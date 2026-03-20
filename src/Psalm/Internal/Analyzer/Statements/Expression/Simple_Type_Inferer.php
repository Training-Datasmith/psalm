<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use InvalidArgumentException;
use Php_Parser;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Exception\Circular_Reference_Exception;
use Psalm\File_Source;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op\Arithmetic_Op_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Union;
use ReflectionProperty;
use function array_merge;
use function array_values;
use function count;
use function is_string;
use function strtolower;
use const PHP_INT_MAX;
/**
 * This class takes a statement and return its type by analyzing each part of the statement if necessary
 *
 * @internal
 */
final class Simple_Type_Inferer
{
    /**
     * @param   ?array<string, ClassConstantStorage> $existing_class_constants
     */
    public static function infer(Codebase $codebase, Node_Data_Provider $nodes, Php_Parser\Node\Expr $stmt, Aliases $aliases, ?File_Source $file_source = null, ?array $existing_class_constants = null, ?string $fq_classlike_name = null): ?Union
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op) {
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
                $left = self::infer($codebase, $nodes, $stmt->left, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
                $right = self::infer($codebase, $nodes, $stmt->right, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
                if ($left && $right) {
                    if ($left->is_single_string_literal() && $right->is_single_string_literal()) {
                        $result = $left->get_single_string_literal()->value . $right->get_single_string_literal()->value;
                        return Type::get_string($result);
                    }
                    if ($left->is_non_empty_string()) {
                        return new Union([new T_Non_Empty_String()]);
                    }
                    if ($right->is_non_empty_string()) {
                        return new Union([new T_Non_Empty_String()]);
                    }
                }
                return Type::get_string();
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_And || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal) {
                return Type::get_bool();
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Coalesce) {
                return null;
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Spaceship) {
                return new Union([new T_Literal_Int(-1), new T_Literal_Int(0), new T_Literal_Int(1)]);
            }
            $stmt_left_type = self::infer($codebase, $nodes, $stmt->left, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if (!$stmt_left_type && $file_source instanceof Statements_Analyzer && $stmt->left instanceof Php_Parser\Node\Expr\Const_Fetch) {
                $stmt_left_type = Const_Fetch_Analyzer::get_const_type($file_source, $stmt->left->name->to_string(), true, null);
            }
            $stmt_right_type = self::infer($codebase, $nodes, $stmt->right, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if (!$stmt_right_type && $file_source instanceof Statements_Analyzer && $stmt->right instanceof Php_Parser\Node\Expr\Const_Fetch) {
                $stmt_right_type = Const_Fetch_Analyzer::get_const_type($file_source, $stmt->right->name->to_string(), true, null);
            }
            if (!$stmt_left_type || !$stmt_right_type) {
                return null;
            }
            $nodes->set_type($stmt->left, $stmt_left_type);
            $nodes->set_type($stmt->right, $stmt_right_type);
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Plus || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Minus || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Mod || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Mul || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Pow || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Right || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Left || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or || $stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And) {
                Arithmetic_Op_Analyzer::analyze($file_source instanceof Statements_Source ? $file_source : null, $nodes, $stmt->left, $stmt->right, $stmt, $result_type);
                if ($result_type) {
                    return $result_type;
                }
                return null;
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Div && ($stmt_left_type->has_int() || $stmt_left_type->has_float()) && ($stmt_right_type->has_int() || $stmt_right_type->has_float())) {
                return Type::combine_union_types(Type::get_float(), Type::get_int());
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Bitwise_Not) {
            $stmt_expr_type = self::infer($codebase, $nodes, $stmt->expr, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if ($stmt_expr_type === null) {
                return null;
            }
            $invalid_types = $stmt_expr_type->get_builder();
            $invalid_types->remove_type('string');
            $invalid_types->remove_type('int');
            $invalid_types->remove_type('float');
            if (!$invalid_types->is_union_empty()) {
                return null;
            }
            $types = [];
            if ($stmt_expr_type->has_string()) {
                $types[] = Type::get_string();
            }
            if ($stmt_expr_type->has_int() || $stmt_expr_type->has_float()) {
                $types[] = Type::get_int();
            }
            return $types ? Type::combine_union_type_array($types, null) : null;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
            $stmt_expr_type = self::infer($codebase, $nodes, $stmt->expr, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if ($stmt_expr_type === null) {
                return null;
            }
            if ($stmt_expr_type->is_always_falsy()) {
                return Type::get_true();
            }
            if ($stmt_expr_type->is_always_truthy()) {
                return Type::get_false();
            }
            return Type::get_bool();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Const_Fetch) {
            $name = $stmt->name->get_first();
            $name_lowercase = strtolower($name);
            if ($name_lowercase === 'false') {
                return Type::get_false();
            }
            if ($name_lowercase === 'true') {
                return Type::get_true();
            }
            if ($name_lowercase === 'null') {
                return Type::get_null();
            }
            if ($name === '__NAMESPACE__') {
                return Type::get_string($aliases->namespace);
            }
            if ($type = Const_Fetch_Analyzer::get_global_const_type($codebase, $name, $name)) {
                return $type;
            }
            return null;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Dir || $stmt instanceof Php_Parser\Node\Scalar\Magic_Const\File) {
            return new Union([new T_Non_Empty_String()]);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Line) {
            return Type::get_int_range(1, null);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Class_ || $stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Method || $stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Trait_ || $stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Function_) {
            return Type::get_string();
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Namespace_) {
            return Type::get_string($aliases->namespace);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Class_Const_Fetch) {
            if ($stmt->class instanceof Php_Parser\Node\Name && $stmt->name instanceof Php_Parser\Node\Identifier && $fq_classlike_name && $stmt->class->get_parts() !== ['static'] && $stmt->class->get_parts() !== ['parent']) {
                if (isset($existing_class_constants[$stmt->name->name]) && $existing_class_constants[$stmt->name->name]->type) {
                    if ($stmt->class->get_parts() === ['self']) {
                        return $existing_class_constants[$stmt->name->name]->type;
                    }
                }
                if ($stmt->class->get_parts() === ['self']) {
                    $const_fq_class_name = $fq_classlike_name;
                } else {
                    $const_fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $aliases);
                }
                if (strtolower($const_fq_class_name) === strtolower($fq_classlike_name) && isset($existing_class_constants[$stmt->name->name]) && $existing_class_constants[$stmt->name->name]->type) {
                    return $existing_class_constants[$stmt->name->name]->type;
                }
                if (strtolower($stmt->name->name) === 'class') {
                    return Type::get_literal_class_string($const_fq_class_name, true);
                }
                if ($existing_class_constants === null || $existing_class_constants === [] && $file_source !== null) {
                    try {
                        $foreign_class_constant = $codebase->classlikes->get_class_constant_type($const_fq_class_name, $stmt->name->name, ReflectionProperty::IS_PRIVATE, $file_source instanceof Statements_Analyzer ? $file_source : null);
                        if ($foreign_class_constant) {
                            return $foreign_class_constant;
                        }
                        return null;
                    } catch (InvalidArgumentException|Circular_Reference_Exception) {
                        return null;
                    }
                }
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier && strtolower($stmt->name->name) === 'class') {
                return Type::get_class_string();
            }
            return null;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\String_) {
            return Type::get_string($stmt->value);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Int_) {
            return Type::get_int(false, $stmt->value);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Float_) {
            return Type::get_float($stmt->value);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_) {
            return self::infer_array_type($codebase, $nodes, $stmt, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Int_) {
            return Type::get_int();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Double) {
            return Type::get_float();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Bool_) {
            return Type::get_bool();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\String_) {
            return Type::get_string();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Object_) {
            return Type::get_object();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Array_) {
            return Type::get_array();
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Unary_Minus || $stmt instanceof Php_Parser\Node\Expr\Unary_Plus) {
            $type_to_invert = self::infer($codebase, $nodes, $stmt->expr, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if (!$type_to_invert) {
                return null;
            }
            $new_types = [];
            foreach ($type_to_invert->get_atomic_types() as $type_part) {
                if ($type_part instanceof T_Literal_Int && $stmt instanceof Php_Parser\Node\Expr\Unary_Minus) {
                    $new_types[] = new T_Literal_Int(-$type_part->value);
                } elseif ($type_part instanceof T_Literal_Float && $stmt instanceof Php_Parser\Node\Expr\Unary_Minus) {
                    $new_types[] = new T_Literal_Float(-$type_part->value);
                } else {
                    $new_types[] = $type_part;
                }
            }
            return new Union($new_types);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            if ($stmt->var instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->dim) {
                $array_type = self::infer($codebase, $nodes, $stmt->var, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
                $dim_type = self::infer($codebase, $nodes, $stmt->dim, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
                if ($array_type !== null && $dim_type !== null) {
                    if ($dim_type->is_single_string_literal()) {
                        $dim_value = $dim_type->get_single_string_literal()->value;
                    } elseif ($dim_type->is_single_int_literal()) {
                        $dim_value = $dim_type->get_single_int_literal()->value;
                    } else {
                        return null;
                    }
                    foreach ($array_type->get_atomic_types() as $array_atomic_type) {
                        if ($array_atomic_type instanceof T_Keyed_Array) {
                            return $array_atomic_type->properties[$dim_value] ?? null;
                        }
                    }
                }
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\New_) {
            $resolved_class_name = $stmt->class->get_attribute('resolvedName');
            if (!is_string($resolved_class_name)) {
                return null;
            }
            return new Union([new Type\Atomic\T_Named_Object($resolved_class_name)]);
        }
        return null;
    }
    /**
     * @param   ?array<string, ClassConstantStorage> $existing_class_constants
     */
    private static function infer_array_type(Codebase $codebase, Node_Data_Provider $nodes, Php_Parser\Node\Expr\Array_ $stmt, Aliases $aliases, ?File_Source $file_source = null, ?array $existing_class_constants = null, ?string $fq_classlike_name = null): ?Union
    {
        if (count($stmt->items) === 0) {
            return Type::get_empty_array();
        }
        $array_creation_info = new Array_Creation_Info();
        foreach ($stmt->items as $item) {
            if ($item === null) {
                continue;
            }
            if (!self::handle_array_item($codebase, $nodes, $array_creation_info, $item, $aliases, $file_source, $existing_class_constants, $fq_classlike_name)) {
                return null;
            }
        }
        $item_key_type = null;
        if ($array_creation_info->item_key_atomic_types) {
            $item_key_type = Type_Combiner::combine($array_creation_info->item_key_atomic_types);
        }
        $item_value_type = null;
        if ($array_creation_info->item_value_atomic_types) {
            $item_value_type = Type_Combiner::combine($array_creation_info->item_value_atomic_types);
        }
        // if this array looks like an object-like array, let's return that instead
        if ($item_value_type && $item_key_type && ($item_key_type->has_string() || $item_key_type->has_int()) && $array_creation_info->can_create_objectlike && $array_creation_info->property_types) {
            $objectlike = new T_Keyed_Array($array_creation_info->property_types, $array_creation_info->class_strings, null, $array_creation_info->all_list);
            return new Union([$objectlike]);
        }
        if (!$item_key_type || !$item_value_type) {
            return null;
        }
        if ($array_creation_info->all_list) {
            return Type::get_non_empty_list($item_value_type);
        }
        return new Union([new T_Non_Empty_Array([$item_key_type, $item_value_type])]);
    }
    /**
     * @param   ?array<string, ClassConstantStorage> $existing_class_constants
     */
    private static function handle_array_item(Codebase $codebase, Node_Data_Provider $nodes, Array_Creation_Info $array_creation_info, Php_Parser\Node\Array_Item $item, Aliases $aliases, ?File_Source $file_source = null, ?array $existing_class_constants = null, ?string $fq_classlike_name = null): bool
    {
        if ($item->unpack) {
            $unpacked_array_type = self::infer($codebase, $nodes, $item->value, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if (!$unpacked_array_type) {
                return false;
            }
            return self::handle_unpacked_array($array_creation_info, $unpacked_array_type);
        }
        $single_item_key_type = null;
        $item_is_list_item = false;
        $item_key_value = null;
        if ($item->key) {
            $single_item_key_type = self::infer($codebase, $nodes, $item->key, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
            if ($single_item_key_type) {
                $key_type = $single_item_key_type;
                if ($key_type->is_null()) {
                    $key_type = Type::get_string('');
                }
                if ($item->key instanceof Php_Parser\Node\Scalar\String_ && Array_Analyzer::get_literal_array_key_int($item->key->value) !== false) {
                    $key_type = Type::get_int(false, (int) $item->key->value);
                }
                $array_creation_info->item_key_atomic_types = array_merge($array_creation_info->item_key_atomic_types, array_values($key_type->get_atomic_types()));
                if ($key_type->is_single_string_literal()) {
                    $item_key_literal_type = $key_type->get_single_string_literal();
                    $string_to_int = Array_Analyzer::get_literal_array_key_int($item_key_literal_type->value);
                    $item_key_value = $string_to_int === false ? $item_key_literal_type->value : $string_to_int;
                    if (is_string($item_key_value) && $item_key_literal_type instanceof T_Literal_Class_String) {
                        $array_creation_info->class_strings[$item_key_value] = true;
                    }
                } elseif ($key_type->is_single_int_literal()) {
                    $item_key_value = $key_type->get_single_int_literal()->value;
                    if ($item_key_value <= PHP_INT_MAX && $item_key_value > $array_creation_info->int_offset) {
                        if ($item_key_value - 1 === $array_creation_info->int_offset) {
                            $item_is_list_item = true;
                        }
                        $array_creation_info->int_offset = $item_key_value;
                    }
                }
            }
        } else {
            if ($array_creation_info->int_offset === PHP_INT_MAX) {
                return false;
            }
            $item_is_list_item = true;
            $item_key_value = ++$array_creation_info->int_offset;
            $array_creation_info->item_key_atomic_types[] = new T_Literal_Int($item_key_value);
        }
        $single_item_value_type = self::infer($codebase, $nodes, $item->value, $aliases, $file_source, $existing_class_constants, $fq_classlike_name);
        if (!$single_item_value_type) {
            return false;
        }
        $config = $codebase->config;
        $array_creation_info->all_list = $array_creation_info->all_list && $item_is_list_item;
        if ($item->key instanceof Php_Parser\Node\Scalar\String_ || $item->key instanceof Php_Parser\Node\Scalar\Int_ || !$item->key) {
            if ($item_key_value !== null && count($array_creation_info->property_types) <= $config->max_shaped_array_size) {
                $array_creation_info->property_types[$item_key_value] = $single_item_value_type;
            } else {
                $array_creation_info->can_create_objectlike = false;
            }
        } else {
            $dim_type = $single_item_key_type;
            if (!$dim_type) {
                return false;
            }
            if (count($dim_type->get_atomic_types()) > 1 || $dim_type->has_mixed() || count($array_creation_info->property_types) > $config->max_shaped_array_size) {
                $array_creation_info->can_create_objectlike = false;
            } else {
                $atomic_type = $dim_type->get_single_atomic();
                if ($atomic_type instanceof T_Literal_Int || $atomic_type instanceof T_Literal_String) {
                    if ($atomic_type instanceof T_Literal_Class_String) {
                        $array_creation_info->class_strings[$atomic_type->value] = true;
                    }
                    $array_creation_info->property_types[$atomic_type->value] = $single_item_value_type;
                } else {
                    $array_creation_info->can_create_objectlike = false;
                }
            }
        }
        $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values($single_item_value_type->get_atomic_types()));
        return true;
    }
    private static function handle_unpacked_array(Array_Creation_Info $array_creation_info, Union $unpacked_array_type): bool
    {
        foreach ($unpacked_array_type->get_atomic_types() as $unpacked_atomic_type) {
            if ($unpacked_atomic_type instanceof T_Keyed_Array) {
                foreach ($unpacked_atomic_type->properties as $key => $property_value) {
                    if (is_string($key)) {
                        $new_offset = $key;
                        $array_creation_info->item_key_atomic_types[] = Type::get_atomic_string_from_literal($new_offset);
                    } else {
                        if ($array_creation_info->int_offset === PHP_INT_MAX) {
                            return false;
                        }
                        $new_offset = ++$array_creation_info->int_offset;
                        $array_creation_info->item_key_atomic_types[] = new T_Literal_Int($new_offset);
                    }
                    $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values($property_value->get_atomic_types()));
                    $array_creation_info->array_keys[$new_offset] = true;
                    $array_creation_info->property_types[$new_offset] = $property_value;
                }
                if ($unpacked_atomic_type->fallback_params !== null) {
                    // Not sure if this is needed
                    //$array_creation_info->can_create_objectlike = false;
                    if ($unpacked_atomic_type->fallback_params[0]->has_string()) {
                        $array_creation_info->item_key_atomic_types[] = new T_String();
                    }
                    if ($unpacked_atomic_type->fallback_params[0]->has_int()) {
                        $array_creation_info->item_key_atomic_types[] = new T_Int();
                    }
                    $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values($unpacked_atomic_type->fallback_params[1]->get_atomic_types()));
                }
            } elseif ($unpacked_atomic_type instanceof T_Array) {
                if ($unpacked_atomic_type->is_empty_array()) {
                    continue;
                }
                $array_creation_info->can_create_objectlike = false;
                if ($unpacked_atomic_type->type_params[0]->has_string()) {
                    $array_creation_info->item_key_atomic_types[] = new T_String();
                }
                if ($unpacked_atomic_type->type_params[0]->has_int()) {
                    $array_creation_info->item_key_atomic_types[] = new T_Int();
                }
                $array_creation_info->item_value_atomic_types = array_merge($array_creation_info->item_value_atomic_types, array_values(isset($unpacked_atomic_type->type_params[1]) ? $unpacked_atomic_type->type_params[1]->get_atomic_types() : [new T_Mixed()]));
            }
        }
        return true;
    }
}