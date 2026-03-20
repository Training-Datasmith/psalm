<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Closure_Analyzer;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Falsable_Return_Statement;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Invalid_Return_Statement;
use Psalm\Issue\Less_Specific_Return_Statement;
use Psalm\Issue\Mixed_Return_Statement;
use Psalm\Issue\Mixed_Return_Type_Coercion;
use Psalm\Issue\No_Value;
use Psalm\Issue\Non_Variable_Reference_Return;
use Psalm\Issue\Nullable_Return_Statement;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Union;
use function array_merge;
use function array_unique;
use function count;
use function explode;
use function implode;
use function reset;
use function strtolower;
/**
 * @internal
 */
final class Return_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Return_ $stmt, Context $context): void
    {
        $doc_comment = $stmt->get_doc_comment();
        $var_comments = [];
        $var_comment_type = null;
        $source = $statements_analyzer->get_source();
        $codebase = $statements_analyzer->get_codebase();
        if ($doc_comment && $parsed_docblock = $statements_analyzer->get_parsed_docblock()) {
            $file_storage_provider = $codebase->file_storage_provider;
            $file_storage = $file_storage_provider->get($statements_analyzer->get_file_path());
            try {
                $var_comments = $codebase->config->disable_var_parsing ? [] : Comment_Analyzer::array_to_docblocks($doc_comment, $parsed_docblock, $statements_analyzer->get_source(), $statements_analyzer->get_aliases(), $statements_analyzer->get_template_type_map(), $file_storage->type_aliases);
            } catch (Docblock_Parse_Exception $e) {
                Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($source, $stmt)));
            }
            foreach ($var_comments as $var_comment) {
                if (!$var_comment->type) {
                    continue;
                }
                $comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self, $statements_analyzer->get_parent_fqcln());
                if ($codebase->alter_code && $var_comment->type_start && $var_comment->type_end && $var_comment->line_number) {
                    $type_location = new Docblock_Type_Location($statements_analyzer, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                    $codebase->classlikes->handle_docblock_type_in_migration($codebase, $statements_analyzer, $comment_type, $type_location, $context->calling_method_id);
                }
                if (!$var_comment->var_id) {
                    $var_comment_type = $comment_type;
                    continue;
                }
                if (isset($context->vars_in_scope[$var_comment->var_id])) {
                    $comment_type = $comment_type->set_parent_nodes($context->vars_in_scope[$var_comment->var_id]->parent_nodes);
                }
                $context->vars_in_scope[$var_comment->var_id] = $comment_type;
            }
        }
        if ($stmt->expr) {
            $context->inside_return = true;
            if ($stmt->expr instanceof Php_Parser\Node\Expr\Closure || $stmt->expr instanceof Php_Parser\Node\Expr\Arrow_Function) {
                self::potentially_infer_types_on_closure_from_parent_return_type($statements_analyzer, $stmt->expr, $context);
            }
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                $context->inside_return = false;
                $context->has_returned = true;
                return;
            }
            $stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($var_comment_type) {
                $stmt_type = $var_comment_type;
                if ($stmt_expr_type && $stmt_expr_type->parent_nodes) {
                    $stmt_type = $stmt_type->set_parent_nodes($stmt_expr_type->parent_nodes);
                }
                $statements_analyzer->node_data->set_type($stmt, $var_comment_type);
            } elseif ($stmt_expr_type) {
                $stmt_type = $stmt_expr_type;
                if ($stmt_type->is_never()) {
                    Issue_Buffer::maybe_add(new No_Value('All possible types for this return were invalidated - This may be dead code', new Code_Location($source, $stmt)), $statements_analyzer->get_suppressed_issues());
                    $stmt_type = Type::get_never();
                }
                if ($stmt_type->is_void()) {
                    $stmt_type = Type::get_null();
                }
            } else {
                $stmt_type = Type::get_mixed();
            }
            $context->inside_return = false;
        } else {
            $stmt_type = Type::get_void();
        }
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        if ($context->finally_scope) {
            foreach ($context->vars_in_scope as $var_id => &$type) {
                if (isset($context->finally_scope->vars_in_scope[$var_id])) {
                    $context->finally_scope->vars_in_scope[$var_id] = Type::combine_union_types($context->finally_scope->vars_in_scope[$var_id], $type, $statements_analyzer->get_codebase());
                } else {
                    $type = $type->set_possibly_undefined(true, true);
                    $context->finally_scope->vars_in_scope[$var_id] = $type;
                }
            }
        }
        $context->has_returned = true;
        if ($source instanceof Function_Like_Analyzer && !$source->get_source() instanceof Trait_Analyzer) {
            $source->add_return_types($context);
            $source->examine_param_types($statements_analyzer, $context, $codebase, $stmt);
            $storage = $source->get_function_like_storage($statements_analyzer);
            if ($storage->signature_return_type && $storage->signature_return_type->by_ref && $stmt->expr !== null && !($stmt->expr instanceof Php_Parser\Node\Expr\Variable || $stmt->expr instanceof Php_Parser\Node\Expr\Property_Fetch || $stmt->expr instanceof Php_Parser\Node\Expr\Static_Property_Fetch)) {
                Issue_Buffer::maybe_add(new Non_Variable_Reference_Return('Only variable references should be returned by reference', new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
            }
            $cased_method_id = $source->get_correctly_cased_method_id();
            if ($stmt->expr && $storage->location) {
                $inferred_type = Type_Expander::expand_union($codebase, $stmt_type, $source->get_fqcln(), $source->get_fqcln(), $source->get_parent_fqcln());
                if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                    self::handle_taints($statements_analyzer, $stmt, $cased_method_id, $inferred_type, $storage, $context);
                }
                if ($storage instanceof Method_Storage && $context->self) {
                    $self_class = $context->self;
                    $declared_return_type = $codebase->methods->get_method_return_type(Method_Identifier::wrap($cased_method_id), $self_class, $statements_analyzer);
                    [, $method_name] = explode('::', $cased_method_id);
                    if ($method_name === '__construct') {
                        Issue_Buffer::maybe_add(new Invalid_Return_Statement('No return values are expected for ' . $cased_method_id, new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                        return;
                    }
                } else {
                    $declared_return_type = $storage->return_type;
                }
                if ($declared_return_type && !$declared_return_type->has_mixed()) {
                    $local_return_type = $source->get_local_return_type($declared_return_type, $storage instanceof Method_Storage && $storage->final);
                    if ($storage instanceof Method_Storage) {
                        [$fq_class_name, $method_name] = explode('::', $cased_method_id);
                        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
                        $found_generic_params = Class_Template_Param_Collector::collect($codebase, $class_storage, $class_storage, strtolower($method_name), null, true);
                        if ($found_generic_params) {
                            foreach ($found_generic_params as $template_name => $_) {
                                unset($found_generic_params[$template_name][$fq_class_name]);
                            }
                            $local_return_type = Template_Inferred_Type_Replacer::replace($local_return_type, new Template_Result([], $found_generic_params), $codebase);
                        }
                    }
                    if ($local_return_type->is_generator() && $storage->has_yield) {
                        return;
                    }
                    if ($stmt_type->has_mixed()) {
                        if ($local_return_type->is_void() || $local_return_type->is_never()) {
                            if (Issue_Buffer::accepts(new Invalid_Return_Statement('No return values are expected for ' . $cased_method_id, new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues())) {
                                return;
                            }
                        }
                        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && !$source->get_source() instanceof Trait_Analyzer) {
                            $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                        }
                        if ($stmt_type->is_mixed()) {
                            $origin_locations = [];
                            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                                foreach ($stmt_type->parent_nodes as $parent_node) {
                                    $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                                }
                            }
                            $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                            $return_location = new Code_Location($source, $stmt->expr);
                            if ($origin_location && $origin_location->get_hash() === $return_location->get_hash()) {
                                $origin_location = null;
                            }
                            Issue_Buffer::maybe_add(new Mixed_Return_Statement('Could not infer a return type', $return_location, $origin_location), $statements_analyzer->get_suppressed_issues());
                            return;
                        }
                        Issue_Buffer::maybe_add(new Mixed_Return_Statement('Possibly-mixed return value', new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                    }
                    if ($local_return_type->is_mixed()) {
                        return;
                    }
                    if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && !$source->get_source() instanceof Trait_Analyzer) {
                        $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
                    }
                    if ($local_return_type->is_void()) {
                        Issue_Buffer::maybe_add(new Invalid_Return_Statement('No return values are expected for ' . $cased_method_id, new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                        return;
                    }
                    $union_comparison_results = new Type_Comparison_Result();
                    if (!Union_Type_Comparator::is_contained_by($codebase, $inferred_type, $local_return_type, true, true, $union_comparison_results)) {
                        // is the declared return type more specific than the inferred one?
                        if ($union_comparison_results->type_coerced) {
                            if ($union_comparison_results->type_coerced_from_mixed) {
                                if (!$union_comparison_results->type_coerced_from_as_mixed) {
                                    if ($inferred_type->has_mixed()) {
                                        Issue_Buffer::maybe_add(new Mixed_Return_Statement('Could not infer a return type', new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                                    } else {
                                        Issue_Buffer::maybe_add(new Mixed_Return_Type_Coercion('The type \'' . $stmt_type->get_id() . '\' is more general than the' . ' declared return type \'' . $local_return_type->get_id() . '\'' . ' for ' . $cased_method_id, new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                                    }
                                }
                            } else {
                                Issue_Buffer::maybe_add(new Less_Specific_Return_Statement('The type \'' . $stmt_type->get_id() . '\' is more general than the' . ' declared return type \'' . $local_return_type->get_id() . '\'' . ' for ' . $cased_method_id, new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                            }
                            foreach ($local_return_type->get_atomic_types() as $local_type_part) {
                                if ($local_type_part instanceof T_Class_String && $stmt->expr instanceof Php_Parser\Node\Scalar\String_) {
                                    if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $stmt->expr->value, new Code_Location($source, $stmt->expr), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                                        return;
                                    }
                                } elseif ($local_type_part instanceof T_Array && $stmt->expr instanceof Php_Parser\Node\Expr\Array_) {
                                    $value_param = $local_type_part->type_params[1];
                                    foreach ($value_param->get_atomic_types() as $local_array_type_part) {
                                        if ($local_array_type_part instanceof T_Class_String) {
                                            foreach ($stmt->expr->items as $item) {
                                                if ($item && $item->value instanceof Php_Parser\Node\Scalar\String_) {
                                                    if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $item->value->value, new Code_Location($source, $item->value), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                                                        return;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            Issue_Buffer::maybe_add(new Invalid_Return_Statement('The inferred type \'' . $inferred_type->get_id() . '\' does not match the declared return ' . 'type \'' . $local_return_type->get_id() . '\' for ' . $cased_method_id . ($union_comparison_results->missing_shape_fields ? ' due to additional array shape fields (' . implode(', ', $union_comparison_results->missing_shape_fields) . ')' : ''), new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                    if (!$stmt_type->ignore_nullable_issues && $inferred_type->is_nullable() && !$local_return_type->is_nullable() && !$local_return_type->has_template()) {
                        Issue_Buffer::maybe_add(new Nullable_Return_Statement('The declared return type \'' . $local_return_type->get_id() . '\' for ' . $cased_method_id . ' is not nullable, but the function returns \'' . $inferred_type->get_id() . '\'', new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                    }
                    if (!$stmt_type->ignore_falsable_issues && $inferred_type->is_falsable() && !$local_return_type->is_falsable() && (!$local_return_type->has_bool() || $local_return_type->is_true()) && !$local_return_type->has_scalar()) {
                        Issue_Buffer::maybe_add(new Falsable_Return_Statement('The declared return type \'' . $local_return_type . '\' for ' . $cased_method_id . ' does not allow false, but the function returns \'' . $inferred_type . '\'', new Code_Location($source, $stmt->expr)), $statements_analyzer->get_suppressed_issues());
                    }
                }
            } else if ($storage->signature_return_type && !$storage->signature_return_type->is_void() && !$storage->has_yield) {
                Issue_Buffer::maybe_add(new Invalid_Return_Statement('Empty return statement is not expected in ' . $cased_method_id, new Code_Location($source, $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    private static function handle_taints(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Return_ $stmt, string $cased_method_id, Union $inferred_type, Function_Like_Storage $storage, Context $context): void
    {
        if (!$statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph || !$stmt->expr || !$storage->location) {
            return;
        }
        $method_node = Data_Flow_Node::get_for_method_return(strtolower($cased_method_id), $cased_method_id, $storage->signature_return_type_location ?: $storage->location);
        $statements_analyzer->data_flow_graph->add_node($method_node);
        $codebase = $statements_analyzer->get_codebase();
        $event = new Add_Remove_Taints_Event($stmt->expr, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $storage->added_taints = array_unique(array_merge($storage->added_taints, $added_taints));
        $storage->removed_taints = array_unique(array_merge($storage->removed_taints, $codebase->config->event_dispatcher->dispatch_remove_taints($event)));
        foreach ($inferred_type->parent_nodes as $parent_node) {
            $statements_analyzer->data_flow_graph->add_path($parent_node, $method_node, 'return', $storage->added_taints, $storage->removed_taints);
        }
    }
    /**
     * If a function returns a closure, we try to infer the param/return types of
     * the inner closure.
     *
     * @see \Psalm\Tests\ReturnTypeTest:756
     * @param PhpParser\Node\Expr\Closure|PhpParser\Node\Expr\ArrowFunction $expr
     */
    private static function potentially_infer_types_on_closure_from_parent_return_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Function_Like $expr, Context $context): void
    {
        // if not returning from inside of a function, return
        if (!$context->calling_method_id && !$context->calling_function_id) {
            return;
        }
        $closure_id = (new Closure_Analyzer($expr, $statements_analyzer))->get_closure_id();
        $closure_storage = $statements_analyzer->get_codebase()->get_function_like_storage($statements_analyzer, $closure_id);
        $parent_fn_storage = $statements_analyzer->get_codebase()->get_function_like_storage($statements_analyzer, $context->calling_function_id ?: $context->calling_method_id);
        if ($parent_fn_storage->return_type === null) {
            return;
        }
        // can't infer returned closure if the parent doesn't have a callable return type
        if (!$parent_fn_storage->return_type->has_callable_type()) {
            return;
        }
        // cannot infer if we have union/intersection types
        if (!$parent_fn_storage->return_type->is_single()) {
            return;
        }
        /** @var TClosure|TCallable $parent_callable_return_type */
        $parent_callable_return_type = $parent_fn_storage->return_type->get_single_atomic();
        if ($parent_callable_return_type->params === null && $parent_callable_return_type->return_type === null) {
            return;
        }
        foreach ($closure_storage->params as $key => $param) {
            $parent_param = $parent_callable_return_type->params[$key] ?? null;
            $param->type = self::infer_inner_closure_type_from_parent($statements_analyzer->get_codebase(), $param->type, $parent_param->type ?? null);
        }
        $closure_storage->return_type = self::infer_inner_closure_type_from_parent($statements_analyzer->get_codebase(), $closure_storage->return_type, $parent_callable_return_type->return_type);
    }
    /**
     * - If non parent type, do nothing
     * - If no return type, infer from parent
     * - If parent return type is more specific, infer from parent
     * - else, do nothing
     */
    private static function infer_inner_closure_type_from_parent(Codebase $codebase, ?Union $return_type, ?Union $parent_return_type): ?Union
    {
        if (!$parent_return_type) {
            return $return_type;
        }
        if (!$return_type || Union_Type_Comparator::is_contained_by($codebase, $parent_return_type, $return_type)) {
            return $parent_return_type;
        }
        return $return_type;
    }
}