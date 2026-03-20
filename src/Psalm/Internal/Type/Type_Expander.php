<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Codebase;
use Psalm\Exception\Circular_Reference_Exception;
use Psalm\Exception\Unresolvable_Constant_Exception;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Storage\Assertion\Is_Type;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Mask;
use Psalm\Type\Atomic\T_Int_Mask_Of;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Key_Of;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Properties_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Type_Alias;
use Psalm\Type\Atomic\T_Value_Of;
use Psalm\Type\Atomic\T_Void;
use Psalm\Type\Union;
use ReflectionProperty;
use function array_any;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function count;
use function is_string;
use function reset;
use function strtolower;
/**
 * @internal
 */
final class Type_Expander
{
    /**
     * @psalm-suppress InaccessibleProperty We just created the type
     */
    public static function expand_union(Codebase $codebase, Union $return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type, ?string $parent_class, bool $evaluate_class_constants = true, bool $evaluate_conditional_types = false, bool $final = false, bool $expand_generic = false, bool $expand_templates = false, bool $throw_on_unresolvable_constant = false): Union
    {
        $new_return_type_parts = [];
        foreach ($return_type->get_atomic_types() as $return_type_part) {
            $parts = self::expand_atomic($codebase, $return_type_part, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            $new_return_type_parts = [...$new_return_type_parts, ...$parts];
        }
        $fleshed_out_type = Type_Combiner::combine($new_return_type_parts, $codebase);
        $fleshed_out_type->from_docblock = $return_type->from_docblock;
        $fleshed_out_type->ignore_nullable_issues = $return_type->ignore_nullable_issues;
        $fleshed_out_type->ignore_falsable_issues = $return_type->ignore_falsable_issues;
        $fleshed_out_type->possibly_undefined = $return_type->possibly_undefined;
        $fleshed_out_type->possibly_undefined_from_try = $return_type->possibly_undefined_from_try;
        $fleshed_out_type->by_ref = $return_type->by_ref;
        $fleshed_out_type->initialized = $return_type->initialized;
        $fleshed_out_type->from_property = $return_type->from_property;
        $fleshed_out_type->from_static_property = $return_type->from_static_property;
        $fleshed_out_type->explicit_never = $return_type->explicit_never;
        $fleshed_out_type->had_template = $return_type->had_template;
        $fleshed_out_type->parent_nodes = $return_type->parent_nodes;
        return $fleshed_out_type;
    }
    /**
     * @param-out Atomic $return_type
     * @return non-empty-list<Atomic>
     * @psalm-suppress ConflictingReferenceConstraint, ReferenceConstraintViolation The output type is always Atomic
     * @psalm-suppress ComplexMethod
     */
    public static function expand_atomic(Codebase $codebase, Atomic &$return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type, ?string $parent_class, bool $evaluate_class_constants = true, bool $evaluate_conditional_types = false, bool $final = false, bool $expand_generic = false, bool $expand_templates = false, bool $throw_on_unresolvable_constant = false): array
    {
        if ($return_type instanceof T_Enum_Case) {
            return [$return_type];
        }
        if ($return_type instanceof T_Named_Object || $return_type instanceof T_Template_Param) {
            if ($return_type->extra_types) {
                $new_intersection_types = [];
                $extra_types = [];
                foreach ($return_type->extra_types as $extra_type) {
                    self::expand_atomic($codebase, $extra_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                    if ($extra_type instanceof T_Named_Object && $extra_type->extra_types) {
                        $new_intersection_types = [...$new_intersection_types, ...$extra_type->extra_types];
                        $extra_type = $extra_type->set_intersection_types([]);
                    }
                    $extra_types[$extra_type->get_key()] = $extra_type;
                }
                /** @psalm-suppress ArgumentTypeCoercion */
                $return_type = $return_type->set_intersection_types(array_merge($extra_types, $new_intersection_types));
            }
            if ($return_type instanceof T_Named_Object) {
                $return_type = self::expand_named_object($codebase, $return_type, $self_class, $static_class_type, $parent_class, $final, $expand_generic);
            }
        }
        if ($return_type instanceof T_Class_String && $return_type->as_type) {
            $new_as_type = $return_type->as_type;
            self::expand_atomic($codebase, $new_as_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            if ($new_as_type instanceof T_Named_Object && $new_as_type !== $return_type->as_type) {
                $return_type = $return_type->set_as($new_as_type->value, $new_as_type);
            }
        } elseif ($return_type instanceof T_Template_Param) {
            $new_as_type = self::expand_union($codebase, $return_type->as, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            if ($expand_templates) {
                return array_values($new_as_type->get_atomic_types());
            }
            $return_type = $return_type->replace_as($new_as_type);
        }
        if ($return_type instanceof T_Class_Constant) {
            if ($self_class) {
                $return_type = $return_type->replace_class_like('self', $self_class);
            }
            if (is_string($static_class_type) || $self_class) {
                $return_type = $return_type->replace_class_like('static', is_string($static_class_type) ? $static_class_type : $self_class);
            }
            if ($evaluate_class_constants && $codebase->class_or_interface_or_enum_exists($return_type->fq_classlike_name)) {
                if (strtolower($return_type->const_name) === 'class') {
                    return [new T_Literal_Class_String($return_type->fq_classlike_name)];
                }
                try {
                    $class_constant = $codebase->classlikes->get_class_constant_type($return_type->fq_classlike_name, $return_type->const_name, ReflectionProperty::IS_PRIVATE);
                } catch (Circular_Reference_Exception) {
                    $class_constant = null;
                }
                if ($class_constant) {
                    return array_values($class_constant->get_atomic_types());
                }
            }
            return [$return_type];
        }
        if ($return_type instanceof T_Properties_Of) {
            return self::expand_properties_of($codebase, $return_type, $self_class, $static_class_type);
        }
        if ($return_type instanceof T_Type_Alias) {
            $declaring_fq_classlike_name = $return_type->declaring_fq_classlike_name;
            if ($declaring_fq_classlike_name === 'self' && $self_class) {
                $declaring_fq_classlike_name = $self_class;
            }
            if (!($evaluate_class_constants && $codebase->classlikes->does_class_like_exist(strtolower($declaring_fq_classlike_name)))) {
                return [$return_type];
            }
            $class_storage = $codebase->classlike_storage_provider->get($declaring_fq_classlike_name);
            $type_alias_name = $return_type->alias_name;
            if (!isset($class_storage->type_aliases[$type_alias_name])) {
                return [$return_type];
            }
            $resolved_type_alias = $class_storage->type_aliases[$type_alias_name];
            $replacement_atomic_types = $resolved_type_alias->replacement_atomic_types;
            if (!$replacement_atomic_types) {
                return [$return_type];
            }
            $recursively_fleshed_out_types = [];
            foreach ($replacement_atomic_types as $replacement_atomic_type) {
                $more_recursively_fleshed_out_types = self::expand_atomic($codebase, $replacement_atomic_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                $recursively_fleshed_out_types = [...$more_recursively_fleshed_out_types, ...$recursively_fleshed_out_types];
            }
            return $recursively_fleshed_out_types;
        }
        if ($return_type instanceof T_Key_Of || $return_type instanceof T_Value_Of) {
            return self::expand_key_of_value_of($codebase, $return_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
        }
        if ($return_type instanceof T_Int_Mask) {
            if (!$evaluate_class_constants) {
                return [new T_Int()];
            }
            $potential_ints = [];
            foreach ($return_type->values as $value_type) {
                $new_value_type = self::expand_atomic($codebase, $value_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                $new_value_type = reset($new_value_type);
                if (!$new_value_type instanceof T_Literal_Int) {
                    return [new T_Int()];
                }
                $potential_ints[] = $new_value_type->value;
            }
            return Type_Parser::get_computed_ints_from_mask($potential_ints);
        }
        if ($return_type instanceof T_Int_Mask_Of) {
            if (!$evaluate_class_constants) {
                return [new T_Int()];
            }
            $value_type = $return_type->value;
            $new_value_types = self::expand_atomic($codebase, $value_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            $potential_ints = [];
            foreach ($new_value_types as $new_value_type) {
                if (!$new_value_type instanceof T_Literal_Int) {
                    return [new T_Int()];
                }
                $potential_ints[] = $new_value_type->value;
            }
            return Type_Parser::get_computed_ints_from_mask($potential_ints);
        }
        if ($return_type instanceof T_Conditional) {
            return self::expand_conditional($codebase, $return_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
        }
        if ($return_type instanceof T_Array || $return_type instanceof T_Generic_Object || $return_type instanceof T_Iterable) {
            $type_params = $return_type->type_params;
            foreach ($type_params as &$type_param) {
                $type_param = self::expand_union($codebase, $type_param, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            }
            unset($type_param);
            /** @psalm-suppress InvalidArgument Psalm bug */
            $return_type = $return_type->set_type_params($type_params);
        } elseif ($return_type instanceof T_Keyed_Array) {
            $properties = $return_type->properties;
            $changed = false;
            foreach ($properties as $k => $property_type) {
                $property_type = self::expand_union($codebase, $property_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                if ($property_type !== $properties[$k]) {
                    $changed = true;
                    $properties[$k] = $property_type;
                }
            }
            unset($property_type);
            $fallback_params = $return_type->fallback_params;
            if ($fallback_params) {
                foreach ($fallback_params as $k => $property_type) {
                    $property_type = self::expand_union($codebase, $property_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                    if ($property_type !== $fallback_params[$k]) {
                        $changed = true;
                        $fallback_params[$k] = $property_type;
                    }
                }
                unset($property_type);
            }
            if ($changed) {
                $return_type = new T_Keyed_Array($properties, $return_type->class_strings, $fallback_params, $return_type->is_list, $return_type->from_docblock);
            }
        }
        if ($return_type instanceof T_Object_With_Properties) {
            $properties = $return_type->properties;
            foreach ($properties as &$property_type) {
                $property_type = self::expand_union($codebase, $property_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            }
            unset($property_type);
            $return_type = $return_type->set_properties($properties);
        }
        if ($return_type instanceof T_Callable || $return_type instanceof T_Closure) {
            $params = $return_type->params;
            if ($params) {
                foreach ($params as &$param) {
                    if ($param->type) {
                        $param = $param->set_type(self::expand_union($codebase, $param->type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant));
                    }
                }
                unset($param);
            }
            $sub_return_type = $return_type->return_type;
            if ($sub_return_type) {
                $sub_return_type = self::expand_union($codebase, $sub_return_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
            }
            $return_type = $return_type->replace($params, $sub_return_type);
        }
        return [$return_type];
    }
    /**
     * @param-out TNamedObject|TTemplateParam $return_type
     */
    private static function expand_named_object(Codebase $codebase, T_Named_Object &$return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type, ?string $parent_class, bool $final = false, bool &$expand_generic = false): T_Named_Object|T_Template_Param
    {
        if ($expand_generic && $return_type::class === T_Named_Object::class && !$return_type->extra_types && $codebase->class_or_interface_exists($return_type->value)) {
            $value = $codebase->classlikes->get_un_aliased_name($return_type->value);
            $container_class_storage = $codebase->classlike_storage_provider->get($value);
            if ($container_class_storage->template_types && array_any($container_class_storage->template_types, static fn($type_map): bool => !reset($type_map)->has_mixed())) {
                $return_type = new T_Generic_Object($return_type->value, array_values(array_map(reset(...), $container_class_storage->template_types)));
                // we don't want to expand generic types recursively
                $expand_generic = false;
            }
        }
        $return_type_lc = strtolower($return_type->value);
        if ($static_class_type && ($return_type_lc === 'static' || $return_type_lc === '$this')) {
            $is_static = $return_type->is_static;
            $is_static_resolved = null;
            if (!$final) {
                $is_static = true;
                $is_static_resolved = true;
            }
            if (is_string($static_class_type)) {
                $return_type = $return_type->set_value_is_static($static_class_type, $is_static, $is_static_resolved);
            } else if ($return_type instanceof T_Generic_Object && $static_class_type instanceof T_Generic_Object) {
                $return_type = $return_type->set_value_is_static($static_class_type->value, $is_static, $is_static_resolved);
            } elseif ($static_class_type instanceof T_Named_Object) {
                $return_type = $static_class_type->set_is_static($is_static, $is_static_resolved);
            } else {
                $return_type = $static_class_type;
            }
        } elseif ($return_type->is_static && !$return_type->is_static_resolved && ($static_class_type instanceof T_Named_Object || $static_class_type instanceof T_Template_Param)) {
            $return_type_types = $return_type->get_intersection_types();
            $cloned_static = $static_class_type->set_intersection_types([]);
            $extra_static = $static_class_type->extra_types;
            if ($cloned_static->get_key(false) !== $return_type->get_key(false)) {
                $return_type_types[$cloned_static->get_key()] = $cloned_static;
            }
            foreach ($extra_static as $extra_static_type) {
                if ($extra_static_type->get_key(false) !== $return_type->get_key(false)) {
                    $return_type_types[$extra_static_type->get_key()] = $extra_static_type;
                }
            }
            $return_type = $return_type->set_intersection_types($return_type_types)->set_is_static(true, true);
        } elseif ($return_type->is_static && is_string($static_class_type) && $final && ($return_type->value === $self_class || $self_class !== null && ($codebase->class_extends($return_type->value, $self_class) || $codebase->class_extends($self_class, $return_type->value)))) {
            $return_type = $return_type->set_value_is_static($static_class_type, false);
        } elseif ($self_class && $return_type_lc === 'self') {
            $return_type = $return_type->set_value($self_class);
        } elseif ($parent_class && $return_type_lc === 'parent') {
            $return_type = $return_type->set_value($parent_class);
        } else {
            $new_value = $codebase->classlikes->get_un_aliased_name($return_type->value);
            $return_type = $return_type->set_value($new_value);
        }
        return $return_type;
    }
    /**
     * @return non-empty-list<Atomic>
     */
    private static function expand_conditional(Codebase $codebase, T_Conditional &$return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type, ?string $parent_class, bool $evaluate_class_constants = true, bool $evaluate_conditional_types = false, bool $final = false, bool $expand_generic = false, bool $expand_templates = false, bool $throw_on_unresolvable_constant = false): array
    {
        $new_as_type = self::expand_union($codebase, $return_type->as_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
        if ($evaluate_conditional_types) {
            $assertion = null;
            if ($return_type->conditional_type->is_single()) {
                foreach ($return_type->conditional_type->get_atomic_types() as $condition_atomic_type) {
                    $candidate = self::expand_atomic($codebase, $condition_atomic_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                    if (count($candidate) === 1) {
                        $assertion = new Is_Type($candidate[0]);
                    }
                }
            }
            $if_conditional_return_types = [];
            foreach ($return_type->if_type->get_atomic_types() as $if_atomic_type) {
                $candidate_types = self::expand_atomic($codebase, $if_atomic_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                $if_conditional_return_types = [...$if_conditional_return_types, ...$candidate_types];
            }
            $else_conditional_return_types = [];
            foreach ($return_type->else_type->get_atomic_types() as $else_atomic_type) {
                $candidate_types = self::expand_atomic($codebase, $else_atomic_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                $else_conditional_return_types = [...$else_conditional_return_types, ...$candidate_types];
            }
            if ($assertion && $return_type->param_name === (string) $return_type->if_type) {
                $if_conditional_return_type = Type_Combiner::combine($if_conditional_return_types, $codebase);
                $if_conditional_return_type = Simple_Assertion_Reconciler::reconcile($assertion, $codebase, $if_conditional_return_type);
                if ($if_conditional_return_type) {
                    $if_conditional_return_types = array_values($if_conditional_return_type->get_atomic_types());
                }
            }
            if ($assertion && $return_type->param_name === (string) $return_type->else_type) {
                $else_conditional_return_type = Type_Combiner::combine($else_conditional_return_types, $codebase);
                $else_conditional_return_type = Simple_Negated_Assertion_Reconciler::reconcile($codebase, $assertion, $else_conditional_return_type);
                if ($else_conditional_return_type) {
                    $else_conditional_return_types = array_values($else_conditional_return_type->get_atomic_types());
                }
            }
            $all_conditional_return_types = [...$if_conditional_return_types, ...$else_conditional_return_types];
            $number_of_types = count($all_conditional_return_types);
            // we filter TNever that have no bearing on the return type
            if ($number_of_types > 1) {
                $all_conditional_return_types = array_filter($all_conditional_return_types, static fn(Atomic $atomic_type): bool => !$atomic_type instanceof T_Never);
            }
            // if we still have more than one type, we remove TVoid and replace it by TNull
            $number_of_types = count($all_conditional_return_types);
            if ($number_of_types > 1) {
                $all_conditional_return_types = array_filter($all_conditional_return_types, static fn(Atomic $atomic_type): bool => !$atomic_type instanceof T_Void);
                if (count($all_conditional_return_types) !== $number_of_types) {
                    $all_conditional_return_types[] = new T_Null(true);
                }
            }
            if ($all_conditional_return_types) {
                $combined = Type_Combiner::combine(array_values($all_conditional_return_types), $codebase);
                $return_type = $return_type->set_types($new_as_type);
                return array_values($combined->get_atomic_types());
            }
        }
        $return_type = $return_type->set_types($new_as_type, self::expand_union($codebase, $return_type->conditional_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant), self::expand_union($codebase, $return_type->if_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant), self::expand_union($codebase, $return_type->else_type, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant));
        return [$return_type];
    }
    /**
     * @return non-empty-list<Atomic>
     */
    private static function expand_properties_of(Codebase $codebase, T_Properties_Of &$return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type): array
    {
        if ($self_class) {
            $return_type = $return_type->replace_class_like('self', $self_class);
            $return_type = $return_type->replace_class_like('static', is_string($static_class_type) ? $static_class_type : $self_class);
        }
        $class_storage = null;
        if ($codebase->class_exists($return_type->classlike_type->value)) {
            $class_storage = $codebase->classlike_storage_provider->get($return_type->classlike_type->value);
        } else {
            foreach ($return_type->classlike_type->extra_types as $type) {
                if ($type instanceof T_Named_Object && $codebase->class_exists($type->value)) {
                    $class_storage = $codebase->classlike_storage_provider->get($type->value);
                    break;
                }
            }
        }
        if (!$class_storage) {
            return [$return_type];
        }
        $all_sealed = true;
        $properties = [];
        foreach ([$class_storage->name, ...array_values($class_storage->parent_classes)] as $class) {
            if (!$codebase->class_exists($class)) {
                continue;
            }
            $storage = $codebase->classlike_storage_provider->get($class);
            if (!$storage->final) {
                $all_sealed = false;
            }
            foreach ($storage->properties as $key => $property) {
                if (isset($properties[$key])) {
                    continue;
                }
                if ($return_type->visibility_filter !== null && $property->visibility !== $return_type->visibility_filter) {
                    continue;
                }
                if ($property->is_static) {
                    continue;
                }
                if (!$property->type) {
                    continue;
                }
                $type = $return_type->classlike_type instanceof T_Generic_Object ? Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, $property->type, $return_type->classlike_type, $storage, $storage) : $property->type;
                $properties[$key] = $type;
            }
        }
        if ($properties === []) {
            return [$return_type];
        }
        return [new T_Keyed_Array($properties, null, $all_sealed ? null : [Type::get_string(), Type::get_mixed()])];
    }
    /**
     * @param TKeyOf|TValueOf $return_type
     * @return non-empty-list<Atomic>
     */
    private static function expand_key_of_value_of(Codebase $codebase, Atomic &$return_type, ?string $self_class, string|T_Named_Object|T_Template_Param|null $static_class_type, ?string $parent_class, bool $evaluate_class_constants = true, bool $evaluate_conditional_types = false, bool $final = false, bool $expand_generic = false, bool $expand_templates = false, bool $throw_on_unresolvable_constant = false): array
    {
        // Expand class constants to their atomics
        $type_atomics = [];
        foreach ($return_type->type->get_atomic_types() as $type_param) {
            if (!$evaluate_class_constants || !$type_param instanceof T_Class_Constant) {
                $type_param_expanded = self::expand_atomic($codebase, $type_param, $self_class, $static_class_type, $parent_class, $evaluate_class_constants, $evaluate_conditional_types, $final, $expand_generic, $expand_templates, $throw_on_unresolvable_constant);
                $type_atomics = [...$type_atomics, ...$type_param_expanded];
                continue;
            }
            if ($self_class) {
                $type_param = $type_param->replace_class_like('self', $self_class);
            }
            if ($throw_on_unresolvable_constant && !$codebase->class_or_interface_or_enum_exists($type_param->fq_classlike_name)) {
                throw new Unresolvable_Constant_Exception($type_param->fq_classlike_name, $type_param->const_name);
            }
            try {
                $constant_type = $codebase->classlikes->get_class_constant_type($type_param->fq_classlike_name, $type_param->const_name, ReflectionProperty::IS_PRIVATE, null, [], false, $return_type instanceof T_Value_Of);
            } catch (Circular_Reference_Exception) {
                return [$return_type];
            }
            if (!$constant_type || $return_type instanceof T_Key_Of && !T_Key_Of::is_viable_template_type($constant_type) || $return_type instanceof T_Value_Of && !T_Value_Of::is_viable_template_type($constant_type)) {
                if ($throw_on_unresolvable_constant) {
                    throw new Unresolvable_Constant_Exception($type_param->fq_classlike_name, $type_param->const_name);
                }
                return [$return_type];
            }
            $type_atomics = array_merge($type_atomics, array_values($constant_type->get_atomic_types()));
        }
        if ($type_atomics === []) {
            return [$return_type];
        }
        if ($return_type instanceof T_Key_Of) {
            $new_return_types = T_Key_Of::get_array_key_type(new Union($type_atomics));
        } else {
            $new_return_types = T_Value_Of::get_value_type(new Union($type_atomics), $codebase);
        }
        if ($new_return_types === null) {
            return [$return_type];
        }
        return array_values($new_return_types->get_atomic_types());
    }
}