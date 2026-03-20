<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Function_Like;

use Php_Parser;
use Php_Parser\Node\Expr\Arrow_Function;
use Php_Parser\Node\Expr\Closure;
use Php_Parser\Node\Function_Like;
use Php_Parser\Node\Stmt\Class_Method;
use Php_Parser\Node\Stmt\Function_;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\Unresolvable_Constant_Exception;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Interface_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Source_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\File_Manipulation\Function_Docblock_Manipulator;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Implicit_To_String_Cast;
use Psalm\Issue\Invalid_Falsable_Return_Type;
use Psalm\Issue\Invalid_Nullable_Return_Type;
use Psalm\Issue\Invalid_Parent;
use Psalm\Issue\Invalid_Return_Type;
use Psalm\Issue\Invalid_To_String;
use Psalm\Issue\Less_Specific_Return_Type;
use Psalm\Issue\Mismatching_Docblock_Return_Type;
use Psalm\Issue\Missing_Closure_Return_Type;
use Psalm\Issue\Missing_Return_Type;
use Psalm\Issue\Mixed_Return_Type_Coercion;
use Psalm\Issue\More_Specific_Return_Type;
use Psalm\Issue\Unresolvable_Constant;
use Psalm\Issue_Buffer;
use Psalm\Statements_Source;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use function array_diff;
use function array_filter;
use function array_values;
use function count;
use function implode;
use function in_array;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
final class Return_Type_Analyzer
{
    /**
     * @param Closure|Function_|ClassMethod|ArrowFunction $function
     * @param PhpParser\Node\Stmt[] $function_stmts
     * @param string[]            $compatible_method_ids
     * @return  false|null
     * @psalm-suppress PossiblyUnusedReturnValue unused but seems important
     * @psalm-suppress ComplexMethod Unavoidably complex method
     */
    public static function verify_return_type(Function_Like $function, array $function_stmts, Source_Analyzer $source, Node_Data_Provider $type_provider, Function_Like_Analyzer $function_like_analyzer, ?Union $return_type = null, ?string $fq_class_name = null, ?string $static_fq_class_name = null, ?Code_Location $return_type_location = null, array $compatible_method_ids = [], bool $did_explicitly_return = false, bool $closure_inside_call = false): ?bool
    {
        $suppressed_issues = $function_like_analyzer->get_suppressed_issues();
        $codebase = $source->get_codebase();
        $project_analyzer = $source->get_project_analyzer();
        $function_like_storage = null;
        if ($source instanceof Statements_Analyzer) {
            $function_like_storage = $function_like_analyzer->get_function_like_storage($source);
        } elseif ($source instanceof Class_Analyzer || $source instanceof Trait_Analyzer) {
            $function_like_storage = $function_like_analyzer->get_function_like_storage();
        }
        $cased_method_id = $function_like_analyzer->get_correctly_cased_method_id();
        if (!$function->get_stmts() && ($function instanceof Class_Method && ($source instanceof Interface_Analyzer || $function->is_abstract()))) {
            if (!$return_type) {
                Issue_Buffer::maybe_add(new Missing_Return_Type('Method ' . $cased_method_id . ' does not have a return type', new Code_Location($function_like_analyzer, $function->name, null, true)), $suppressed_issues);
            }
            return null;
        }
        $is_to_string = $function instanceof Class_Method && strtolower($function->name->name) === '__tostring';
        if ($function instanceof Class_Method && str_starts_with($function->name->name, '__') && !$is_to_string && !$return_type) {
            // do not check __construct, __set, __get, __call etc.
            return null;
        }
        if (!$return_type_location) {
            $return_type_location = new Code_Location($function_like_analyzer, $function instanceof Closure || $function instanceof Arrow_Function ? $function : $function->name);
        }
        $inferred_yield_types = [];
        $inferred_return_type_parts = Return_Type_Collector::get_return_types($codebase, $type_provider, $function_stmts, $inferred_yield_types, true);
        if (!$inferred_return_type_parts) {
            $did_explicitly_return = true;
        }
        if ((!$return_type || $return_type->from_docblock) && Scope_Analyzer::get_control_actions($function_stmts, $type_provider, []) !== [Scope_Analyzer::ACTION_END] && !$inferred_yield_types && count($inferred_return_type_parts) && !$did_explicitly_return) {
            // only add null if we have a return statement elsewhere and it wasn't void or never
            foreach ($inferred_return_type_parts as $inferred_return_type_part) {
                if (!$inferred_return_type_part->is_void() && !$inferred_return_type_part->is_never()) {
                    $atomic_null = new T_Null(true);
                    $inferred_return_type_parts[] = new Union([$atomic_null]);
                    break;
                }
            }
        }
        $control_actions = Scope_Analyzer::get_control_actions($function_stmts, $type_provider, [], false);
        $function_always_exits = $control_actions === [Scope_Analyzer::ACTION_END];
        $function_returns_implicitly = (bool) array_diff($control_actions, [Scope_Analyzer::ACTION_END, Scope_Analyzer::ACTION_RETURN]);
        /** @psalm-suppress PossiblyUndefinedStringArrayOffset */
        if ($return_type && (!$return_type->from_docblock || $return_type->is_nullable() && !$return_type->has_template() && !$return_type->get_atomic_types()['null']->from_docblock) && !$return_type->is_void() && !$return_type->is_never() && !$inferred_yield_types && (!$function_like_storage || !$function_like_storage->has_yield) && $function_returns_implicitly) {
            if (Issue_Buffer::accepts(new Invalid_Return_Type('Not all code paths of ' . $cased_method_id . ' end in a return statement, return type ' . $return_type . ' expected', $return_type_location), $suppressed_issues)) {
                return false;
            }
            return null;
        }
        if ($return_type && $return_type->is_never() && !$inferred_yield_types && !$function_always_exits) {
            if (Issue_Buffer::accepts(new Invalid_Return_Type($cased_method_id . ' is not expected to return, but it does, ' . 'either implicitly or explicitly', $return_type_location), $suppressed_issues)) {
                return false;
            }
            return null;
        }
        // only now after non-implicit things are checked
        if ($function_returns_implicitly) {
            $inferred_return_type_parts[] = Type::get_void();
        }
        $inferred_return_type_parts_with_never = $inferred_return_type_parts;
        // we filter TNever that have no bearing on the return type
        if (count($inferred_return_type_parts) > 1) {
            $inferred_return_type_parts = array_filter($inferred_return_type_parts, static fn(Union $union_type): bool => !$union_type->is_never());
        }
        $inferred_return_type_parts = array_values($inferred_return_type_parts);
        $inferred_return_type = $inferred_return_type_parts ? Type::combine_union_type_array($inferred_return_type_parts, $codebase) : Type::get_void();
        if ($function_always_exits) {
            $inferred_return_type = Type::get_never();
        }
        // void + never = null, so we need to check this separately
        if (count($inferred_return_type_parts_with_never) > 1 && !$function_always_exits && $inferred_return_type_parts_with_never !== $inferred_return_type_parts) {
            /**
             * see https://github.com/vimeo/psalm/issues/9045
             *
             * @psalm-suppress InvalidArgument
             */
            $inferred_return_type_with_never = Type::combine_union_type_array($inferred_return_type_parts_with_never, $codebase);
        } else {
            $inferred_return_type_with_never = $inferred_return_type;
        }
        $inferred_yield_type = $inferred_yield_types ? Type::combine_union_type_array($inferred_yield_types, $codebase) : null;
        if ($inferred_yield_type) {
            $inferred_return_type = $inferred_yield_type;
        }
        $unsafe_return_type = false;
        // prevent any return types that do not return a value from being used in PHP typehints
        if ($codebase->alter_code && $inferred_return_type->is_nullable() && !$inferred_yield_types) {
            foreach ($inferred_return_type_parts as $inferred_return_type_part) {
                if ($inferred_return_type_part->is_void()) {
                    $unsafe_return_type = true;
                    break;
                }
            }
        }
        $inferred_return_type = Type_Expander::expand_union($codebase, $inferred_return_type, $source->get_fqcln(), $source->get_fqcln(), $source->get_parent_fqcln());
        if ($is_to_string) {
            $union_comparison_results = new Type_Comparison_Result();
            if (!$inferred_return_type->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $inferred_return_type, Type::get_string(), $inferred_return_type->ignore_nullable_issues, $inferred_return_type->ignore_falsable_issues, $union_comparison_results)) {
                if (Issue_Buffer::accepts(new Invalid_To_String('__toString methods must return a string, ' . $inferred_return_type . ' returned', $return_type_location), $suppressed_issues)) {
                    return false;
                }
            }
            if ($union_comparison_results->to_string_cast) {
                Issue_Buffer::maybe_add(new Implicit_To_String_Cast('The declared return type for ' . $cased_method_id . ' expects string, ' . '\'' . $inferred_return_type . '\' provided with a __toString method', $return_type_location), $suppressed_issues);
            }
            return null;
        }
        if (!$return_type) {
            if ($function instanceof Closure || $function instanceof Arrow_Function) {
                if (!$closure_inside_call || $inferred_return_type->is_mixed()) {
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingClosureReturnType']) && !in_array('MissingClosureReturnType', $suppressed_issues)) {
                        if ($inferred_return_type->has_mixed() || $inferred_return_type->is_null()) {
                            return null;
                        }
                        self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                        return null;
                    }
                    Issue_Buffer::maybe_add(new Missing_Closure_Return_Type('Closure does not have a return type, expecting ' . $inferred_return_type->get_id(), new Code_Location($function_like_analyzer, $function, null, true)), $suppressed_issues, !$inferred_return_type->has_mixed() && !$inferred_return_type->is_null());
                }
                return null;
            }
            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingReturnType']) && !in_array('MissingReturnType', $suppressed_issues)) {
                if ($inferred_return_type->has_mixed() || $inferred_return_type->is_null()) {
                    return null;
                }
                self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, $compatible_method_ids || !$did_explicitly_return || ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                return null;
            }
            Issue_Buffer::maybe_add(new Missing_Return_Type('Method ' . $cased_method_id . ' does not have a return type' . (!$inferred_return_type->has_mixed() ? ', expecting ' . $inferred_return_type->get_id() : ''), new Code_Location($function_like_analyzer, $function->name, null, true)), $suppressed_issues, !$inferred_return_type->has_mixed() && !$inferred_return_type->is_null());
            return null;
        }
        $self_fq_class_name = $fq_class_name ?: $source->get_fqcln();
        $parent_class = null;
        $classlike_storage = null;
        if ($self_fq_class_name) {
            $classlike_storage = $codebase->classlike_storage_provider->get($self_fq_class_name);
            $parent_class = $classlike_storage->parent_class;
        }
        // passing it through fleshOutTypes eradicates errant $ vars
        $declared_return_type = Type_Expander::expand_union($codebase, $return_type, $self_fq_class_name, $static_fq_class_name, $parent_class, true, true, $function_like_storage instanceof Method_Storage && $function_like_storage->final || $classlike_storage && $classlike_storage->final);
        if ((!$inferred_return_type_parts || $inferred_return_type->is_void() && $function_returns_implicitly && count($inferred_return_type_parts) === 1) && !$inferred_return_type->is_never() && !$inferred_yield_types && (!$function_like_storage || !$function_like_storage->has_yield)) {
            if ($declared_return_type->is_void() || $declared_return_type->is_never()) {
                return null;
            }
            if (Scope_Analyzer::only_throws_or_exits($type_provider, $function_stmts)) {
                // if there's a single throw statement, it's presumably an exception saying this method is not to be
                // used
                return null;
            }
            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['InvalidReturnType']) && !in_array('InvalidReturnType', $suppressed_issues)) {
                self::add_or_update_return_type($function, $project_analyzer, Type::get_void(), $source, $compatible_method_ids || ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock);
                return null;
            }
            if (!$declared_return_type->from_docblock || !$declared_return_type->is_nullable()) {
                if (Issue_Buffer::accepts(new Invalid_Return_Type('No return statements were found for method ' . $cased_method_id . ' but return type \'' . $declared_return_type . '\' was expected', $return_type_location), $suppressed_issues, true)) {
                    return false;
                }
            }
            return null;
        }
        if (!$declared_return_type->has_mixed()) {
            if ($inferred_return_type->is_void() && ($declared_return_type->is_void() || $function_like_storage && $function_like_storage->has_yield)) {
                return null;
            }
            if ($inferred_return_type->has_mixed()) {
                return null;
            }
            $union_comparison_results = new Type_Comparison_Result();
            if ($declared_return_type->explicit_never === true && $inferred_return_type_with_never->explicit_never === false) {
                if (Issue_Buffer::accepts(new More_Specific_Return_Type('The declared return type \'' . $declared_return_type->get_id() . '|never\' for ' . $cased_method_id . ' is more specific than the inferred return type ' . '\'' . $inferred_return_type->get_id() . '\'', $return_type_location), $suppressed_issues)) {
                    return false;
                }
            }
            if (!$declared_return_type->is_never() && $function_always_exits && ($declared_return_type->from_docblock || $codebase->analysis_php_version_id >= 81000) && !Scope_Analyzer::only_throws($function_stmts)) {
                if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['InvalidReturnType']) && !in_array('InvalidReturnType', $suppressed_issues)) {
                    self::add_or_update_return_type($function, $project_analyzer, Type::get_never(), $source, ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                    return null;
                }
                if (Issue_Buffer::accepts(new Invalid_Return_Type('The declared return type \'' . $declared_return_type->get_id() . '\' for ' . $cased_method_id . ' is incorrect, got \'never\'', $return_type_location), $suppressed_issues, true)) {
                    return false;
                }
            }
            if (!Union_Type_Comparator::is_contained_by($codebase, $inferred_return_type, $declared_return_type, true, true, $union_comparison_results)) {
                // is the declared return type more specific than the inferred one?
                if ($union_comparison_results->type_coerced) {
                    if ($union_comparison_results->type_coerced_from_mixed) {
                        if (!$union_comparison_results->type_coerced_from_as_mixed) {
                            if (Issue_Buffer::accepts(new Mixed_Return_Type_Coercion('The declared return type \'' . $declared_return_type->get_id() . '\' for ' . $cased_method_id . ' is more specific than the inferred return type ' . '\'' . $inferred_return_type->get_id() . '\'', $return_type_location), $suppressed_issues)) {
                                return false;
                            }
                        }
                    } else if (Issue_Buffer::accepts(new More_Specific_Return_Type('The declared return type \'' . $declared_return_type->get_id() . '\' for ' . $cased_method_id . ' is more specific than the inferred return type ' . '\'' . $inferred_return_type->get_id() . '\'', $return_type_location), $suppressed_issues)) {
                        return false;
                    }
                } elseif (($declared_return_type->explicit_never === false || !$declared_return_type->is_null()) && (!$declared_return_type->is_nullable() || $parent_class === null && $self_fq_class_name === $source->get_fqcln())) {
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['InvalidReturnType']) && !in_array('InvalidReturnType', $suppressed_issues)) {
                        self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                        return null;
                    }
                    if (Issue_Buffer::accepts(new Invalid_Return_Type('The declared return type \'' . $declared_return_type->get_id() . '\' for ' . $cased_method_id . ' is incorrect, got \'' . $inferred_return_type->get_id() . '\'' . ($union_comparison_results->missing_shape_fields ? ' which is different due to additional array shape fields (' . implode(', ', $union_comparison_results->missing_shape_fields) . ')' : ''), $return_type_location), $suppressed_issues, true)) {
                        return false;
                    }
                }
            } elseif (!Union_Type_Comparator::is_contained_by($codebase, $declared_return_type, $inferred_return_type, false, false)) {
                if ($codebase->alter_code) {
                    if (isset($project_analyzer->get_issues_to_fix()['LessSpecificReturnType']) && !in_array('LessSpecificReturnType', $suppressed_issues) && !($function_like_storage instanceof Method_Storage && $function_like_storage->inheritdoc)) {
                        self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, $compatible_method_ids || ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                    }
                } else {
                    if ($function instanceof Function_ || $function instanceof Closure || $function instanceof Arrow_Function || $function->is_private()) {
                        $check_for_less_specific_type = true;
                    } elseif ($source instanceof Statements_Analyzer) {
                        if ($function_like_storage instanceof Method_Storage) {
                            $check_for_less_specific_type = !$function_like_storage->overridden_somewhere;
                        } else {
                            $check_for_less_specific_type = false;
                        }
                    } else {
                        $check_for_less_specific_type = false;
                    }
                    if ($check_for_less_specific_type && (Config::get_instance()->restrict_return_types || !$inferred_return_type->is_nullable() && $declared_return_type->is_nullable() || !$inferred_return_type->is_falsable() && $declared_return_type->is_falsable())) {
                        if (Issue_Buffer::accepts(new Less_Specific_Return_Type('The inferred return type \'' . $inferred_return_type->get_id() . '\' for ' . $cased_method_id . ' is more specific than the declared return type \'' . $declared_return_type->get_id() . '\'', $return_type_location), $suppressed_issues, !($function_like_storage instanceof Method_Storage && $function_like_storage->inheritdoc))) {
                            return false;
                        }
                    }
                }
            }
            if ($union_comparison_results->to_string_cast) {
                Issue_Buffer::maybe_add(new Implicit_To_String_Cast('The declared return type for ' . $cased_method_id . ' expects \'' . $declared_return_type . '\', ' . '\'' . $inferred_return_type . '\' provided with a __toString method', $return_type_location), $suppressed_issues);
            }
            if (!$inferred_return_type->ignore_nullable_issues && $inferred_return_type->is_nullable() && !$declared_return_type->is_nullable() && !$declared_return_type->has_template() && !$declared_return_type->is_void()) {
                if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['InvalidNullableReturnType']) && !in_array('InvalidNullableReturnType', $suppressed_issues) && !$inferred_return_type->is_null()) {
                    self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                    return null;
                }
                if (Issue_Buffer::accepts(new Invalid_Nullable_Return_Type('The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id . ' is not nullable, but \'' . $inferred_return_type . '\' contains null', $return_type_location), $suppressed_issues, !$inferred_return_type->is_null())) {
                    return false;
                }
            }
            if (!$inferred_return_type->ignore_falsable_issues && $inferred_return_type->is_falsable() && !$declared_return_type->is_falsable() && !$declared_return_type->has_bool() && !$declared_return_type->has_scalar()) {
                if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['InvalidFalsableReturnType'])) {
                    self::add_or_update_return_type($function, $project_analyzer, $inferred_return_type, $source, ($project_analyzer->only_replace_php_types_with_non_docblock_types || $unsafe_return_type) && $inferred_return_type->from_docblock, $function_like_storage);
                    return null;
                }
                if (Issue_Buffer::accepts(new Invalid_Falsable_Return_Type('The declared return type \'' . $declared_return_type . '\' for ' . $cased_method_id . ' does not allow false, but \'' . $inferred_return_type . '\' contains false', $return_type_location), $suppressed_issues, true)) {
                    return false;
                }
            }
        }
        return null;
    }
    /**
     * @param Closure|Function_|ClassMethod|ArrowFunction $function
     * @return false|null
     */
    public static function check_return_type(Function_Like $function, Project_Analyzer $project_analyzer, Function_Like_Analyzer $function_like_analyzer, Function_Like_Storage $storage, Context $context): ?bool
    {
        $codebase = $project_analyzer->get_codebase();
        if (!$storage->return_type || !$storage->return_type_location) {
            return null;
        }
        $parent_class = null;
        $classlike_storage = null;
        if ($context->self) {
            $classlike_storage = $codebase->classlike_storage_provider->get($context->self);
            $parent_class = $classlike_storage->parent_class;
        }
        if (!$storage->signature_return_type || $storage->signature_return_type === $storage->return_type) {
            foreach ($storage->return_type->get_atomic_types() as $type) {
                if ($type instanceof T_Named_Object && 'parent' === $type->value && null === $parent_class) {
                    if (Issue_Buffer::accepts(new Invalid_Parent('Cannot use parent as a return type when class has no parent', $storage->return_type_location), $storage->suppressed_issues)) {
                        return false;
                    }
                    return null;
                }
            }
            $fleshed_out_return_type = Type_Expander::expand_union($codebase, $storage->return_type, $classlike_storage->name ?? null, $classlike_storage->name ?? null, $parent_class);
            /** @psalm-suppress UnusedMethodCall This call actually has the side effect of creating issues */
            $fleshed_out_return_type->check($function_like_analyzer, $storage->return_type_location, $storage->suppressed_issues, [], false, false, false, $context->calling_method_id);
            return null;
        }
        $fleshed_out_signature_type = Type_Expander::expand_union($codebase, $storage->signature_return_type, $classlike_storage->name ?? null, $classlike_storage->name ?? null, $parent_class);
        if ($fleshed_out_signature_type->check($function_like_analyzer, $storage->signature_return_type_location ?: $storage->return_type_location, $storage->suppressed_issues, [], false) === false) {
            return false;
        }
        if ($function instanceof Closure || $function instanceof Arrow_Function) {
            return null;
        }
        try {
            $fleshed_out_return_type = Type_Expander::expand_union($codebase, $storage->return_type, $classlike_storage->name ?? null, $classlike_storage->name ?? null, $parent_class, true, true, false, false, false, true);
        } catch (Unresolvable_Constant_Exception $e) {
            Issue_Buffer::maybe_add(new Unresolvable_Constant("Could not resolve constant {$e->class_name}::{$e->const_name}", $storage->return_type_location), $storage->suppressed_issues, true);
            $fleshed_out_return_type = $storage->return_type;
        }
        if ($fleshed_out_return_type->check($function_like_analyzer, $storage->return_type_location, $storage->suppressed_issues, [], false, $storage instanceof Method_Storage && $storage->inherited_return_type) === false) {
            return false;
        }
        if ($classlike_storage && $context->self) {
            $class_template_params = Class_Template_Param_Collector::collect($codebase, $classlike_storage, $codebase->classlike_storage_provider->get($context->self), strtolower($function->name->name), new T_Named_Object($context->self), true);
            $class_template_params = $class_template_params ?: [];
            if ($class_template_params) {
                $template_result = new Template_Result($class_template_params, []);
                $fleshed_out_return_type = Template_Standin_Type_Replacer::replace($fleshed_out_return_type, $template_result, $codebase, null, null);
            }
        }
        $union_comparison_result = new Type_Comparison_Result();
        if (!Union_Type_Comparator::is_contained_by($codebase, $fleshed_out_return_type, $fleshed_out_signature_type, false, false, $union_comparison_result) && !$union_comparison_result->type_coerced_from_mixed) {
            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MismatchingDocblockReturnType'])) {
                self::add_or_update_return_type($function, $project_analyzer, $storage->signature_return_type, $function_like_analyzer->get_source());
                return null;
            }
            if (Issue_Buffer::accepts(new Mismatching_Docblock_Return_Type('Docblock has incorrect return type \'' . $storage->return_type->get_id() . '\', should be \'' . $storage->signature_return_type->get_id() . '\'', $storage->return_type_location), $storage->suppressed_issues, true)) {
                return false;
            }
        }
        return null;
    }
    /**
     * @param Closure|Function_|ClassMethod|ArrowFunction $function
     */
    private static function add_or_update_return_type(Function_Like $function, Project_Analyzer $project_analyzer, Union $inferred_return_type, Statements_Source $source, bool $docblock_only = false, ?Function_Like_Storage $function_like_storage = null): void
    {
        $manipulator = Function_Docblock_Manipulator::get_for_function($project_analyzer, $source->get_file_path(), $function);
        $codebase = $project_analyzer->get_codebase();
        $is_final = true;
        $fqcln = $source->get_fqcln();
        if ($fqcln !== null && $function instanceof Class_Method) {
            $class_storage = $codebase->classlike_storage_provider->get($fqcln);
            $is_final = $function->is_final() || $class_storage->final;
        }
        $allow_native_type = !$docblock_only && $codebase->analysis_php_version_id >= 70000 && ($codebase->allow_backwards_incompatible_changes || $is_final || !$function instanceof Php_Parser\Node\Stmt\Class_Method);
        $manipulator->set_return_type($allow_native_type ? (string) $inferred_return_type->to_php_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), $codebase->analysis_php_version_id) : null, $inferred_return_type->to_namespaced_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), false), $inferred_return_type->to_namespaced_string($source->get_namespace(), $source->get_aliased_classes_flipped(), $source->get_fqcln(), true), $inferred_return_type->can_be_fully_expressed_in_php($codebase->analysis_php_version_id), $function_like_storage->return_type_description ?? null);
    }
}