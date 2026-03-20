<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Call_Return_Type_Fetcher;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Visibility_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Abstract_Instantiation;
use Psalm\Issue\Deprecated_Class;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Interface_Instantiation;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Internal_Method;
use Psalm\Issue\Invalid_String_Class;
use Psalm\Issue\Mixed_Method_Call;
use Psalm\Issue\ParseError;
use Psalm\Issue\Too_Many_Arguments;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Unsafe_Generic_Instantiation;
use Psalm\Issue\Unsafe_Instantiation;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic\T_Anonymous_Class_Instance;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Dependent_Get_Class;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Unknown_Class_String;
use Psalm\Type\Taint_Kind;
use Psalm\Type\Union;
use function array_diff;
use function array_map;
use function array_values;
use function count;
use function in_array;
use function md5;
use function preg_match;
use function reset;
use function strtolower;
/**
 * @internal
 */
final class New_Analyzer extends Call_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\New_ $stmt, Context $context, ?Template_Result $template_result = null): bool
    {
        $fq_class_name = null;
        $codebase = $statements_analyzer->get_codebase();
        $config = $codebase->config;
        $can_extend = false;
        $from_static = false;
        if ($stmt->is_first_class_callable()) {
            Issue_Buffer::maybe_add(new ParseError('First-class callables cannot be used in new', new Code_Location($statements_analyzer->get_source(), $stmt)));
            return false;
        }
        if ($stmt->class instanceof Php_Parser\Node\Name) {
            if (!in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
                $aliases = $statements_analyzer->get_aliases();
                if ($context->calling_method_id && !$stmt->class instanceof Php_Parser\Node\Name\Fully_Qualified) {
                    $codebase->file_reference_provider->add_method_reference_to_class_member($context->calling_method_id, 'use:' . $stmt->class->get_first() . ':' . md5($statements_analyzer->get_file_path()), false);
                }
                $fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $aliases);
                $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
            } elseif ($context->self !== null) {
                switch ($stmt->class->get_first()) {
                    case 'self':
                        $class_storage = $codebase->classlike_storage_provider->get($context->self);
                        $fq_class_name = $class_storage->name;
                        break;
                    case 'parent':
                        $fq_class_name = $context->parent;
                        break;
                    case 'static':
                        // @todo maybe we can do better here
                        $class_storage = $codebase->classlike_storage_provider->get($context->self);
                        $fq_class_name = $class_storage->name;
                        if (!$class_storage->final) {
                            $can_extend = true;
                            $from_static = true;
                        }
                        break;
                }
            }
            if ($codebase->store_node_types && $fq_class_name && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $codebase->classlikes->class_exists($fq_class_name) ? $fq_class_name : '*' . ($stmt->class instanceof Php_Parser\Node\Name\Fully_Qualified ? '\\' : $statements_analyzer->get_namespace() . '-') . $stmt->class->to_string());
            }
        } elseif ($stmt->class instanceof Php_Parser\Node\Stmt\Class_) {
            $statements_analyzer->analyze([$stmt->class], $context);
            $fq_class_name = Class_Analyzer::get_anonymous_class_name($stmt->class, $statements_analyzer->get_aliases(), $statements_analyzer->get_file_path());
        } else {
            self::analyze_constructor_expression($statements_analyzer, $codebase, $context, $stmt, $stmt->class, $config, $fq_class_name, $can_extend);
        }
        if ($fq_class_name) {
            if ($codebase->alter_code && $stmt->class instanceof Php_Parser\Node\Name && !in_array($stmt->class->get_first(), ['parent', 'static'])) {
                $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id);
            }
            if ($context->check_classes) {
                if ($context->is_phantom_class($fq_class_name)) {
                    Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
                    return true;
                }
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer->get_source(), $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues()) === false) {
                    Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
                    return true;
                }
                if ($codebase->interface_exists($fq_class_name)) {
                    Issue_Buffer::maybe_add(new Interface_Instantiation('Interface ' . $fq_class_name . ' cannot be instantiated', new Code_Location($statements_analyzer->get_source(), $stmt->class)), $statements_analyzer->get_suppressed_issues());
                    return true;
                }
            }
            if ($stmt->class instanceof Php_Parser\Node\Stmt\Class_) {
                $extends = $stmt->class->extends ? (string) $stmt->class->extends : null;
                $result_atomic_type = new T_Anonymous_Class_Instance($fq_class_name, false, $extends);
            } else {
                //if the class is a Name, it can't represent a child
                $definite_class = $stmt->class instanceof Php_Parser\Node\Name;
                $result_atomic_type = new T_Named_Object($fq_class_name, $from_static, $definite_class);
            }
            $statements_analyzer->node_data->set_type($stmt, new Union([$result_atomic_type]));
            if (strtolower($fq_class_name) === 'stdclass' && $stmt->get_args() !== []) {
                Issue_Buffer::maybe_add(new Too_Many_Arguments('stdClass::__construct() has no parameters', new Code_Location($statements_analyzer->get_source(), $stmt), 'stdClass::__construct'), $statements_analyzer->get_suppressed_issues());
            }
            if (strtolower($fq_class_name) !== 'stdclass' && $codebase->classlikes->class_exists($fq_class_name)) {
                self::analyze_named_constructor($statements_analyzer, $codebase, $stmt, $context, $fq_class_name, $from_static, $can_extend, $template_result);
            } else {
                Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
                if ($codebase->classlikes->enum_exists($fq_class_name)) {
                    Issue_Buffer::maybe_add(new Undefined_Class('Enums cannot be instantiated', new Code_Location($statements_analyzer, $stmt), $fq_class_name));
                }
            }
        }
        if (!$config->remember_property_assignments_after_call && !$context->collect_initializations) {
            $context->remove_mutable_object_vars();
        }
        return true;
    }
    private static function analyze_named_constructor(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\New_ $stmt, Context $context, string $fq_class_name, bool $from_static, bool $can_extend, ?Template_Result $template_result = null): void
    {
        $storage = $codebase->classlike_storage_provider->get($fq_class_name);
        if ($from_static) {
            if (!$storage->preserve_constructor_signature) {
                Issue_Buffer::maybe_add(new Unsafe_Instantiation('Cannot safely instantiate class ' . $fq_class_name . ' with "new static" as' . ' its constructor might change in child classes', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($storage->template_types && !$storage->enforce_template_inheritance) {
                $source = $statements_analyzer->get_source();
                if ($source instanceof Function_Like_Analyzer) {
                    $function_storage = $source->get_function_like_storage($statements_analyzer);
                    if ($function_storage->return_type && preg_match('/\bstatic\b/', $function_storage->return_type->get_id())) {
                        Issue_Buffer::maybe_add(new Unsafe_Generic_Instantiation('Cannot safely instantiate generic class ' . $fq_class_name . ' with "new static" as' . ' its generic parameters may be constrained in child classes.', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                }
            }
        }
        // if we're not calling this constructor via new static()
        if ($storage->abstract && !$can_extend) {
            if (Issue_Buffer::accepts(new Abstract_Instantiation('Unable to instantiate an abstract class ' . $fq_class_name, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues())) {
                return;
            }
        }
        if ($storage->deprecated && strtolower($fq_class_name) !== strtolower((string) $context->self)) {
            Issue_Buffer::maybe_add(new Deprecated_Class($fq_class_name . ' is marked deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
        }
        if ($context->self && !$context->collect_initializations && !$context->collect_mutations && !Namespace_Analyzer::is_within_any($context->self, $storage->internal)) {
            Issue_Buffer::maybe_add(new Internal_Class($fq_class_name . ' is internal to ' . Internal_Class::list_to_phrase($storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
        }
        $method_id = new Method_Identifier($fq_class_name, '__construct');
        if ($codebase->methods->method_exists($method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null, $statements_analyzer, $statements_analyzer->get_file_path())) {
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                Argument_Map_Populator::record_argument_positions($statements_analyzer, $stmt, $codebase, (string) $method_id);
            }
            $template_result ??= new Template_Result([], []);
            if (self::check_method_args($method_id, $stmt->get_args(), $template_result, $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer) === false) {
                return;
            }
            if (Method_Visibility_Analyzer::analyze($method_id, $context, $statements_analyzer->get_source(), new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
                return;
            }
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            $method_storage = null;
            if ($declaring_method_id) {
                $method_storage = $codebase->methods->get_storage($declaring_method_id);
                $caller_identifier = $statements_analyzer->get_fully_qualified_function_method_or_namespace_name() ?: '';
                if (!Namespace_Analyzer::is_within_any($caller_identifier, $method_storage->internal)) {
                    Issue_Buffer::maybe_add(new Internal_Method('Constructor ' . $codebase->methods->get_cased_method_id($declaring_method_id) . ' is internal to ' . Internal_Class::list_to_phrase($method_storage->internal) . ' but called from ' . ($caller_identifier ?: 'root namespace'), new Code_Location($statements_analyzer, $stmt), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                }
                if (!$method_storage->external_mutation_free && !$context->inside_throw) {
                    if ($context->pure) {
                        Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call an impure constructor from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                    } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                        $statements_analyzer->get_source()->inferred_has_mutation = true;
                        $statements_analyzer->get_source()->inferred_impure = true;
                    }
                }
                if ($method_storage->assertions && $stmt->class instanceof Php_Parser\Node\Name) {
                    self::apply_assertions_to_context($stmt->class, null, $method_storage->assertions, $stmt->get_args(), $template_result, $context, $statements_analyzer);
                }
                if ($method_storage->if_true_assertions) {
                    $statements_analyzer->node_data->set_if_true_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $method_storage->if_true_assertions));
                }
                if ($method_storage->if_false_assertions) {
                    $statements_analyzer->node_data->set_if_false_assertions($stmt, array_map(static fn(Possibilities $assertion): Possibilities => $assertion->get_untemplated_copy($template_result, null, $codebase), $method_storage->if_false_assertions));
                }
            }
            $generic_param_types = null;
            $self_out_candidate = null;
            if ($storage->template_types) {
                foreach ($storage->template_types as $template_name => $base_type) {
                    if (isset($template_result->lower_bounds[$template_name][$fq_class_name])) {
                        $generic_param_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($template_result->lower_bounds[$template_name][$fq_class_name], $codebase);
                    } elseif ($storage->template_extended_params && $template_result->lower_bounds) {
                        $generic_param_type = self::get_generic_param_for_offset($fq_class_name, $template_name, $storage->template_extended_params, array_map(static fn(array $type_map): array => array_map(static fn(array $bounds): Union => Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($bounds, $codebase), $type_map), $template_result->lower_bounds));
                    } else if ($fq_class_name === 'SplObjectStorage') {
                        $generic_param_type = Type::get_never();
                    } else {
                        $generic_param_type = array_values($base_type)[0];
                    }
                    $generic_param_types[] = $generic_param_type->set_properties(['had_template' => true]);
                }
                if ($method_storage && $method_storage->self_out_type) {
                    $self_out_candidate = $method_storage->self_out_type;
                    if ($template_result->lower_bounds) {
                        $self_out_candidate = Type_Expander::expand_union($codebase, $self_out_candidate, $fq_class_name, null, $storage->parent_class, true, false, false, true);
                    }
                    $self_out_candidate = Method_Call_Return_Type_Fetcher::replace_template_types($self_out_candidate, $template_result, $method_id, count($stmt->get_args()), $codebase);
                    $self_out_candidate = Type_Expander::expand_union($codebase, $self_out_candidate, $fq_class_name, $fq_class_name, $storage->parent_class, true, false, false, true);
                    $statements_analyzer->node_data->set_type($stmt, $self_out_candidate);
                }
            }
            // XXX: what if we need both?
            if ($generic_param_types && !$self_out_candidate) {
                $result_atomic_type = new T_Generic_Object($fq_class_name, $generic_param_types, false, $from_static);
                $statements_analyzer->node_data->set_type($stmt, new Union([$result_atomic_type]));
            }
        } elseif ($stmt->get_args()) {
            Issue_Buffer::maybe_add(new Too_Many_Arguments('Class ' . $fq_class_name . ' has no __construct, but arguments were passed', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name . '::__construct'), $statements_analyzer->get_suppressed_issues());
        } elseif ($storage->template_types) {
            $result_atomic_type = new T_Generic_Object($fq_class_name, array_values(array_map(reset(...), $storage->template_types)), false, $from_static);
            $statements_analyzer->node_data->set_type($stmt, new Union([$result_atomic_type]));
        }
        if ($storage->external_mutation_free) {
            $stmt->set_attribute('external_mutation_free', true);
            $stmt_type = $statements_analyzer->node_data->get_type($stmt);
            if ($stmt_type) {
                $stmt_type = $stmt_type->set_properties(['reference_free' => true]);
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            }
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues()) && $stmt_type = $statements_analyzer->node_data->get_type($stmt)) {
            $code_location = new Code_Location($statements_analyzer->get_source(), $stmt);
            $method_storage = null;
            $declaring_method_id = $codebase->methods->get_declaring_method_id($method_id);
            if ($declaring_method_id) {
                $method_storage = $codebase->methods->get_storage($declaring_method_id);
            }
            if ($storage->external_mutation_free || $method_storage && $method_storage->specialize_call) {
                $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $fq_class_name . '::__construct', $storage->location, $code_location);
            } else {
                $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $fq_class_name . '::__construct', $storage->location);
            }
            $statements_analyzer->data_flow_graph->add_node($method_source);
            $stmt_type = $stmt_type->set_parent_nodes([$method_source->id => $method_source]);
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        }
    }
    private static function analyze_constructor_expression(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, Php_Parser\Node\Expr\New_ $stmt, Php_Parser\Node\Expr $stmt_class, Config $config, ?string &$fq_class_name, bool &$can_extend): void
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        Expression_Analyzer::analyze($statements_analyzer, $stmt_class, $context);
        $context->inside_general_use = $was_inside_general_use;
        $stmt_class_type = $statements_analyzer->node_data->get_type($stmt_class);
        if (!$stmt_class_type) {
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
            return;
        }
        $has_single_class = $stmt_class_type->is_single_string_literal();
        if ($has_single_class) {
            $fq_class_name = $stmt_class_type->get_single_string_literal()->value;
        } else {
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $stmt_class_type->parent_nodes && !in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                $arg_location = new Code_Location($statements_analyzer->get_source(), $stmt_class);
                $custom_call_sink = Taint_Sink::get_for_method_argument('variable-call', 'variable-call', 0, $arg_location, $arg_location);
                $custom_call_sink->taints = [Taint_Kind::INPUT_CALLABLE];
                $statements_analyzer->data_flow_graph->add_sink($custom_call_sink);
                $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
                $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                $taints = array_diff($added_taints, $removed_taints);
                if ($added_taints !== []) {
                    $taint_source = Taint_Source::from_node($custom_call_sink);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
                foreach ($stmt_class_type->parent_nodes as $parent_node) {
                    $statements_analyzer->data_flow_graph->add_path($parent_node, $custom_call_sink, 'call', $added_taints, $removed_taints);
                }
            }
            if (self::check_method_args(null, $stmt->get_args(), new Template_Result([], []), $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer) === false) {
                return;
            }
        }
        $new_type = self::get_new_type($statements_analyzer, $codebase, $context, $stmt, $stmt_class_type, $config, $can_extend);
        if (!$has_single_class) {
            if ($new_type) {
                $statements_analyzer->node_data->set_type($stmt, $new_type);
            }
            Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context);
            return;
        }
    }
    private static function get_new_type(Statements_Analyzer $statements_analyzer, Codebase $codebase, Context $context, Php_Parser\Node\Expr\New_ $stmt, Union $stmt_class_type, Config $config, bool &$can_extend): ?Union
    {
        $new_types = [];
        foreach ($stmt_class_type->get_atomic_types() as $lhs_type_part) {
            if ($lhs_type_part instanceof T_Template_Param) {
                $as = self::get_new_type($statements_analyzer, $codebase, $context, $stmt, $lhs_type_part->as, $config, $can_extend);
                if ($as) {
                    $new_types[] = new Union([$lhs_type_part->replace_as($as)]);
                }
                continue;
            }
            if ($lhs_type_part instanceof T_Template_Param_Class) {
                if (!$statements_analyzer->node_data->get_type($stmt)) {
                    $new_type_part = new T_Template_Param($lhs_type_part->param_name, $lhs_type_part->as_type ? new Union([$lhs_type_part->as_type]) : Type::get_object(), $lhs_type_part->defining_class);
                    if (!$lhs_type_part->as_type) {
                        Issue_Buffer::maybe_add(new Mixed_Method_Call('Cannot call constructor on an unknown class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                    $new_types[] = new Union([$new_type_part]);
                    if ($lhs_type_part->as_type && $codebase->classlikes->class_exists($lhs_type_part->as_type->value)) {
                        $as_storage = $codebase->classlike_storage_provider->get($lhs_type_part->as_type->value);
                        if (!$as_storage->preserve_constructor_signature) {
                            Issue_Buffer::maybe_add(new Unsafe_Instantiation('Cannot safely instantiate class ' . $lhs_type_part->as_type->value . ' with "new $class_name" as' . ' its constructor might change in child classes', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                        }
                    }
                }
                if ($lhs_type_part->as_type) {
                    $codebase->methods->method_exists(new Method_Identifier($lhs_type_part->as_type->value, '__construct'), $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null, $statements_analyzer, $statements_analyzer->get_file_path());
                }
                continue;
            }
            if ($lhs_type_part instanceof T_Literal_Class_String || $lhs_type_part instanceof T_Class_String || $lhs_type_part instanceof T_Dependent_Get_Class) {
                if (!$statements_analyzer->node_data->get_type($stmt)) {
                    if ($lhs_type_part instanceof T_Class_String) {
                        $generated_type = $lhs_type_part->as_type ?? new T_Object();
                        if ($lhs_type_part instanceof T_Unknown_Class_String) {
                            $generated_type = $lhs_type_part->as_unknown_type ?? $generated_type;
                        }
                        if ($lhs_type_part->as_type && $codebase->classlikes->class_exists($lhs_type_part->as_type->value)) {
                            $as_storage = $codebase->classlike_storage_provider->get($lhs_type_part->as_type->value);
                            if (!$as_storage->preserve_constructor_signature) {
                                Issue_Buffer::maybe_add(new Unsafe_Instantiation('Cannot safely instantiate class ' . $lhs_type_part->as_type->value . ' with "new $class_name" as' . ' its constructor might change in child classes', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                            }
                        }
                    } elseif ($lhs_type_part instanceof T_Dependent_Get_Class) {
                        $generated_type = new T_Object();
                        if ($lhs_type_part->as_type->has_object_type() && $lhs_type_part->as_type->is_single()) {
                            foreach ($lhs_type_part->as_type->get_atomic_types() as $typeof_type_atomic) {
                                if ($typeof_type_atomic instanceof T_Named_Object) {
                                    $generated_type = new T_Named_Object($typeof_type_atomic->value);
                                }
                            }
                        }
                    } else {
                        $generated_type = new T_Named_Object($lhs_type_part->value);
                    }
                    if ($lhs_type_part instanceof T_Class_String) {
                        $can_extend = true;
                    }
                    if ($generated_type instanceof T_Object) {
                        Issue_Buffer::maybe_add(new Mixed_Method_Call('Cannot call constructor on an unknown class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                    $new_types[] = new Union([$generated_type]);
                }
                continue;
            }
            if ($lhs_type_part instanceof T_String) {
                if (!$config->allow_string_standin_for_class || $lhs_type_part instanceof T_Numeric_String) {
                    Issue_Buffer::maybe_add(new Invalid_String_Class('String cannot be used as a class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } elseif ($lhs_type_part instanceof T_Mixed || $lhs_type_part instanceof T_Object) {
                Issue_Buffer::maybe_add(new Mixed_Method_Call('Cannot call constructor on an unknown class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($lhs_type_part instanceof T_False && $stmt_class_type->ignore_falsable_issues) {
                // do nothing
            } elseif ($lhs_type_part instanceof T_Null && $stmt_class_type->ignore_nullable_issues) {
                // do nothing
            } elseif ($lhs_type_part instanceof T_Named_Object) {
                $new_types[] = new Union([$lhs_type_part]);
                continue;
            } else {
                Issue_Buffer::maybe_add(new Undefined_Class('Type ' . $lhs_type_part . ' cannot be called as a class', new Code_Location($statements_analyzer->get_source(), $stmt), (string) $lhs_type_part), $statements_analyzer->get_suppressed_issues());
            }
            $new_types[] = Type::get_object();
        }
        if ($new_types) {
            return Type::combine_union_type_array($new_types, $codebase);
        }
        return null;
    }
}