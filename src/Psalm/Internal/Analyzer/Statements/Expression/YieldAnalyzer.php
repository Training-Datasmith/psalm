<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Context;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Unnecessary_Var_Annotation;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use function array_values;
/**
 * @internal
 */
final class Yield_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Yield_ $stmt, Context $context): bool
    {
        $doc_comment = $stmt->get_doc_comment();
        $var_comments = [];
        $var_comment_type = null;
        $codebase = $statements_analyzer->get_codebase();
        if ($doc_comment) {
            try {
                $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $statements_analyzer, $statements_analyzer->get_aliases());
            } catch (Docblock_Parse_Exception $e) {
                Issue_Buffer::maybe_add(new Invalid_Docblock($e->get_message(), new Code_Location($statements_analyzer->get_source(), $stmt)));
            }
            foreach ($var_comments as $var_comment) {
                if (!$var_comment->type) {
                    continue;
                }
                $comment_type = Type_Expander::expand_union($codebase, $var_comment->type, $context->self, $context->self ? new T_Named_Object($context->self) : null, $statements_analyzer->get_parent_fqcln());
                $type_location = null;
                if ($var_comment->type_start && $var_comment->type_end && $var_comment->line_number) {
                    $type_location = new Docblock_Type_Location($statements_analyzer, $var_comment->type_start, $var_comment->type_end, $var_comment->line_number);
                }
                if (!$var_comment->var_id) {
                    $var_comment_type = $comment_type;
                    continue;
                }
                if ($codebase->find_unused_variables && $type_location && isset($context->vars_in_scope[$var_comment->var_id]) && $context->vars_in_scope[$var_comment->var_id]->get_id() === $comment_type->get_id()) {
                    $project_analyzer = $statements_analyzer->get_project_analyzer();
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['UnnecessaryVarAnnotation'])) {
                        File_Manipulation_Buffer::add_var_annotation_to_remove($type_location);
                    } else {
                        Issue_Buffer::maybe_add(new Unnecessary_Var_Annotation('The @var annotation for ' . $var_comment->var_id . ' is unnecessary', $type_location), $statements_analyzer->get_suppressed_issues(), true);
                    }
                }
                if (isset($context->vars_in_scope[$var_comment->var_id])) {
                    $comment_type = $comment_type->set_parent_nodes($context->vars_in_scope[$var_comment->var_id]->parent_nodes);
                }
                $context->vars_in_scope[$var_comment->var_id] = $comment_type;
            }
        }
        if ($stmt->key) {
            $context->inside_call = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->key, $context) === false) {
                return false;
            }
            $context->inside_call = false;
        }
        if ($stmt->value) {
            $context->inside_call = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->value, $context) === false) {
                return false;
            }
            $context->inside_call = false;
            if ($var_comment_type) {
                $expression_type = $var_comment_type;
            } elseif ($stmt_var_type = $statements_analyzer->node_data->get_type($stmt->value)) {
                $expression_type = $stmt_var_type;
            } else {
                $expression_type = Type::get_mixed();
            }
        } else {
            $expression_type = Type::get_never();
        }
        $yield_type = null;
        foreach ($expression_type->get_atomic_types() as $expression_atomic_type) {
            if (!$expression_atomic_type instanceof T_Named_Object) {
                continue;
            }
            if (!$codebase->classlikes->class_or_interface_exists($expression_atomic_type->value)) {
                continue;
            }
            $classlike_storage = $codebase->classlike_storage_provider->get($expression_atomic_type->value);
            if (!$classlike_storage->yield) {
                continue;
            }
            $declaring_classlike_storage = $classlike_storage->declaring_yield_fqcn ? $codebase->classlike_storage_provider->get($classlike_storage->declaring_yield_fqcn) : $classlike_storage;
            $yield_candidate_type = $classlike_storage->yield;
            $yield_candidate_type = !$yield_candidate_type->is_mixed() ? Type_Expander::expand_union($codebase, $yield_candidate_type, $expression_atomic_type->value, $expression_atomic_type->value, null, true, false) : $yield_candidate_type;
            $class_template_params = Class_Template_Param_Collector::collect($codebase, $declaring_classlike_storage, $classlike_storage, null, $expression_atomic_type, true);
            if ($class_template_params) {
                if (!$expression_atomic_type instanceof T_Generic_Object) {
                    $type_params = [];
                    foreach ($class_template_params as $type_map) {
                        $type_params[] = array_values($type_map)[0];
                    }
                    $expression_atomic_type = new T_Generic_Object($expression_atomic_type->value, $type_params);
                }
                $yield_candidate_type = Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, $yield_candidate_type, $expression_atomic_type, $classlike_storage, $declaring_classlike_storage);
            }
            $yield_type = Type::combine_union_types($yield_type, $yield_candidate_type, $codebase);
        }
        if ($yield_type) {
            $expression_type = $expression_type->get_builder()->substitute($expression_type, $yield_type)->freeze();
        }
        $statements_analyzer->node_data->set_type($stmt, $expression_type);
        $source = $statements_analyzer->get_source();
        if ($source instanceof Function_Like_Analyzer && !$source->get_source() instanceof Trait_Analyzer) {
            $source->examine_param_types($statements_analyzer, $context, $codebase, $stmt);
            $storage = $source->get_function_like_storage($statements_analyzer);
            if ($storage->return_type && !$yield_type) {
                foreach ($storage->return_type->get_atomic_types() as $atomic_return_type) {
                    if ($atomic_return_type instanceof T_Named_Object && $atomic_return_type->value === 'Generator') {
                        if ($atomic_return_type instanceof T_Generic_Object) {
                            if (!$atomic_return_type->type_params[2]->is_void()) {
                                $statements_analyzer->node_data->set_type($stmt, $atomic_return_type->type_params[2]);
                            }
                        } else {
                            $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types(Type::get_mixed(), $expression_type));
                        }
                    }
                }
            }
        }
        return true;
    }
}