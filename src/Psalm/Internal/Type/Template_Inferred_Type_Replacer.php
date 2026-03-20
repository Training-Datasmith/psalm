<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use InvalidArgumentException;
use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Key_Of;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Properties_Of;
use Psalm\Type\Atomic\T_Template_Indexed_Access;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Template_Properties_Of;
use Psalm\Type\Atomic\T_Template_Value_Of;
use Psalm\Type\Atomic\T_Value_Of;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_merge;
use function array_shift;
use function array_values;
use function assert;
use function str_starts_with;
/**
 * @internal
 */
final class Template_Inferred_Type_Replacer
{
    /**
     * This replaces template types in unions with the inferred types they should be
     *
     * @psalm-external-mutation-free
     */
    public static function replace(Union $union, Template_Result $template_result, ?Codebase $codebase): Union
    {
        $new_types = [];
        $is_mixed = false;
        $inferred_lower_bounds = $template_result->lower_bounds ?: [];
        $types = [];
        foreach ($union->get_atomic_types() as $key => $atomic_type) {
            $should_set = true;
            $atomic_type = $atomic_type->replace_template_types_with_arg_types($template_result, $codebase);
            if ($atomic_type instanceof T_Template_Param) {
                $template_type = self::replace_template_param($codebase, $atomic_type, $inferred_lower_bounds, $key);
                if ($template_type) {
                    $should_set = false;
                    foreach ($template_type->get_atomic_types() as $template_type_part) {
                        if ($template_type_part instanceof T_Mixed) {
                            $is_mixed = true;
                        }
                        $new_types[] = $template_type_part;
                    }
                }
            } elseif ($atomic_type instanceof T_Template_Param_Class) {
                $template_type = isset($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class]) ? Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase) : null;
                $class_template_type = null;
                if ($template_type) {
                    foreach ($template_type->get_atomic_types() as $template_type_part) {
                        if ($template_type_part instanceof T_Mixed || $template_type_part instanceof T_Object) {
                            $class_template_type = new T_Class_String();
                        } elseif ($template_type_part instanceof T_Named_Object) {
                            $class_template_type = new T_Class_String($template_type_part->value, $template_type_part);
                        } elseif ($template_type_part instanceof T_Template_Param) {
                            $first_atomic_type = $template_type_part->as->get_single_atomic();
                            $class_template_type = new T_Template_Param_Class($template_type_part->param_name, $template_type_part->as->get_id(), $first_atomic_type instanceof T_Named_Object ? $first_atomic_type : null, $template_type_part->defining_class);
                        }
                    }
                }
                if ($class_template_type) {
                    $should_set = false;
                    $new_types[] = $class_template_type;
                }
            } elseif ($atomic_type instanceof T_Template_Indexed_Access) {
                $should_set = false;
                $template_type = null;
                if (isset($inferred_lower_bounds[$atomic_type->array_param_name][$atomic_type->defining_class]) && !empty($inferred_lower_bounds[$atomic_type->offset_param_name])) {
                    $array_template_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$atomic_type->array_param_name][$atomic_type->defining_class], $codebase);
                    $offset_template_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds(array_values($inferred_lower_bounds[$atomic_type->offset_param_name])[0], $codebase);
                    if ($array_template_type->is_single() && $offset_template_type->is_single() && !$array_template_type->is_mixed() && !$offset_template_type->is_mixed()) {
                        $array_template_type = $array_template_type->get_single_atomic();
                        $offset_template_type = $offset_template_type->get_single_atomic();
                        if ($array_template_type instanceof T_Keyed_Array && ($offset_template_type instanceof T_Literal_String || $offset_template_type instanceof T_Literal_Int) && isset($array_template_type->properties[$offset_template_type->value])) {
                            $template_type = $array_template_type->properties[$offset_template_type->value];
                        }
                    }
                }
                if ($template_type) {
                    foreach ($template_type->get_atomic_types() as $template_type_part) {
                        if ($template_type_part instanceof T_Mixed) {
                            $is_mixed = true;
                        }
                        $new_types[] = $template_type_part;
                    }
                } else {
                    $new_types[] = new T_Mixed();
                }
            } elseif ($atomic_type instanceof T_Template_Key_Of || $atomic_type instanceof T_Template_Value_Of) {
                $new_type = self::replace_template_key_of_value_of($codebase, $atomic_type, $inferred_lower_bounds);
                if ($new_type) {
                    $should_set = false;
                    $new_types[] = $new_type;
                }
            } elseif ($atomic_type instanceof T_Template_Properties_Of) {
                $new_type = self::replace_template_properties_of($codebase, $atomic_type, $inferred_lower_bounds);
                if ($new_type) {
                    $should_set = false;
                    $new_types[] = $new_type;
                }
            } elseif ($atomic_type instanceof T_Conditional && $codebase) {
                $class_template_type = self::replace_conditional($template_result, $codebase, $atomic_type, $inferred_lower_bounds);
                $should_set = false;
                foreach ($class_template_type->get_atomic_types() as $class_template_atomic_type) {
                    $new_types[] = $class_template_atomic_type;
                }
            }
            if ($should_set) {
                $types[] = $atomic_type;
            }
        }
        if ($is_mixed) {
            if (!$new_types) {
                throw new UnexpectedValueException('This array should be full');
            }
            return $union->get_builder()->set_types(Type_Combiner::combine($new_types, $codebase)->get_atomic_types())->freeze();
        }
        $atomic_types = [...$types, ...$new_types];
        if (!$atomic_types) {
            throw new UnexpectedValueException('This array should be full');
        }
        return $union->get_builder()->set_types(Type_Combiner::combine($atomic_types, $codebase)->get_atomic_types())->freeze();
    }
    /**
     * @param array<string, array<string, non-empty-list<TemplateBound>>> $inferred_lower_bounds
     */
    private static function replace_template_param(?Codebase $codebase, T_Template_Param $atomic_type, array $inferred_lower_bounds, string $key): ?Union
    {
        $template_type = null;
        $traversed_type = Template_Standin_Type_Replacer::get_root_template_type($inferred_lower_bounds, $atomic_type->param_name, $atomic_type->defining_class, [], $codebase);
        if ($traversed_type) {
            $template_type = $traversed_type;
            if ($template_type->is_mixed() && !$atomic_type->as->is_mixed()) {
                $template_type = $atomic_type->as;
            }
            if ($atomic_type->extra_types) {
                $types = [];
                foreach ($template_type->get_atomic_types() as $atomic_template_type) {
                    if ($atomic_template_type instanceof T_Named_Object || $atomic_template_type instanceof T_Template_Param || $atomic_template_type instanceof T_Iterable || $atomic_template_type instanceof T_Object_With_Properties) {
                        $types[] = $atomic_template_type->set_intersection_types(array_merge($atomic_type->extra_types, $atomic_template_type->extra_types));
                    } elseif ($atomic_template_type instanceof T_Object) {
                        $first_atomic_type = array_shift($atomic_type->extra_types);
                        assert($first_atomic_type !== null);
                        if ($atomic_type->extra_types) {
                            $first_atomic_type = $first_atomic_type->set_intersection_types($atomic_type->extra_types);
                        }
                        $types[] = $first_atomic_type;
                    } else {
                        $types[] = $atomic_template_type;
                    }
                }
                $template_type = $template_type->get_builder()->set_types($types)->freeze();
            }
        } elseif ($codebase) {
            foreach ($inferred_lower_bounds as $template_type_map) {
                foreach ($template_type_map as $template_class => $_) {
                    if (str_starts_with($template_class, 'fn-')) {
                        continue;
                    }
                    try {
                        $classlike_storage = $codebase->classlike_storage_provider->get($template_class);
                        if ($classlike_storage->template_extended_params) {
                            $defining_class = $atomic_type->defining_class;
                            if (isset($classlike_storage->template_extended_params[$defining_class])) {
                                $param_map = $classlike_storage->template_extended_params[$defining_class];
                                if (isset($param_map[$key])) {
                                    $template_name = (string) $param_map[$key];
                                    if (isset($inferred_lower_bounds[$template_name][$template_class])) {
                                        $template_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$template_name][$template_class], $codebase);
                                    }
                                }
                            }
                        }
                    } catch (InvalidArgumentException) {
                    }
                }
            }
        }
        return $template_type;
    }
    /**
     * @param TTemplateKeyOf|TTemplateValueOf $atomic_type
     * @param array<string, array<string, non-empty-list<TemplateBound>>> $inferred_lower_bounds
     */
    private static function replace_template_key_of_value_of(?Codebase $codebase, Atomic $atomic_type, array $inferred_lower_bounds): ?Atomic
    {
        if (!isset($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class])) {
            return null;
        }
        $template_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase);
        if ($atomic_type instanceof T_Template_Key_Of && T_Key_Of::is_viable_template_type($template_type)) {
            return new T_Key_Of($template_type);
        }
        if ($atomic_type instanceof T_Template_Value_Of && T_Value_Of::is_viable_template_type($template_type)) {
            return new T_Value_Of($template_type);
        }
        return null;
    }
    /**
     * @param array<string, array<string, non-empty-list<TemplateBound>>> $inferred_lower_bounds
     */
    private static function replace_template_properties_of(?Codebase $codebase, T_Template_Properties_Of $atomic_type, array $inferred_lower_bounds): ?Atomic
    {
        if (!isset($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class])) {
            return null;
        }
        $template_type = Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase);
        $classlike_type = $template_type->get_single_atomic();
        if (!$classlike_type instanceof T_Named_Object) {
            return null;
        }
        return new T_Properties_Of($classlike_type, $atomic_type->visibility_filter);
    }
    /**
     * @param array<string, array<string, non-empty-list<TemplateBound>>> $inferred_lower_bounds
     */
    private static function replace_conditional(Template_Result $template_result, Codebase $codebase, T_Conditional &$atomic_type, array $inferred_lower_bounds): Union
    {
        $template_type = isset($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class]) ? Template_Standin_Type_Replacer::get_most_specific_type_from_bounds($inferred_lower_bounds[$atomic_type->param_name][$atomic_type->defining_class], $codebase) : null;
        $if_template_type = null;
        $else_template_type = null;
        $as_type = $atomic_type->as_type;
        $conditional_type = $atomic_type->conditional_type;
        $if_type = $atomic_type->if_type;
        $else_type = $atomic_type->else_type;
        if ($template_type) {
            $as_type = self::replace($as_type, $template_result, $codebase);
            if ($as_type->is_nullable() && $template_type->is_void()) {
                $template_type = Type::get_null();
            }
            $matching_if_types = [];
            $matching_else_types = [];
            $l = $template_type->get_atomic_types();
            foreach (isset($l['mixed']) ? [$l['mixed']] : $l as $candidate_atomic_type) {
                $candidate = new Union([$candidate_atomic_type]);
                if (Union_Type_Comparator::is_contained_by($codebase, $candidate, $conditional_type, false, false, null, false, false) && (!$candidate_atomic_type instanceof T_Int || $conditional_type->get_id() !== 'float')) {
                    $matching_if_types[] = $candidate_atomic_type;
                } elseif (null === Type::intersect_union_types($candidate, $conditional_type, $codebase, false, false)) {
                    $matching_else_types[] = $candidate_atomic_type;
                }
            }
            $if_candidate_type = $matching_if_types ? new Union($matching_if_types) : null;
            $else_candidate_type = $matching_else_types ? new Union($matching_else_types) : null;
            if ($if_candidate_type && Union_Type_Comparator::is_contained_by($codebase, $if_candidate_type, $conditional_type, false, false, null, false, false)) {
                $if_template_type = $if_type;
                $refined_template_result = clone $template_result;
                $refined_template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class] = [new Template_Bound($if_candidate_type)];
                $if_template_type = self::replace($if_template_type, $refined_template_result, $codebase);
            }
            if ($else_candidate_type && Union_Type_Comparator::is_contained_by($codebase, $else_candidate_type, $as_type, false, false, null, false, false)) {
                $else_template_type = $else_type;
                $refined_template_result = clone $template_result;
                $refined_template_result->lower_bounds[$atomic_type->param_name][$atomic_type->defining_class] = [new Template_Bound($else_candidate_type)];
                $else_template_type = self::replace($else_template_type, $refined_template_result, $codebase);
            }
        }
        if (!$if_template_type && !$else_template_type) {
            $if_type = self::replace($if_type, $template_result, $codebase);
            $else_type = self::replace($else_type, $template_result, $codebase);
            $class_template_type = Type::combine_union_types($if_type, $else_type, $codebase);
        } else {
            $class_template_type = Type::combine_union_types($if_template_type, $else_template_type, $codebase);
        }
        $atomic_type = $atomic_type->set_types($as_type, $conditional_type, $if_type, $else_type);
        return $class_template_type;
    }
}