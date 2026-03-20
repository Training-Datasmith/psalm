<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use InvalidArgumentException;
use Psalm\Exception\Circular_Reference_Exception;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Offset_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Spread;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Value;
use Psalm\Internal\Scanner\Unresolved_Constant\Class_Constant;
use Psalm\Internal\Scanner\Unresolved_Constant\Constant;
use Psalm\Internal\Scanner\Unresolved_Constant\Enum_Name_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Enum_Property_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Enum_Value_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Scalar_Value;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Addition_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Binary_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_And;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_Or;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_Xor;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Concat_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Division_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Multiplication_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Subtraction_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Ternary;
use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use ReflectionProperty;
use Unit_Enum;
use function ctype_digit;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function spl_object_id;
/**
 * @internal
 */
final class Constant_Type_Resolver
{
    public static function resolve(Class_Likes $classlikes, Unresolved_Constant_Component $c, ?Statements_Analyzer $statements_analyzer = null, array $visited_constant_ids = []): Atomic
    {
        $c_id = spl_object_id($c);
        if (isset($visited_constant_ids[$c_id])) {
            throw new Circular_Reference_Exception('Found a circular reference');
        }
        if ($c instanceof Scalar_Value) {
            return self::get_literal_type_from_scalar_value($c->value);
        }
        if ($c instanceof Unresolved_Binary_Op) {
            $left = self::resolve($classlikes, $c->left, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            $right = self::resolve($classlikes, $c->right, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            if ($left instanceof T_Mixed || $right instanceof T_Mixed) {
                return new T_Mixed();
            }
            if ($c instanceof Unresolved_Concat_Op) {
                if (($left instanceof T_Literal_String || $left instanceof T_Literal_Float || $left instanceof T_Literal_Int) && ($right instanceof T_Literal_String || $right instanceof T_Literal_Float || $right instanceof T_Literal_Int)) {
                    return Type::get_atomic_string_from_literal($left->value . $right->value);
                }
                return new T_String();
            }
            if ($c instanceof Unresolved_Addition_Op || $c instanceof Unresolved_Subtraction_Op || $c instanceof Unresolved_Division_Op || $c instanceof Unresolved_Multiplication_Op || $c instanceof Unresolved_Bitwise_Or || $c instanceof Unresolved_Bitwise_Xor || $c instanceof Unresolved_Bitwise_And) {
                if (($left instanceof T_Literal_Float || $left instanceof T_Literal_Int) && ($right instanceof T_Literal_Float || $right instanceof T_Literal_Int)) {
                    if ($c instanceof Unresolved_Addition_Op) {
                        return self::get_literal_type_from_scalar_value($left->value + $right->value);
                    }
                    if ($c instanceof Unresolved_Subtraction_Op) {
                        return self::get_literal_type_from_scalar_value($left->value - $right->value);
                    }
                    if ($c instanceof Unresolved_Division_Op) {
                        return self::get_literal_type_from_scalar_value($left->value / $right->value);
                    }
                    if ($c instanceof Unresolved_Bitwise_Or) {
                        return self::get_literal_type_from_scalar_value($left->value | $right->value);
                    }
                    if ($c instanceof Unresolved_Bitwise_Xor) {
                        return self::get_literal_type_from_scalar_value($left->value ^ $right->value);
                    }
                    if ($c instanceof Unresolved_Bitwise_And) {
                        return self::get_literal_type_from_scalar_value($left->value & $right->value);
                    }
                    return self::get_literal_type_from_scalar_value($left->value * $right->value);
                }
                if ($left instanceof T_Keyed_Array && $right instanceof T_Keyed_Array) {
                    return new T_Keyed_Array($left->properties + $right->properties);
                }
                return new T_Mixed();
            }
            return new T_Mixed();
        }
        if ($c instanceof Unresolved_Ternary) {
            $cond = self::resolve($classlikes, $c->cond, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            $if = $c->if ? self::resolve($classlikes, $c->if, $statements_analyzer, $visited_constant_ids + [$c_id => true]) : null;
            $else = self::resolve($classlikes, $c->else, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            if ($cond instanceof T_Literal_Float || $cond instanceof T_Literal_Int || $cond instanceof T_Literal_String) {
                if ($cond->value) {
                    return $if ?? $cond;
                }
            } elseif ($cond instanceof T_False || $cond instanceof T_Null) {
                return $else;
            } elseif ($cond instanceof T_True) {
                return $if ?? $cond;
            }
        }
        if ($c instanceof Array_Value) {
            $properties = [];
            $auto_key = 0;
            if (!$c->entries) {
                return new T_Array([Type::get_never(), Type::get_never()]);
            }
            $is_list = true;
            foreach ($c->entries as $entry) {
                if ($entry instanceof Array_Spread) {
                    $spread_array = self::resolve($classlikes, $entry->array, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
                    if ($spread_array instanceof T_Array && $spread_array->is_empty_array()) {
                        continue;
                    }
                    if (!$spread_array instanceof T_Keyed_Array) {
                        return Type::get_array_atomic();
                    }
                    foreach ($spread_array->properties as $k => $spread_array_type) {
                        $properties[is_string($k) ? $k : $auto_key++] = $spread_array_type;
                    }
                    continue;
                }
                if ($entry->key) {
                    $key_type = self::resolve($classlikes, $entry->key, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
                    if (!$key_type instanceof T_Literal_Int || $key_type->value !== $auto_key) {
                        $is_list = false;
                    }
                } else {
                    $key_type = new T_Literal_Int($auto_key);
                }
                if ($key_type instanceof T_Literal_Int || $key_type instanceof T_Literal_String) {
                    $key_value = $key_type->value;
                    if ($key_type instanceof T_Literal_Int) {
                        $auto_key = $key_type->value + 1;
                    } elseif (ctype_digit($key_type->value)) {
                        $auto_key = (int) $key_type->value + 1;
                    }
                } else {
                    return Type::get_array_atomic();
                }
                $value_type = new Union([self::resolve($classlikes, $entry->value, $statements_analyzer, $visited_constant_ids + [$c_id => true])]);
                $properties[$key_value] = $value_type;
            }
            if (empty($properties)) {
                return Type::get_empty_array_atomic();
            }
            return new T_Keyed_Array($properties, null, null, $is_list);
        }
        if ($c instanceof Class_Constant) {
            if ($c->name === 'class') {
                return new T_Literal_Class_String($c->fqcln);
            }
            $found_type = $classlikes->get_class_constant_type($c->fqcln, $c->name, ReflectionProperty::IS_PRIVATE, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            if ($found_type) {
                return $found_type->get_single_atomic();
            }
        }
        if ($c instanceof Array_Offset_Fetch) {
            $var_type = self::resolve($classlikes, $c->array, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            $offset_type = self::resolve($classlikes, $c->offset, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
            if ($var_type instanceof T_Keyed_Array && ($offset_type instanceof T_Literal_Int || $offset_type instanceof T_Literal_String)) {
                $union = $var_type->properties[$offset_type->value] ?? null;
                if ($union && $union->is_single()) {
                    return $union->get_single_atomic();
                }
            }
        }
        if ($c instanceof Constant) {
            if ($statements_analyzer) {
                $found_type = Const_Fetch_Analyzer::get_const_type($statements_analyzer, $c->name, $c->is_fully_qualified, null);
                if ($found_type) {
                    return $found_type->get_single_atomic();
                }
            }
        }
        if ($c instanceof Enum_Property_Fetch) {
            if ($classlikes->enum_exists($c->fqcln)) {
                $enum_storage = $classlikes->get_storage_for($c->fqcln);
                if (isset($enum_storage->enum_cases[$c->case])) {
                    if ($c instanceof Enum_Value_Fetch) {
                        $value = $enum_storage->enum_cases[$c->case]->value;
                        if ($value !== null) {
                            if ($value instanceof Unresolved_Constant_Component) {
                                return self::resolve($classlikes, $value, $statements_analyzer, $visited_constant_ids + [$c_id => true]);
                            }
                            return $value;
                        }
                    } elseif ($c instanceof Enum_Name_Fetch) {
                        return Type::get_string($c->case)->get_single_atomic();
                    }
                }
            }
        }
        return new T_Mixed();
    }
    /**
     * Note: This takes an array, but any array should only contain other arrays and scalars.
     */
    public static function get_literal_type_from_scalar_value(array|string|int|float|bool|Unit_Enum|null $value): Atomic
    {
        if ($value instanceof Unit_Enum) {
            return new T_Enum_Case($value::class, $value->name);
        }
        if (is_array($value)) {
            if (empty($value)) {
                return Type::get_empty_array_atomic();
            }
            $types = [];
            /** @var array|scalar|null $val */
            foreach ($value as $key => $val) {
                $types[$key] = new Union([self::get_literal_type_from_scalar_value($val)]);
            }
            return new T_Keyed_Array($types);
        }
        if (is_string($value)) {
            return Type::get_atomic_string_from_literal($value);
        }
        if (is_int($value)) {
            return new T_Literal_Int($value);
        }
        if (is_float($value)) {
            return new T_Literal_Float($value);
        }
        if ($value === false) {
            return new T_False();
        }
        if ($value === true) {
            return new T_True();
        }
        return new T_Null();
    }
}