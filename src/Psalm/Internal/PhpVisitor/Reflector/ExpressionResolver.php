<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Php_Parser;
use Php_Parser\Const_Expr_Evaluation_Exception;
use Php_Parser\Const_Expr_Evaluator;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Const_Fetch;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Offset_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Spread;
use Psalm\Internal\Scanner\Unresolved_Constant\Array_Value;
use Psalm\Internal\Scanner\Unresolved_Constant\Class_Constant;
use Psalm\Internal\Scanner\Unresolved_Constant\Constant;
use Psalm\Internal\Scanner\Unresolved_Constant\Enum_Name_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Enum_Value_Fetch;
use Psalm\Internal\Scanner\Unresolved_Constant\Key_Value_Pair;
use Psalm\Internal\Scanner\Unresolved_Constant\Scalar_Value;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Addition_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_And;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_Or;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Bitwise_Xor;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Concat_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Division_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Multiplication_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Subtraction_Op;
use Psalm\Internal\Scanner\Unresolved_Constant\Unresolved_Ternary;
use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use ReflectionClass;
use ReflectionFunction;
use function array_merge;
use function array_values;
use function assert;
use function class_exists;
use function function_exists;
use function get_defined_constants;
use function in_array;
use function interface_exists;
use function strtolower;
/**
 * @internal
 */
