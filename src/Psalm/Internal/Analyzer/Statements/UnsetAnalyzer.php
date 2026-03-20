<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_Mixed;
use Psalm\Type\Union;
use function count;
use function is_int;
/**
 * @internal
 */
final class Unset_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Unset_ $stmt, Context $context): void
    {
        $context->inside_unset = true;
        foreach ($stmt->vars as $var) {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            Expression_Analyzer::analyze($statements_analyzer, $var, $context);
            $context->inside_general_use = $was_inside_general_use;
            $var_id = Expression_Identifier::get_extended_var_id($var, $statements_analyzer->get_fqcln(), $statements_analyzer);
            if ($var_id) {
                $context->remove($var_id);
                unset($context->references_possibly_from_confusing_scope[$var_id]);
            }
            if ($var instanceof Php_Parser\Node\Expr\Array_Dim_Fetch && $var->dim) {
                $root_var_id = Expression_Identifier::get_extended_var_id($var->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
                $key_type = $statements_analyzer->node_data->get_type($var->dim);
                if ($root_var_id && isset($context->vars_in_scope[$root_var_id]) && $key_type) {
                    $root_types = [];
                    foreach ($context->vars_in_scope[$root_var_id]->get_atomic_types() as $atomic_root_type) {
                        if ($atomic_root_type instanceof T_Keyed_Array) {
                            $key_value = null;
                            if ($key_type->is_single_int_literal()) {
                                $key_value = $key_type->get_single_int_literal()->value;
                            } elseif ($key_type->is_single_string_literal()) {
                                $key_value = $key_type->get_single_string_literal()->value;
                            }
                            if ($key_value !== null) {
                                $properties = $atomic_root_type->properties;
                                $is_list = $atomic_root_type->is_list;
                                $list_key = null;
                                if ($atomic_root_type->fallback_params) {
                                    $is_list = false;
                                } elseif (isset($properties[$key_value])) {
                                    if ($is_list && $key_value !== count($properties) - 1) {
                                        $is_list = false;
                                    }
                                }
                                unset($properties[$key_value]);
                                if ($atomic_root_type->is_list && !$is_list && is_int($key_value)) {
                                    if ($key_value === 0) {
                                        $list_key = Type::get_int_range(1, null);
                                    } elseif ($key_value === 1) {
                                        $list_key = new Union([new T_Literal_Int(0), new T_Int_Range(2, null)]);
                                    } else {
                                        $list_key = new Union([new T_Int_Range(0, $key_value - 1), new T_Int_Range($key_value + 1, null)]);
                                    }
                                }
                                if (!$properties) {
                                    if ($atomic_root_type->fallback_params) {
                                        $root_types[] = new T_Array([$list_key ?? $atomic_root_type->fallback_params[0], $atomic_root_type->fallback_params[1]]);
                                    } else {
                                        $root_types[] = new T_Array([new Union([new T_Never()]), new Union([new T_Never()])]);
                                    }
                                } else {
                                    $root_types[] = new T_Keyed_Array($properties, null, $atomic_root_type->fallback_params ? [$list_key ?? $atomic_root_type->fallback_params[0], $atomic_root_type->fallback_params[1]] : null, $is_list);
                                }
                            } else {
                                $properties = [];
                                foreach ($atomic_root_type->properties as $key => $type) {
                                    $properties[$key] = $type->set_possibly_undefined(true);
                                }
                                $root_types[] = new T_Keyed_Array($properties, null, $atomic_root_type->fallback_params, false);
                            }
                        } elseif ($atomic_root_type instanceof T_Non_Empty_Array) {
                            $root_types[] = new T_Array($atomic_root_type->type_params);
                        } elseif ($atomic_root_type instanceof T_Non_Empty_Mixed) {
                            $root_types[] = new T_Mixed();
                        } else {
                            $root_types[] = $atomic_root_type;
                        }
                    }
                    $context->vars_in_scope[$root_var_id] = new Union($root_types);
                    $context->remove_var_from_conflicting_clauses($root_var_id, $context->vars_in_scope[$root_var_id], $statements_analyzer);
                }
            }
        }
        $context->inside_unset = false;
    }
}