<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Config;
use Psalm\File_Source;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use function count;
use function implode;
use function in_array;
use function is_string;
use function strtolower;
/**
 * @internal
 */
final class Expression_Identifier
{
    public static function get_var_id(Php_Parser\Node\Expr $stmt, ?string $this_class_name, ?File_Source $source = null, ?int &$nesting = null): ?string
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->name)) {
            return '$' . $stmt->name;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Static_Property_Fetch && $stmt->name instanceof Php_Parser\Node\Identifier && $stmt->class instanceof Php_Parser\Node\Name) {
            if (count($stmt->class->get_parts()) === 1 && in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
                if (!$this_class_name) {
                    $fq_class_name = $stmt->class->get_first();
                } else {
                    $fq_class_name = $this_class_name;
                }
            } else {
                $fq_class_name = $source ? Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $source->get_aliases()) : implode('\\', $stmt->class->get_parts());
            }
            return $fq_class_name . '::$' . $stmt->name->name;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch && $stmt->name instanceof Php_Parser\Node\Identifier) {
            $object_id = self::get_var_id($stmt->var, $this_class_name, $source);
            if (!$object_id) {
                return null;
            }
            return $object_id . '->' . $stmt->name->name;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch && $nesting !== null) {
            ++$nesting;
            return self::get_var_id($stmt->var, $this_class_name, $source, $nesting);
        }
        return null;
    }
    public static function get_root_var_id(Php_Parser\Node\Expr $stmt, ?string $this_class_name, ?File_Source $source = null): ?string
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Variable || $stmt instanceof Php_Parser\Node\Expr\Static_Property_Fetch) {
            return self::get_var_id($stmt, $this_class_name, $source);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch && $stmt->name instanceof Php_Parser\Node\Identifier) {
            $property_root = self::get_root_var_id($stmt->var, $this_class_name, $source);
            if ($property_root) {
                return $property_root . '->' . $stmt->name->name;
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            return self::get_root_var_id($stmt->var, $this_class_name, $source);
        }
        return null;
    }
    public static function get_extended_var_id(Php_Parser\Node\Expr $stmt, ?string $this_class_name, ?File_Source $source = null): ?string
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Assign) {
            return self::get_extended_var_id($stmt->var, $this_class_name, $source);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            $root_var_id = self::get_extended_var_id($stmt->var, $this_class_name, $source);
            $offset = null;
            if ($root_var_id) {
                if ($stmt->dim instanceof Php_Parser\Node\Scalar\String_ || $stmt->dim instanceof Php_Parser\Node\Scalar\Int_) {
                    $string_to_int = Array_Analyzer::get_literal_array_key_int($stmt->dim->value);
                    $offset = $string_to_int === false ? '\'' . $stmt->dim->value . '\'' : (int) $stmt->dim->value;
                } elseif ($stmt->dim instanceof Php_Parser\Node\Expr\Variable && is_string($stmt->dim->name)) {
                    $offset = '$' . $stmt->dim->name;
                } elseif ($stmt->dim instanceof Php_Parser\Node\Expr\Const_Fetch) {
                    $offset = implode('\\', $stmt->dim->name->get_parts());
                } elseif ($stmt->dim instanceof Php_Parser\Node\Expr\Property_Fetch) {
                    $object_id = self::get_extended_var_id($stmt->dim->var, $this_class_name, $source);
                    if ($object_id && $stmt->dim->name instanceof Php_Parser\Node\Identifier) {
                        $offset = $object_id . '->' . $stmt->dim->name;
                    }
                } elseif ($stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->dim->name instanceof Php_Parser\Node\Identifier && $stmt->dim->class instanceof Php_Parser\Node\Name && $stmt->dim->class->get_first() === 'static') {
                    $offset = 'static::' . $stmt->dim->name;
                } elseif ($stmt->dim && $source instanceof Statements_Analyzer && ($stmt_dim_type = $source->node_data->get_type($stmt->dim)) && (!$stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch || !$stmt->dim->name instanceof Php_Parser\Node\Identifier || $stmt->dim->name->name !== 'class')) {
                    if ($stmt_dim_type->is_single_string_literal()) {
                        $string_to_int = Array_Analyzer::get_literal_array_key_int($stmt_dim_type->get_single_string_literal()->value);
                        $offset = $string_to_int === false ? '\'' . $stmt_dim_type->get_single_string_literal()->value . '\'' : (int) $stmt_dim_type->get_single_string_literal()->value;
                    } elseif ($stmt_dim_type->is_single_int_literal()) {
                        $offset = $stmt_dim_type->get_single_int_literal()->value;
                    }
                } elseif ($stmt->dim instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->dim->name instanceof Php_Parser\Node\Identifier) {
                    /** @var string|null */
                    $resolved_name = $stmt->dim->class->get_attribute('resolvedName');
                    if ($resolved_name) {
                        $offset = $resolved_name . '::' . $stmt->dim->name;
                    }
                }
                return $offset !== null ? $root_var_id . '[' . $offset . ']' : null;
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch) {
            $object_id = self::get_extended_var_id($stmt->var, $this_class_name, $source);
            if (!$object_id) {
                return null;
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                return $object_id . '->' . $stmt->name;
            }
            if ($source instanceof Statements_Analyzer && ($stmt_name_type = $source->node_data->get_type($stmt->name)) && $stmt_name_type->is_single_string_literal()) {
                return $object_id . '->' . $stmt_name_type->get_single_string_literal()->value;
            }
            return null;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $stmt->name instanceof Php_Parser\Node\Identifier) {
            /** @var string|null */
            $resolved_name = $stmt->class->get_attribute('resolvedName');
            if ($resolved_name) {
                if (($resolved_name === 'self' || $resolved_name === 'static') && $this_class_name) {
                    $resolved_name = $this_class_name;
                }
                return $resolved_name . '::' . $stmt->name;
            }
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Method_Call && $stmt->name instanceof Php_Parser\Node\Identifier && !$stmt->is_first_class_callable() && !$stmt->get_args()) {
            $config = Config::get_instance();
            if ($config->memoize_method_calls || $stmt->get_attribute('memoizable', false)) {
                $lhs_var_name = self::get_extended_var_id($stmt->var, $this_class_name, $source);
                if (!$lhs_var_name) {
                    return null;
                }
                return $lhs_var_name . '->' . strtolower($stmt->name->name) . '()';
            }
        }
        return self::get_var_id($stmt, $this_class_name, $source);
    }
}