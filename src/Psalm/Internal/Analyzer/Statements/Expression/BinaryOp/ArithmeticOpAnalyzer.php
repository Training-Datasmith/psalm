<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Array_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Issue\False_Operand;
use Psalm\Issue\Invalid_Operand;
use Psalm\Issue\Mixed_Operand;
use Psalm\Issue\Null_Operand;
use Psalm\Issue\Possibly_False_Operand;
use Psalm\Issue\Possibly_Invalid_Operand;
use Psalm\Issue\Possibly_Null_Operand;
use Psalm\Issue\String_Increment;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Minus;
use Psalm\Node\Expr\Binary_Op\Virtual_Plus;
use Psalm\Statements_Source;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_diff_key;
use function array_values;
use function count;
use function is_int;
use function is_numeric;
use function max;
use function min;
use function preg_match;
use function strtolower;
/**
 * @internal
 */
final class Arithmetic_Op_Analyzer
{
    public static function analyze(?Statements_Source $statements_source, Node_Data_Provider $nodes, Php_Parser\Node\Expr $left, Php_Parser\Node\Expr $right, Php_Parser\Node $parent, ?Union &$result_type = null, ?Context $context = null): void
    {
        $codebase = $statements_source ? $statements_source->get_codebase() : null;
        $left_type = $nodes->get_type($left);
        $right_type = $nodes->get_type($right);
        $config = Config::get_instance();
        if ($left_type && $left_type->is_never()) {
            $left_type = $right_type;
        } elseif ($right_type && $right_type->is_never()) {
            $right_type = $left_type;
        }
        if ($left_type && $right_type) {
            if ($left_type->is_null()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Null_Operand('Left operand cannot be null', new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
                $result_type = Type::get_mixed();
                return;
            }
            if ($left_type->is_nullable() && !$left_type->ignore_nullable_issues) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Possibly_Null_Operand('Left operand cannot be nullable, got ' . $left_type, new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
            }
            if ($right_type->is_null()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Null_Operand('Right operand cannot be null', new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
                $result_type = Type::get_mixed();
                return;
            }
            if ($right_type->is_nullable() && !$right_type->ignore_nullable_issues) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Possibly_Null_Operand('Right operand cannot be nullable, got ' . $right_type, new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
            }
            if ($left_type->is_false()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new False_Operand('Left operand cannot be false', new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
                return;
            }
            if ($left_type->is_falsable() && !$left_type->ignore_falsable_issues) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Possibly_False_Operand('Left operand cannot be falsable, got ' . $left_type, new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
            }
            if ($right_type->is_false()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new False_Operand('Right operand cannot be false', new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
                return;
            }
            if ($right_type->is_falsable() && !$right_type->ignore_falsable_issues) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Possibly_False_Operand('Right operand cannot be falsable, got ' . $right_type, new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
            }
            $invalid_left_messages = [];
            $invalid_right_messages = [];
            $has_valid_left_operand = false;
            $has_valid_right_operand = false;
            $has_string_increment = false;
            foreach ($left_type->get_atomic_types() as $left_type_part) {
                foreach ($right_type->get_atomic_types() as $right_type_part) {
                    $candidate_result_type = self::analyze_operands($statements_source, $codebase, $config, $context, $left, $right, $parent, $left_type_part, $right_type_part, $invalid_left_messages, $invalid_right_messages, $has_valid_left_operand, $has_valid_right_operand, $has_string_increment, $result_type);
                    if ($candidate_result_type) {
                        $result_type = $candidate_result_type;
                        return;
                    }
                }
            }
            if ($invalid_left_messages && $statements_source) {
                $first_left_message = $invalid_left_messages[0];
                if ($has_valid_left_operand) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Operand($first_left_message, new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Operand($first_left_message, new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
            }
            if ($invalid_right_messages && $statements_source) {
                $first_right_message = $invalid_right_messages[0];
                if ($has_valid_right_operand) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Operand($first_right_message, new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Operand($first_right_message, new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
            }
            if ($has_string_increment && $statements_source) {
                Issue_Buffer::maybe_add(new String_Increment('Possibly unintended string increment', new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
            }
        }
    }
    private static function get_numerical_type(int|float $result): Union
    {
        if (is_int($result)) {
            return Type::get_int(false, $result);
        }
        return Type::get_float($result);
    }
    /**
     * @param string[] $invalid_left_messages
     * @param string[] $invalid_right_messages
     * @psalm-suppress ComplexMethod Unavoidably complex method.
     */
    private static function analyze_operands(?Statements_Source $statements_source, ?Codebase $codebase, Config $config, ?Context $context, Php_Parser\Node\Expr $left, Php_Parser\Node\Expr $right, Php_Parser\Node $parent, Atomic $left_type_part, Atomic $right_type_part, array &$invalid_left_messages, array &$invalid_right_messages, bool &$has_valid_left_operand, bool &$has_valid_right_operand, bool &$has_string_increment, ?Union &$result_type = null): ?Union
    {
        if (($left_type_part instanceof T_Literal_Int || $left_type_part instanceof T_Literal_Float) && ($right_type_part instanceof T_Literal_Int || $right_type_part instanceof T_Literal_Float) && ($context === null || $context->inside_loop === false || !$left instanceof Php_Parser\Node\Expr\Variable && !$right instanceof Php_Parser\Node\Expr\Variable)) {
            // get_class is fine here because both classes are final.
            if ($statements_source !== null && $config->strict_binary_operands && $left_type_part::class !== $right_type_part::class) {
                Issue_Buffer::maybe_add(new Invalid_Operand('Cannot process numeric types together in strict operands mode, ' . 'please cast explicitly', new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
            }
            // time for some arithmetic!
            $calculated_type = self::arithmetic_operation($parent, $left_type_part->value, $right_type_part->value, true);
            if ($calculated_type) {
                $result_type = Type::combine_union_types($calculated_type, $result_type);
                $has_valid_left_operand = true;
                $has_valid_right_operand = true;
                return null;
            }
        }
        if ($left_type_part instanceof T_Null || $right_type_part instanceof T_Null) {
            // null case is handled above
            return null;
        }
        if ($left_type_part instanceof T_False || $right_type_part instanceof T_False) {
            // null case is handled above
            return null;
        }
        if ($left_type_part instanceof T_String && $right_type_part instanceof T_Int && ($parent instanceof Php_Parser\Node\Expr\Post_Inc || $parent instanceof Php_Parser\Node\Expr\Pre_Inc)) {
            if ($left_type_part instanceof T_Numeric_String || $left_type_part instanceof T_Literal_String && is_numeric($left_type_part->value)) {
                $new_result_type = new Union([new T_Float(), new T_Int()], ['from_calculation' => true]);
            } else {
                $new_result_type = Type::get_non_empty_string();
                $has_string_increment = true;
            }
            $result_type = Type::combine_union_types($new_result_type, $result_type);
            $has_valid_left_operand = true;
            $has_valid_right_operand = true;
            return null;
        }
        if ($left_type_part instanceof T_Template_Param && $right_type_part instanceof T_Template_Param) {
            $combined_type = Type::combine_union_types($left_type_part->as, $right_type_part->as);
            $combined_atomic_types = array_values($combined_type->get_atomic_types());
            if (count($combined_atomic_types) <= 2) {
                $left_type_part = $combined_atomic_types[0];
                $right_type_part = $combined_atomic_types[1] ?? $combined_atomic_types[0];
            }
        }
        if ($left_type_part instanceof T_Mixed || $right_type_part instanceof T_Mixed) {
            if ($statements_source && $codebase && $context) {
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_source->get_file_path() === $statements_source->get_root_file_path() && (!($source = $statements_source->get_source()) instanceof Function_Like_Analyzer || !$source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_source->get_file_path());
                }
            }
            if ($left_type_part instanceof T_Mixed) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Mixed_Operand('Left operand cannot be mixed', new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
            } else if ($statements_source) {
                Issue_Buffer::maybe_add(new Mixed_Operand('Right operand cannot be mixed', new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
            }
            if ($left_type_part instanceof T_Mixed && $left_type_part->from_loop_isset && $parent instanceof Php_Parser\Node\Expr\Assign_Op\Plus && !$right_type_part instanceof T_Mixed) {
                $result_type = Type::combine_union_types(new Union([$right_type_part]), $result_type);
                return null;
            }
            $from_loop_isset = (!$left_type_part instanceof T_Mixed || $left_type_part->from_loop_isset) && (!$right_type_part instanceof T_Mixed || $right_type_part->from_loop_isset);
            $result_type = Type::get_mixed($from_loop_isset);
            return $result_type;
        }
        if ($left_type_part instanceof T_Template_Param || $right_type_part instanceof T_Template_Param) {
            if ($left_type_part instanceof T_Template_Param && !$left_type_part->as->is_int() && !$left_type_part->as->is_float()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Mixed_Operand('Left operand cannot be a non-numeric template', new Code_Location($statements_source, $left)), $statements_source->get_suppressed_issues());
                }
            } elseif ($right_type_part instanceof T_Template_Param && !$right_type_part->as->is_int() && !$right_type_part->as->is_float()) {
                if ($statements_source) {
                    Issue_Buffer::maybe_add(new Mixed_Operand('Right operand cannot be a non-numeric template', new Code_Location($statements_source, $right)), $statements_source->get_suppressed_issues());
                }
            }
            return null;
        }
        if ($statements_source && $codebase && $context) {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_source->get_file_path() === $statements_source->get_root_file_path() && (!($parent_source = $statements_source->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_source->get_file_path());
            }
        }
        if ($left_type_part instanceof T_Array || $right_type_part instanceof T_Array || $left_type_part instanceof T_Keyed_Array || $right_type_part instanceof T_Keyed_Array) {
            if (!$right_type_part instanceof T_Array && !$right_type_part instanceof T_Keyed_Array || !$left_type_part instanceof T_Array && !$left_type_part instanceof T_Keyed_Array) {
                if (!$left_type_part instanceof T_Array && !$left_type_part instanceof T_Keyed_Array) {
                    $invalid_left_messages[] = 'Cannot add an array to a non-array ' . $left_type_part;
                } else {
                    $invalid_right_messages[] = 'Cannot add an array to a non-array ' . $right_type_part;
                }
                if ($left_type_part instanceof T_Array || $left_type_part instanceof T_Keyed_Array) {
                    $has_valid_left_operand = true;
                } elseif ($right_type_part instanceof T_Array || $right_type_part instanceof T_Keyed_Array) {
                    $has_valid_right_operand = true;
                }
                return null;
            }
            if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Plus) {
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                if ($left_type_part instanceof T_Keyed_Array && $right_type_part instanceof T_Keyed_Array) {
                    $definitely_existing_mixed_right_properties = array_diff_key($right_type_part->properties, $left_type_part->properties);
                    $properties = $left_type_part->properties;
                    foreach ($right_type_part->properties as $key => $type) {
                        if (!isset($properties[$key])) {
                            $properties[$key] = $type;
                        } elseif ($properties[$key]->possibly_undefined) {
                            $properties[$key] = Type::combine_union_types($properties[$key], $type, $codebase, false, true, 500, $type->possibly_undefined);
                        }
                    }
                    if ($left_type_part->fallback_params !== null) {
                        foreach ($definitely_existing_mixed_right_properties as $key => $type) {
                            $properties[$key] = Type::combine_union_types(Type::get_mixed(), $type);
                        }
                    }
                    if ($left_type_part->fallback_params === null && $right_type_part->fallback_params === null) {
                        $fallback_params = null;
                    } elseif ($left_type_part->fallback_params !== null && $right_type_part->fallback_params !== null) {
                        $fallback_params = [Type::combine_union_types($left_type_part->fallback_params[0], $right_type_part->fallback_params[0]), Type::combine_union_types($left_type_part->fallback_params[1], $right_type_part->fallback_params[1])];
                    } else {
                        $fallback_params = $left_type_part->fallback_params ?: $right_type_part->fallback_params;
                    }
                    $new_keyed_array = new T_Keyed_Array($properties, null, $fallback_params);
                    $result_type_member = new Union([$new_keyed_array]);
                } else {
                    $result_type_member = Type_Combiner::combine([$left_type_part, $right_type_part], $codebase, true);
                }
                $result_type = Type::combine_union_types($result_type_member, $result_type, $codebase, true);
                if ($left instanceof Php_Parser\Node\Expr\Array_Dim_Fetch && $context && $statements_source instanceof Statements_Analyzer) {
                    Array_Assignment_Analyzer::update_array_type($statements_source, $left, $right, $result_type, $context);
                }
                return null;
            }
        }
        /**
         * @var Atomic $left_type_part
         * @var Atomic $right_type_part
         * // Todo remove this hint reset after fixing #10267
         */
        if ($left_type_part instanceof T_Named_Object && strtolower($left_type_part->value) === 'gmp' || $right_type_part instanceof T_Named_Object && strtolower($right_type_part->value) === 'gmp') {
            if ($left_type_part instanceof T_Named_Object && strtolower($left_type_part->value) === 'gmp' && ($right_type_part instanceof T_Named_Object && strtolower($right_type_part->value) === 'gmp' || ($right_type_part->is_numeric_type() || $right_type_part instanceof T_Mixed)) || $right_type_part instanceof T_Named_Object && strtolower($right_type_part->value) === 'gmp' && ($left_type_part instanceof T_Named_Object && strtolower($left_type_part->value) === 'gmp' || ($left_type_part->is_numeric_type() || $left_type_part instanceof T_Mixed))) {
                $result_type = Type::combine_union_types(new Union([new T_Named_Object('GMP')]), $result_type);
            } else if ($statements_source) {
                Issue_Buffer::maybe_add(new Invalid_Operand('Cannot add GMP to non-numeric type', new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
            }
            return null;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Plus || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Minus || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Mul || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Div || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Pow) {
            $non_decimal_type = null;
            if ($left_type_part instanceof T_Named_Object && strtolower($left_type_part->value) === "decimal\\decimal") {
                $non_decimal_type = $right_type_part;
            } elseif ($right_type_part instanceof T_Named_Object && strtolower($right_type_part->value) === "decimal\\decimal") {
                $non_decimal_type = $left_type_part;
            }
            if ($non_decimal_type !== null) {
                if ($non_decimal_type instanceof T_Int || $non_decimal_type instanceof T_Numeric_String || $non_decimal_type instanceof T_Named_Object && strtolower($non_decimal_type->value) === "decimal\\decimal") {
                    $result_type = Type::combine_union_types(new Union([new T_Named_Object(\Decimal\Decimal::class)]), $result_type);
                } else if ($statements_source) {
                    Issue_Buffer::maybe_add(new Invalid_Operand("Cannot add Decimal\\Decimal to {$non_decimal_type->get_id()}", new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
                }
                return null;
            }
        }
        if ($left_type_part instanceof T_Literal_String) {
            if (preg_match('/^\-?\d+$/', $left_type_part->value)) {
                $left_type_part = new T_Literal_Int((int) $left_type_part->value);
            } elseif (preg_match('/^\-?\d?\.\d+$/', $left_type_part->value)) {
                $left_type_part = new T_Literal_Float((float) $left_type_part->value);
            }
        }
        if ($right_type_part instanceof T_Literal_String) {
            if (preg_match('/^\-?\d+$/', $right_type_part->value)) {
                $right_type_part = new T_Literal_Int((int) $right_type_part->value);
            } elseif (preg_match('/^\-?\d?\.\d+$/', $right_type_part->value)) {
                $right_type_part = new T_Literal_Float((float) $right_type_part->value);
            }
        }
        if ($left_type_part->is_numeric_type() || $right_type_part->is_numeric_type()) {
            if (($left_type_part instanceof T_Numeric || $right_type_part instanceof T_Numeric) && ($left_type_part->is_numeric_type() && $right_type_part->is_numeric_type())) {
                if ($config->strict_binary_operands) {
                    if ($statements_source) {
                        Issue_Buffer::maybe_add(new Invalid_Operand('Cannot process different numeric types together in strict binary operands mode, ' . 'please cast explicitly', new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
                    }
                }
                if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
                    $new_result_type = Type::get_int();
                } else {
                    $new_result_type = new Union([new T_Float(), new T_Int()]);
                }
                $result_type = Type::combine_union_types($new_result_type, $result_type);
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                return null;
            }
            if ($left_type_part instanceof T_Int_Range && $right_type_part instanceof T_Int_Range) {
                self::analyze_operands_between_int_range($parent, $result_type, $left_type_part, $right_type_part);
                return null;
            }
            if ($left_type_part instanceof T_Int_Range && $right_type_part instanceof T_Int || $left_type_part instanceof T_Int && $right_type_part instanceof T_Int_Range) {
                self::analyze_operands_between_int_range_and_int($parent, $result_type, $left_type_part, $right_type_part);
                return null;
            }
            if ($left_type_part instanceof T_Int && $right_type_part instanceof T_Int) {
                if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Div) {
                    $result_type = new Union([new T_Int(), new T_Float()]);
                } else {
                    $left_is_positive = $left_type_part instanceof T_Literal_Int && $left_type_part->value > 0 || $left_type_part instanceof T_Int_Range && $left_type_part->is_positive();
                    $right_is_positive = $right_type_part instanceof T_Literal_Int && $right_type_part->value > 0 || $right_type_part instanceof T_Int_Range && $right_type_part->is_positive();
                    if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Minus) {
                        $always_positive = false;
                    } elseif ($left_is_positive && $right_is_positive) {
                        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Left || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Right) {
                            $always_positive = false;
                        } else {
                            $always_positive = true;
                        }
                    } elseif ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Plus && ($left_type_part instanceof T_Literal_Int && $left_type_part->value === 0) && $right_is_positive) {
                        $always_positive = true;
                    } elseif ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Plus && ($right_type_part instanceof T_Literal_Int && $right_type_part->value === 0) && $left_is_positive) {
                        $always_positive = true;
                    } else {
                        $always_positive = false;
                    }
                    if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
                        if ($right_type_part instanceof T_Literal_Int) {
                            $literal_value_max = $right_type_part->value - 1;
                            if ($always_positive) {
                                $result_type = Type::get_int_range(0, $literal_value_max);
                            } else {
                                $result_type = Type::get_int_range(-$literal_value_max, $literal_value_max);
                            }
                        } else if ($always_positive) {
                            $result_type = Type::get_list_key();
                        } else {
                            $result_type = Type::get_int();
                        }
                    } elseif ($parent instanceof Virtual_Plus || $parent instanceof Virtual_Minus) {
                        // This seems arbitrary defined as 1/-1, to facilitate loops
                        // and may need a better handling if there's issues
                        $sum = $parent instanceof Virtual_Plus ? 1 : -1;
                        if ($context && $context->inside_loop && $left_type_part instanceof T_Literal_Int) {
                            if ($parent instanceof Virtual_Plus) {
                                $new_type = new T_Int_Range($left_type_part->value + $sum, null);
                            } else {
                                $new_type = new T_Int_Range(null, $left_type_part->value + $sum);
                            }
                        } elseif ($left_type_part instanceof T_Literal_Int) {
                            if ($context && $context->inside_assignment) {
                                $new_type = new T_Int();
                            } else {
                                $new_type = new T_Literal_Int($left_type_part->value + $sum);
                            }
                        } elseif ($left_type_part instanceof T_Int_Range) {
                            $start = $left_type_part->min_bound === null ? null : $left_type_part->min_bound + $sum;
                            $end = $left_type_part->max_bound === null ? null : $left_type_part->max_bound + $sum;
                            $new_type = new T_Int_Range($start, $end);
                        } else {
                            $new_type = new T_Int();
                        }
                        $result_type = Type::combine_union_types(new Union([$new_type], ['from_calculation' => true]), $result_type);
                    } else {
                        $result_type = Type::combine_union_types($always_positive ? Type::get_int_range(1, null) : Type::get_int(true), $result_type);
                    }
                }
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                return null;
            }
            if ($left_type_part instanceof T_Float && $right_type_part instanceof T_Float) {
                if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
                    $result_type = Type::get_int();
                } else {
                    $result_type = Type::combine_union_types(Type::get_float(), $result_type);
                }
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                return null;
            }
            if ($left_type_part instanceof T_Float && $right_type_part instanceof T_Int || $left_type_part instanceof T_Int && $right_type_part instanceof T_Float) {
                if ($config->strict_binary_operands) {
                    if ($statements_source) {
                        Issue_Buffer::maybe_add(new Invalid_Operand('Cannot process ints and floats in strict binary operands mode, ' . 'please cast explicitly', new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
                    }
                }
                if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
                    $result_type = Type::get_int();
                } else {
                    $result_type = Type::combine_union_types(Type::get_float(), $result_type);
                }
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                return null;
            }
            if ($left_type_part->is_numeric_type() && $right_type_part->is_numeric_type()) {
                if ($config->strict_binary_operands) {
                    if ($statements_source) {
                        Issue_Buffer::maybe_add(new Invalid_Operand('Cannot process numeric types together in strict operands mode, ' . 'please cast explicitly', new Code_Location($statements_source, $parent)), $statements_source->get_suppressed_issues());
                    }
                }
                if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
                    $result_type = Type::get_int();
                } else {
                    $result_type = new Union([new T_Int(), new T_Float()]);
                }
                $has_valid_right_operand = true;
                $has_valid_left_operand = true;
                return null;
            }
            if (!$left_type_part->is_numeric_type()) {
                $invalid_left_messages[] = 'Cannot perform a numeric operation with a non-numeric type ' . $left_type_part;
                $has_valid_right_operand = true;
            } else {
                $invalid_right_messages[] = 'Cannot perform a numeric operation with a non-numeric type ' . $right_type_part;
                $has_valid_left_operand = true;
            }
        } else {
            $invalid_left_messages[] = 'Cannot perform a numeric operation with non-numeric types ' . $left_type_part . ' and ' . $right_type_part;
        }
        return null;
    }
    public static function arithmetic_operation(Php_Parser\Node $operation, float|int $operand1, float|int $operand2, bool $allow_float_result): ?Union
    {
        if ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Plus) {
            $result = $operand1 + $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Minus) {
            $result = $operand1 - $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
            if ($operand2 === 0) {
                return Type::get_never();
            }
            $result = $operand1 % $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Mul) {
            $result = $operand1 * $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Pow) {
            $result = $operand1 ** $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or) {
            $result = $operand1 | $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And) {
            $result = $operand1 & $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor) {
            $result = $operand1 ^ $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Left) {
            $result = $operand1 << $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Right) {
            $result = $operand1 >> $operand2;
        } elseif ($operation instanceof Php_Parser\Node\Expr\Binary_Op\Div) {
            if ($operand2 === 0 || $operand2 === 0.0) {
                return Type::get_never();
            }
            $result = $operand1 / $operand2;
        } else {
            return null;
        }
        $calculated_type = self::get_numerical_type($result);
        if (!$allow_float_result && $calculated_type->is_float()) {
            return null;
        }
        return $calculated_type;
    }
    private static function analyze_operands_between_int_range(Php_Parser\Node $parent, ?Union &$result_type, T_Int_Range $left_type_part, T_Int_Range $right_type_part): void
    {
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Div) {
            //can't assume an int range will stay int after division
            $result_type = Type::combine_union_types(new Union([new T_Int(), new T_Float()]), $result_type);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mod) {
            self::analyze_mod_between_int_range($result_type, $left_type_part, $right_type_part);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_And || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Or || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Bitwise_Xor) {
            //really complex to calculate
            $result_type = Type::combine_union_types(Type::get_int(), $result_type);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Left || $parent instanceof Php_Parser\Node\Expr\Binary_Op\Shift_Right) {
            //really complex to calculate
            $result_type = Type::combine_union_types(new Union([new T_Int()]), $result_type);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Mul) {
            self::analyze_mul_between_int_range($parent, $result_type, $left_type_part, $right_type_part);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Pow) {
            self::analyze_pow_between_int_range($result_type, $left_type_part, $right_type_part);
            return;
        }
        if ($parent instanceof Php_Parser\Node\Expr\Binary_Op\Minus) {
            //for Minus, we have to assume the min is the min from first range minus the max from the second
            $min_operand1 = $left_type_part->min_bound;
            $min_operand2 = $right_type_part->max_bound;
            //and the max is the max from first range minus the min from the second
            $max_operand1 = $left_type_part->max_bound;
            $max_operand2 = $right_type_part->min_bound;
        } else {
            $min_operand1 = $left_type_part->min_bound;
            $min_operand2 = $right_type_part->min_bound;
            $max_operand1 = $left_type_part->max_bound;
            $max_operand2 = $right_type_part->max_bound;
        }
        $calculated_min_type = null;
        if ($min_operand1 !== null && $min_operand2 !== null) {
            // when there are two valid numbers, make any operation
            $calculated_min_type = self::arithmetic_operation($parent, $min_operand1, $min_operand2, false);
        }
        $calculated_max_type = null;
        if ($max_operand1 !== null && $max_operand2 !== null) {
            // when there are two valid numbers, make any operation
            $calculated_max_type = self::arithmetic_operation($parent, $max_operand1, $max_operand2, false);
        }
        $min_value = $calculated_min_type !== null ? $calculated_min_type->get_single_int_literal()->value : null;
        $max_value = $calculated_max_type !== null ? $calculated_max_type->get_single_int_literal()->value : null;
        $new_result_type = Type::get_int_range($min_value, $max_value);
        $result_type = Type::combine_union_types($new_result_type, $result_type);
    }
    /**
     * @param TIntRange|TInt $left_type_part
     * @param TIntRange|TInt $right_type_part
     */
    private static function analyze_operands_between_int_range_and_int(Php_Parser\Node $parent, ?Union &$result_type, Atomic $left_type_part, Atomic $right_type_part): void
    {
        if (!$left_type_part instanceof T_Int_Range) {
            $left_type_part = T_Int_Range::convert_to_int_range($left_type_part);
        }
        if (!$right_type_part instanceof T_Int_Range) {
            $right_type_part = T_Int_Range::convert_to_int_range($right_type_part);
        }
        self::analyze_operands_between_int_range($parent, $result_type, $left_type_part, $right_type_part);
    }
    private static function analyze_mul_between_int_range(Php_Parser\Node\Expr\Binary_Op\Mul $parent, ?Union &$result_type, T_Int_Range $left_type_part, T_Int_Range $right_type_part): void
    {
        //Mul is a special case because of double negatives. We can only infer when we know both signs strictly
        if ($right_type_part->min_bound !== null && $right_type_part->max_bound !== null && $left_type_part->min_bound !== null && $left_type_part->max_bound !== null) {
            //everything is known, we can do calculations
            //[ x_1 , x_2 ] ⋆ [ y_1 , y_2 ] =
            //      [
            //          min(x_1 ⋆ y_1 , x_1 ⋆ y_2 , x_2 ⋆ y_1 , x_2 ⋆ y_2),
            //          max(x_1 ⋆ y_1 , x_1 ⋆ y_2 , x_2 ⋆ y_1 , x_2 ⋆ y_2)
            //      ]
            $x_1 = $right_type_part->min_bound;
            $x_2 = $right_type_part->max_bound;
            $y_1 = $left_type_part->min_bound;
            $y_2 = $left_type_part->max_bound;
            $min_value = min($x_1 * $y_1, $x_1 * $y_2, $x_2 * $y_1, $x_2 * $y_2);
            $max_value = max($x_1 * $y_1, $x_1 * $y_2, $x_2 * $y_1, $x_2 * $y_2);
            $new_result_type = Type::get_int_range($min_value, $max_value);
        } elseif ($right_type_part->is_positive_or_zero() && $left_type_part->is_positive_or_zero()) {
            // both operands are positive, result will be only positive
            $min_operand1 = $left_type_part->min_bound;
            $min_operand2 = $right_type_part->min_bound;
            $max_operand1 = $left_type_part->max_bound;
            $max_operand2 = $right_type_part->max_bound;
            $calculated_min_type = null;
            if ($min_operand1 !== null && $min_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_min_type = self::arithmetic_operation($parent, $min_operand1, $min_operand2, false);
            }
            $calculated_max_type = null;
            if ($max_operand1 !== null && $max_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_max_type = self::arithmetic_operation($parent, $max_operand1, $max_operand2, false);
            }
            $min_value = $calculated_min_type !== null ? $calculated_min_type->get_single_int_literal()->value : null;
            $max_value = $calculated_max_type !== null ? $calculated_max_type->get_single_int_literal()->value : null;
            $new_result_type = Type::get_int_range($min_value, $max_value);
        } elseif ($right_type_part->is_positive_or_zero() && $left_type_part->is_negative_or_zero()) {
            // one operand is negative, result will be negative and we have to check min vs max
            $min_operand1 = $left_type_part->max_bound;
            $min_operand2 = $right_type_part->min_bound;
            $max_operand1 = $left_type_part->min_bound;
            $max_operand2 = $right_type_part->max_bound;
            $calculated_min_type = null;
            if ($min_operand1 !== null && $min_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_min_type = self::arithmetic_operation($parent, $min_operand1, $min_operand2, false);
            }
            $calculated_max_type = null;
            if ($max_operand1 !== null && $max_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_max_type = self::arithmetic_operation($parent, $max_operand1, $max_operand2, false);
            }
            $min_value = $calculated_min_type !== null ? $calculated_min_type->get_single_int_literal()->value : null;
            $max_value = $calculated_max_type !== null ? $calculated_max_type->get_single_int_literal()->value : null;
            if ($min_value > $max_value) {
                [$min_value, $max_value] = [$max_value, $min_value];
            }
            $new_result_type = Type::get_int_range($min_value, $max_value);
        } elseif ($right_type_part->is_negative_or_zero() && $left_type_part->is_positive_or_zero()) {
            // one operand is negative, result will be negative and we have to check min vs max
            $min_operand1 = $left_type_part->min_bound;
            $min_operand2 = $right_type_part->max_bound;
            $max_operand1 = $left_type_part->max_bound;
            $max_operand2 = $right_type_part->min_bound;
            $calculated_min_type = null;
            if ($min_operand1 !== null && $min_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_min_type = self::arithmetic_operation($parent, $min_operand1, $min_operand2, false);
            }
            $calculated_max_type = null;
            if ($max_operand1 !== null && $max_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_max_type = self::arithmetic_operation($parent, $max_operand1, $max_operand2, false);
            }
            $min_value = $calculated_min_type !== null ? $calculated_min_type->get_single_int_literal()->value : null;
            $max_value = $calculated_max_type !== null ? $calculated_max_type->get_single_int_literal()->value : null;
            if ($min_value > $max_value) {
                [$min_value, $max_value] = [$max_value, $min_value];
            }
            $new_result_type = Type::get_int_range($min_value, $max_value);
        } elseif ($right_type_part->is_negative_or_zero() && $left_type_part->is_negative_or_zero()) {
            // both operand are negative, result will be positive
            $min_operand1 = $left_type_part->max_bound;
            $min_operand2 = $right_type_part->max_bound;
            $max_operand1 = $left_type_part->min_bound;
            $max_operand2 = $right_type_part->min_bound;
            $calculated_min_type = null;
            if ($min_operand1 !== null && $min_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_min_type = self::arithmetic_operation($parent, $min_operand1, $min_operand2, false);
            }
            $calculated_max_type = null;
            if ($max_operand1 !== null && $max_operand2 !== null) {
                // when there are two valid numbers, make any operation
                $calculated_max_type = self::arithmetic_operation($parent, $max_operand1, $max_operand2, false);
            }
            $min_value = $calculated_min_type !== null ? $calculated_min_type->get_single_int_literal()->value : null;
            $max_value = $calculated_max_type !== null ? $calculated_max_type->get_single_int_literal()->value : null;
            $new_result_type = Type::get_int_range($min_value, $max_value);
        } else {
            $new_result_type = Type::get_int(true);
        }
        $result_type = Type::combine_union_types($new_result_type, $result_type);
    }
    private static function analyze_pow_between_int_range(?Union &$result_type, T_Int_Range $left_type_part, T_Int_Range $right_type_part): void
    {
        //If Pow first operand is negative, the result could be positive or negative, else it will be positive
        //If Pow second operand is negative, the result will be float, if it's 0, it will be 1/-1, else positive
        if ($left_type_part->is_positive()) {
            if ($right_type_part->is_positive()) {
                $new_result_type = Type::get_int_range(1, null);
            } elseif ($right_type_part->is_negative()) {
                $new_result_type = Type::get_float();
            } elseif ($right_type_part->min_bound === 0 && $right_type_part->max_bound === 0) {
                $new_result_type = Type::get_int(true, 1);
            } else {
                //$right_type_part may be a mix of positive, negative and 0
                $new_result_type = new Union([new T_Int(), new T_Float()]);
            }
        } elseif ($left_type_part->is_negative()) {
            if ($right_type_part->is_positive()) {
                if ($right_type_part->min_bound === $right_type_part->max_bound) {
                    if ($right_type_part->max_bound % 2 === 0) {
                        $new_result_type = Type::get_int_range(1, null);
                    } else {
                        $new_result_type = Type::get_int_range(null, -1);
                    }
                } else {
                    $new_result_type = Type::get_int(true);
                }
            } elseif ($right_type_part->is_negative()) {
                $new_result_type = Type::get_float();
            } elseif ($right_type_part->min_bound === 0 && $right_type_part->max_bound === 0) {
                $new_result_type = Type::get_int(true, -1);
            } else {
                //$right_type_part may be a mix of positive, negative and 0
                $new_result_type = new Union([new T_Int(), new T_Float()]);
            }
        } elseif ($left_type_part->min_bound === 0 && $left_type_part->max_bound === 0) {
            if ($right_type_part->is_positive()) {
                $new_result_type = Type::get_int(true, 0);
            } elseif ($right_type_part->min_bound === 0 && $right_type_part->max_bound === 0) {
                $new_result_type = Type::get_int(true, 1);
            } elseif ($right_type_part->is_negative()) {
                $new_result_type = Type::get_float();
            } else {
                $new_result_type = new Union([new T_Float(), new T_Literal_Int(0), new T_Literal_Int(1)], ['from_calculation' => true]);
            }
        } else if ($right_type_part->is_positive()) {
            if ($right_type_part->min_bound === $right_type_part->max_bound && $right_type_part->max_bound % 2 === 0) {
                $new_result_type = Type::get_int_range(1, null);
            } else {
                $new_result_type = Type::get_int(true);
            }
        } elseif ($right_type_part->is_negative()) {
            $new_result_type = Type::get_float();
        } elseif ($right_type_part->min_bound === 0 && $right_type_part->max_bound === 0) {
            $new_result_type = Type::get_int(true, 1);
        } else {
            //$left_type_part may be a mix of positive, negative and 0
            $new_result_type = new Union([new T_Int(), new T_Float()]);
        }
        $result_type = Type::combine_union_types($new_result_type, $result_type);
    }
    private static function analyze_mod_between_int_range(?Union &$result_type, T_Int_Range $left_type_part, T_Int_Range $right_type_part): void
    {
        //result of Mod is not directly dependant on the bounds of the range
        if ($right_type_part->min_bound !== null && $right_type_part->min_bound === $right_type_part->max_bound) {
            //if the second operand is a literal, we can be pretty detailed
            if ($right_type_part->max_bound === 0) {
                $new_result_type = Type::get_never();
            } else if ($left_type_part->is_positive_or_zero()) {
                if ($right_type_part->is_positive()) {
                    $max = $right_type_part->min_bound - 1;
                    $new_result_type = Type::get_int_range(0, $max);
                } else {
                    $max = $right_type_part->min_bound + 1;
                    $new_result_type = Type::get_int_range($max, 0);
                }
            } elseif ($left_type_part->is_negative_or_zero()) {
                if ($right_type_part->is_positive()) {
                    $max = $right_type_part->min_bound - 1;
                    $new_result_type = Type::get_int_range(-$max, 0);
                } else {
                    $max = $right_type_part->min_bound + 1;
                    $new_result_type = Type::get_int_range(-$max, 0);
                }
            } else {
                if ($right_type_part->is_positive()) {
                    $max = $right_type_part->min_bound - 1;
                } else {
                    $max = -$right_type_part->min_bound - 1;
                }
                $new_result_type = Type::get_int_range(-$max, $max);
            }
        } elseif ($right_type_part->is_positive()) {
            if ($left_type_part->is_positive_or_zero()) {
                if ($right_type_part->max_bound !== null) {
                    //we now that the result will be a range between 0 and $right->max - 1
                    $new_result_type = new Union([new T_Int_Range(0, $right_type_part->max_bound - 1)]);
                } else {
                    $new_result_type = Type::get_list_key();
                }
            } elseif ($left_type_part->is_negative_or_zero()) {
                $new_result_type = Type::get_int_range(null, 0);
            } else {
                $new_result_type = Type::get_int(true);
            }
        } elseif ($right_type_part->is_negative()) {
            if ($left_type_part->is_positive_or_zero()) {
                $new_result_type = Type::get_int_range(null, 0);
            } elseif ($left_type_part->is_negative_or_zero()) {
                $new_result_type = Type::get_int_range(null, 0);
            } else {
                $new_result_type = Type::get_int(true);
            }
        } else {
            $new_result_type = Type::get_int(true);
        }
        $result_type = Type::combine_union_types($new_result_type, $result_type);
    }
}