final class Expression_Resolver
{
    public static function get_unresolved_class_const_expr(Php_Parser\Node\Expr $stmt, Aliases $aliases, ?string $fq_classlike_name, ?string $parent_fq_class_name = null): ?Unresolved_Constant_Component
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op) {
            $left = self::get_unresolved_class_const_expr($stmt->left, $aliases, $fq_classlike_name, $parent_fq_class_name);
            $right = self::get_unresolved_class_const_expr($stmt->right, $aliases, $fq_classlike_name, $parent_fq_class_name);
            if (!$left || !$right) {
                return null;
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Plus) {
                return new Unresolved_Addition_Op($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Minus) {
                return new Unresolved_Subtraction_Op($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Mul) {
                return new Unresolved_Multiplication_Op($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Div) {
                return new Unresolved_Division_Op($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
                return new Unresolved_Concat_Op($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or) {
                return new Unresolved_Bitwise_Or($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor) {
                return new Unresolved_Bitwise_Xor($left, $right);
            }
            if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And) {
                return new Unresolved_Bitwise_And($left, $right);
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Ternary) {
            $cond = self::get_unresolved_class_const_expr($stmt->cond, $aliases, $fq_classlike_name, $parent_fq_class_name);
            $if = null;
            if ($stmt->if) {
                $if = self::get_unresolved_class_const_expr($stmt->if, $aliases, $fq_classlike_name, $parent_fq_class_name);
                if ($if === null) {
                    $if = false;
                }
            }
            $else = self::get_unresolved_class_const_expr($stmt->else, $aliases, $fq_classlike_name, $parent_fq_class_name);
            if ($cond && $else && $if !== false) {
                return new Unresolved_Ternary($cond, $if, $else);
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Const_Fetch) {
            $part0_lc = strtolower($stmt->name->get_first());
            if ($part0_lc === 'false') {
                return new Scalar_Value(false);
            }
            if ($part0_lc === 'true') {
                return new Scalar_Value(true);
            }
            if ($part0_lc === 'null') {
                return new Scalar_Value(null);
            }
            if ($part0_lc === '__namespace__') {
                return new Scalar_Value($aliases->namespace);
            }
            return new Constant($stmt->name->to_string(), $stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Namespace_) {
            return new Scalar_Value($aliases->namespace);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch && $stmt->dim) {
            $left = self::get_unresolved_class_const_expr($stmt->var, $aliases, $fq_classlike_name, $parent_fq_class_name);
            $right = self::get_unresolved_class_const_expr($stmt->dim, $aliases, $fq_classlike_name, $parent_fq_class_name);
            if ($left && $right) {
                return new Array_Offset_Fetch($left, $right);
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Class_Const_Fetch) {
            if ($stmt->class instanceof Php_Parser\Node\Name && $stmt->name instanceof Php_Parser\Node\Identifier && $fq_classlike_name && $stmt->class->get_parts() !== ['static'] && ($stmt->class->get_parts() !== ['parent'] || $parent_fq_class_name !== null)) {
                if ($stmt->class->get_parts() === ['self']) {
                    $const_fq_class_name = $fq_classlike_name;
                } else if ($stmt->class->get_parts() === ['parent']) {
                    assert($parent_fq_class_name !== null);
                    $const_fq_class_name = $parent_fq_class_name;
                } else {
                    $const_fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $aliases);
                }
                return new Class_Constant($const_fq_class_name, $stmt->name->name);
            }
            return null;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\String_ || $stmt instanceof Php_Parser\Node\Scalar\Int_ || $stmt instanceof Php_Parser\Node\Scalar\Float_) {
            return new Scalar_Value($stmt->value);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Unary_Plus) {
            $right = self::get_unresolved_class_const_expr($stmt->expr, $aliases, $fq_classlike_name, $parent_fq_class_name);
            if (!$right) {
                return null;
            }
            return new Unresolved_Addition_Op(new Scalar_Value(0), $right);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Unary_Minus) {
            $right = self::get_unresolved_class_const_expr($stmt->expr, $aliases, $fq_classlike_name, $parent_fq_class_name);
            if (!$right) {
                return null;
            }
            return new Unresolved_Subtraction_Op(new Scalar_Value(0), $right);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_) {
            $items = [];
            foreach ($stmt->items as $item) {
                if ($item === null) {
                    return null;
                }
                if ($item->key) {
                    $item_key_type = self::get_unresolved_class_const_expr($item->key, $aliases, $fq_classlike_name, $parent_fq_class_name);
                    if (!$item_key_type) {
                        return null;
                    }
                } else {
                    $item_key_type = null;
                }
                $item_value_type = self::get_unresolved_class_const_expr($item->value, $aliases, $fq_classlike_name, $parent_fq_class_name);
                if (!$item_value_type) {
                    return null;
                }
                if ($item->unpack) {
                    $items[] = new Array_Spread($item_value_type);
                } else {
                    $items[] = new Key_Value_Pair($item_key_type, $item_value_type);
                }
            }
            return new Array_Value($items);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch && $stmt->var instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->var->class instanceof Php_Parser\Node\Name && $stmt->var->name instanceof Php_Parser\Node\Identifier && $stmt->name instanceof Php_Parser\Node\Identifier && in_array($stmt->name->name, ['name', 'value'], true) && ($stmt->var->class->get_parts() !== ['self'] || $fq_classlike_name !== null) && $stmt->var->class->get_parts() !== ['static'] && ($stmt->var->class->get_parts() !== ['parent'] || $parent_fq_class_name !== null)) {
            if ($stmt->var->class->get_parts() === ['self']) {
                assert($fq_classlike_name !== null);
                $enum_fq_class_name = $fq_classlike_name;
            } else if ($stmt->var->class->get_parts() === ['parent']) {
                assert($parent_fq_class_name !== null);
                $enum_fq_class_name = $parent_fq_class_name;
            } else {
                $enum_fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->var->class, $aliases);
            }
            if ($stmt->name->name === 'value') {
                return new Enum_Value_Fetch($enum_fq_class_name, $stmt->var->name->name);
            }
            return new Enum_Name_Fetch($enum_fq_class_name, $stmt->var->name->name);
        }
        return null;
    }
    public static function enter_conditional(Codebase $codebase, string $file_path, Php_Parser\Node\Expr $expr): ?bool
    {
        if ($expr instanceof Php_Parser\Node\Expr\Boolean_Not) {
            $enter_negated = self::enter_conditional($codebase, $file_path, $expr->expr);
            return $enter_negated === null ? null : !$enter_negated;
        }
        if ($expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And) {
            $enter_conditional_left = self::enter_conditional($codebase, $file_path, $expr->left);
            $enter_conditional_right = self::enter_conditional($codebase, $file_path, $expr->right);
            return $enter_conditional_left !== false && $enter_conditional_right !== false;
        }
        if ($expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
            $enter_conditional_left = self::enter_conditional($codebase, $file_path, $expr->left);
            $enter_conditional_right = self::enter_conditional($codebase, $file_path, $expr->right);
            return $enter_conditional_left !== false || $enter_conditional_right !== false;
        }
        if ($codebase->register_autoload_files) {
            if (($expr instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal || $expr instanceof Php_Parser\Node\Expr\Binary_Op\Greater || $expr instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal || $expr instanceof Php_Parser\Node\Expr\Binary_Op\Smaller) && ($expr->left instanceof Php_Parser\Node\Expr\Const_Fetch && $expr->left->name->get_parts() === ['PHP_VERSION_ID'] && $expr->right instanceof Php_Parser\Node\Scalar\Int_ || $expr->right instanceof Php_Parser\Node\Expr\Const_Fetch && $expr->right->name->get_parts() === ['PHP_VERSION_ID'] && $expr->left instanceof Php_Parser\Node\Scalar\Int_)) {
                $php_version_id = $codebase->analysis_php_version_id;
                $evaluator = new Const_Expr_Evaluator(static function (Expr $expr) use ($php_version_id): int {
                    if ($expr instanceof Const_Fetch && $expr->name->get_parts() === ['PHP_VERSION_ID']) {
                        return $php_version_id;
                    }
                    throw new Const_Expr_Evaluation_Exception('unexpected');
                });
                try {
                    return (bool) $evaluator->evaluate_silently($expr);
                } catch (Const_Expr_Evaluation_Exception) {
                    return null;
                }
            }
        }
        if (!$expr instanceof Php_Parser\Node\Expr\Func_Call) {
            return null;
        }
        return self::function_evaluates_to_true($codebase, $file_path, $expr);
    }
    private static function function_evaluates_to_true(Codebase $codebase, string $file_path, Php_Parser\Node\Expr\Func_Call $function): ?bool
    {
        if (!$function->name instanceof Php_Parser\Node\Name) {
            return null;
        }
        if ($function->name->get_parts() === ['function_exists'] && isset($function->get_args()[0]) && $function->get_args()[0]->value instanceof Php_Parser\Node\Scalar\String_ && function_exists($function->get_args()[0]->value->value)) {
            $reflection_function = new ReflectionFunction($function->get_args()[0]->value->value);
            if ($reflection_function->is_internal()) {
                return true;
            }
            return false;
        }
        if ($function->name->get_parts() === ['class_exists'] && isset($function->get_args()[0])) {
            $string_value = null;
            if ($function->get_args()[0]->value instanceof Php_Parser\Node\Scalar\String_) {
                $string_value = $function->get_args()[0]->value->value;
            } elseif ($function->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $function->get_args()[0]->value->class instanceof Php_Parser\Node\Name && $function->get_args()[0]->value->name instanceof Php_Parser\Node\Identifier && strtolower($function->get_args()[0]->value->name->name) === 'class') {
                $string_value = (string) $function->get_args()[0]->value->class->get_attribute('resolvedName');
            }
            if ($string_value && class_exists($string_value)) {
                $reflection_class = new ReflectionClass($string_value);
                if ($reflection_class->get_file_name() !== $file_path) {
                    $codebase->scanner->queue_class_like_for_scanning($string_value);
                    return true;
                }
            }
            return false;
        }
        if ($function->name->get_parts() === ['interface_exists'] && isset($function->get_args()[0])) {
            $string_value = null;
            if ($function->get_args()[0]->value instanceof Php_Parser\Node\Scalar\String_) {
                $string_value = $function->get_args()[0]->value->value;
            } elseif ($function->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $function->get_args()[0]->value->class instanceof Php_Parser\Node\Name && $function->get_args()[0]->value->name instanceof Php_Parser\Node\Identifier && strtolower($function->get_args()[0]->value->name->name) === 'class') {
                $string_value = (string) $function->get_args()[0]->value->class->get_attribute('resolvedName');
            }
            if ($string_value && interface_exists($string_value)) {
                $reflection_class = new ReflectionClass($string_value);
                if ($reflection_class->get_file_name() !== $file_path) {
                    $codebase->scanner->queue_class_like_for_scanning($string_value);
                    return true;
                }
            }
            return false;
        }
        if ($function->name->get_parts() === ['enum_exists'] && isset($function->get_args()[0])) {
            $string_value = null;
            if ($function->get_args()[0]->value instanceof Php_Parser\Node\Scalar\String_) {
                $string_value = $function->get_args()[0]->value->value;
            } elseif ($function->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $function->get_args()[0]->value->class instanceof Php_Parser\Node\Name && $function->get_args()[0]->value->name instanceof Php_Parser\Node\Identifier && strtolower($function->get_args()[0]->value->name->name) === 'class') {
                $string_value = (string) $function->get_args()[0]->value->class->get_attribute('resolvedName');
            }
            // We're using class_exists here because enum_exists doesn't exist on old versions of PHP
            // Not sure what happens if we try to autoload or reflect on an enum on an old version of PHP though...
            if ($string_value && class_exists($string_value)) {
                $reflection_class = new ReflectionClass($string_value);
                if ($reflection_class->get_file_name() !== $file_path) {
                    $codebase->scanner->queue_class_like_for_scanning($string_value);
                    return true;
                }
            }
            return false;
        }
        if ($function->name->get_parts() === ['defined'] && isset($function->get_args()[0]) && $function->get_args()[0]->value instanceof Php_Parser\Node\Scalar\String_) {
            $predefined_constants = get_defined_constants(true);
            if (isset($predefined_constants['user'])) {
                unset($predefined_constants['user']);
            }
            $predefined_constants = array_merge(...array_values($predefined_constants));
            return isset($predefined_constants[$function->get_args()[0]->value->value]);
        }
        return null;
    }
}