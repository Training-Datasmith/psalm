<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Binary_Op;

use AssertionError;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Atomic_Type_Comparator;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\False_Operand;
use Psalm\Issue\Implicit_To_String_Cast;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Invalid_Operand;
use Psalm\Issue\Mixed_Operand;
use Psalm\Issue\Null_Operand;
use Psalm\Issue\Possibly_False_Operand;
use Psalm\Issue\Possibly_Invalid_Operand;
use Psalm\Issue\Possibly_Null_Operand;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Float;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Lowercase_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Non_Falsy_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function count;
use function reset;
use function strlen;
/**
 * @internal
 */
final class Concat_Analyzer
{
    private const MAX_LITERALS = 64;
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $left, Php_Parser\Node\Expr $right, Context $context, ?Union &$result_type = null): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $left_type = $statements_analyzer->node_data->get_type($left);
        $right_type = $statements_analyzer->node_data->get_type($right);
        $config = Config::get_instance();
        if ($left_type && $right_type) {
            $result_type = Type::get_string();
            if ($left_type->has_mixed() || $right_type->has_mixed()) {
                if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                    $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
                }
                if ($left_type->has_mixed()) {
                    $arg_location = new Code_Location($statements_analyzer->get_source(), $left);
                    $origin_locations = [];
                    if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                        foreach ($left_type->parent_nodes as $parent_node) {
                            $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                        }
                    }
                    $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                    if ($origin_location && $origin_location->get_hash() === $arg_location->get_hash()) {
                        $origin_location = null;
                    }
                    Issue_Buffer::maybe_add(new Mixed_Operand('Left operand cannot be mixed', $arg_location, $origin_location), $statements_analyzer->get_suppressed_issues());
                } else {
                    $arg_location = new Code_Location($statements_analyzer->get_source(), $right);
                    $origin_locations = [];
                    if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                        foreach ($right_type->parent_nodes as $parent_node) {
                            $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                        }
                    }
                    $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                    if ($origin_location && $origin_location->get_hash() === $arg_location->get_hash()) {
                        $origin_location = null;
                    }
                    Issue_Buffer::maybe_add(new Mixed_Operand('Right operand cannot be mixed', $arg_location, $origin_location), $statements_analyzer->get_suppressed_issues());
                }
                return;
            }
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
            }
            self::analyze_operand($statements_analyzer, $left, $left_type, 'Left', $context);
            self::analyze_operand($statements_analyzer, $right, $right_type, 'Right', $context);
            // If both types are specific literals, combine them into new literals
            $literal_concat = false;
            if ($left_type->all_specific_literals() && $right_type->all_specific_literals()) {
                $left_type_parts = $left_type->get_atomic_types();
                $right_type_parts = $right_type->get_atomic_types();
                $combinations = count($left_type_parts) * count($right_type_parts);
                if ($combinations < self::MAX_LITERALS) {
                    $literal_concat = true;
                    $result_type_parts = [];
                    foreach ($left_type->get_atomic_types() as $left_type_part) {
                        foreach ($right_type->get_atomic_types() as $right_type_part) {
                            $literal = $left_type_part->value . $right_type_part->value;
                            if (strlen($literal) >= $config->max_string_length) {
                                // Literal too long, use non-literal type instead
                                $literal_concat = false;
                                break 2;
                            }
                            $result_type_parts[] = Type::get_atomic_string_from_literal($literal);
                        }
                    }
                    if ($literal_concat) {
                        if (count($result_type_parts) === 0) {
                            throw new AssertionError("The number of parts cannot be 0!");
                        }
                        if (count($result_type_parts) !== $combinations) {
                            throw new AssertionError("The number of parts does not match!");
                        }
                        $result_type = new Union($result_type_parts);
                    }
                }
            }
            if (!$literal_concat) {
                $numeric_type = new Union([new T_Numeric_String(), new T_Int(), new T_Float()]);
                $left_is_numeric = Union_Type_Comparator::is_contained_by($codebase, $left_type, $numeric_type);
                $right_is_numeric = Union_Type_Comparator::is_contained_by($codebase, $right_type, $numeric_type);
                $has_numeric_type = $left_is_numeric || $right_is_numeric;
                if ($left_is_numeric) {
                    $right_uint = Type::get_list_key();
                    $right_is_uint = Union_Type_Comparator::is_contained_by($codebase, $right_type, $right_uint);
                    if ($right_is_uint) {
                        $result_type = Type::get_numeric_string();
                        return;
                    }
                }
                $lowercase_type = $numeric_type->get_builder()->add_type(new T_Lowercase_String())->freeze();
                $all_lowercase = Union_Type_Comparator::is_contained_by($codebase, $left_type, $lowercase_type) && Union_Type_Comparator::is_contained_by($codebase, $right_type, $lowercase_type);
                $non_empty_string = $numeric_type->get_builder()->add_type(new T_Non_Empty_String())->freeze();
                $left_non_empty = Union_Type_Comparator::is_contained_by($codebase, $left_type, $non_empty_string);
                $right_non_empty = Union_Type_Comparator::is_contained_by($codebase, $right_type, $non_empty_string);
                $has_non_empty = $left_non_empty || $right_non_empty;
                $all_non_empty = $left_non_empty && $right_non_empty;
                $has_numeric_and_non_empty = $has_numeric_type && $has_non_empty;
                $non_falsy_string = $numeric_type->get_builder()->add_type(new T_Non_Falsy_String())->freeze();
                $left_non_falsy = Union_Type_Comparator::is_contained_by($codebase, $left_type, $non_falsy_string);
                $right_non_falsy = Union_Type_Comparator::is_contained_by($codebase, $right_type, $non_falsy_string);
                $all_literals = $left_type->all_literals() && $right_type->all_literals();
                if ($has_non_empty) {
                    if ($all_literals) {
                        $result_type = new Union([new T_Non_Empty_Nonspecific_Literal_String()]);
                    } elseif ($all_lowercase) {
                        $result_type = Type::get_non_empty_lowercase_string();
                    } elseif ($all_non_empty || $has_numeric_and_non_empty || $left_non_falsy || $right_non_falsy) {
                        $result_type = Type::get_non_falsy_string();
                    } else {
                        $result_type = Type::get_non_empty_string();
                    }
                } else if ($all_literals) {
                    $result_type = new Union([new T_Nonspecific_Literal_String()]);
                } elseif ($all_lowercase) {
                    $result_type = Type::get_lowercase_string();
                } else {
                    $result_type = Type::get_string();
                }
            }
        } elseif ($left_type || $right_type) {
            /**
             * @var Union $known_operand
             */
            $known_operand = $right_type ?? $left_type;
            if ($known_operand->is_single()) {
                $known_operands_atomic = $known_operand->get_single_atomic();
                if ($known_operand->is_non_empty_string()) {
                    $result_type = Type::get_non_empty_string();
                }
                if ($known_operands_atomic instanceof T_Non_Falsy_String) {
                    $result_type = Type::get_non_falsy_string();
                }
                if ($known_operands_atomic instanceof T_Literal_String) {
                    if ($known_operands_atomic->value) {
                        $result_type = Type::get_non_falsy_string();
                    } elseif ($known_operands_atomic->value !== '') {
                        $result_type = Type::get_non_empty_string();
                    }
                }
            }
        }
    }
    private static function analyze_operand(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $operand, Union $operand_type, string $side, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $config = Config::get_instance();
        if ($operand_type->is_null()) {
            Issue_Buffer::maybe_add(new Null_Operand('Cannot concatenate with a ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($operand_type->is_false()) {
            Issue_Buffer::maybe_add(new False_Operand('Cannot concatenate with a ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($operand_type->is_nullable() && !$operand_type->ignore_nullable_issues) {
            Issue_Buffer::maybe_add(new Possibly_Null_Operand('Cannot concatenate with a possibly null ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
        }
        if ($operand_type->is_falsable() && !$operand_type->ignore_falsable_issues) {
            Issue_Buffer::maybe_add(new Possibly_False_Operand('Cannot concatenate with a possibly false ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
        }
        $operand_type_match = true;
        $has_valid_operand = false;
        $comparison_result = new Type_Comparison_Result();
        foreach ($operand_type->get_atomic_types() as $operand_type_part) {
            if ($operand_type_part instanceof T_Template_Param && !$operand_type_part->as->is_string()) {
                Issue_Buffer::maybe_add(new Mixed_Operand("{$side} operand cannot be a non-string template param", new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
                return;
            }
            if ($operand_type_part instanceof T_Null) {
                continue;
            }
            if ($operand_type_part instanceof T_False) {
                continue;
            }
            $operand_type_part_match = Atomic_Type_Comparator::is_contained_by($codebase, $operand_type_part, new T_String(), false, false, $comparison_result);
            $operand_type_match = $operand_type_match && $operand_type_part_match;
            $has_valid_operand = $has_valid_operand || $operand_type_part_match;
            if ($comparison_result->to_string_cast && $config->strict_binary_operands) {
                Issue_Buffer::maybe_add(new Implicit_To_String_Cast("{$side} side of concat op expects string, '{$operand_type}' provided with a __toString method", new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
            }
            foreach ($operand_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Named_Object) {
                    $to_string_method_id = new Method_Identifier($atomic_type->value, '__tostring');
                    if ($codebase->methods->method_exists($to_string_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $operand) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path())) {
                        try {
                            $storage = $codebase->methods->get_storage($to_string_method_id);
                        } catch (UnexpectedValueException) {
                            continue;
                        }
                        if ($context->mutation_free && !$storage->mutation_free) {
                            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method ' . $atomic_type->value . '::__toString from a pure context', new Code_Location($statements_analyzer, $operand)), $statements_analyzer->get_suppressed_issues());
                        } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                            $statements_analyzer->get_source()->inferred_has_mutation = true;
                            $statements_analyzer->get_source()->inferred_impure = true;
                        }
                    }
                }
            }
        }
        if (!$operand_type_match && (!$comparison_result->scalar_type_match_found || !$operand_type->is_concat_safe() && $config->strict_binary_operands)) {
            if ($has_valid_operand) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Operand('Cannot concatenate with a ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Operand('Cannot concatenate with a ' . $operand_type, new Code_Location($statements_analyzer->get_source(), $operand)), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
}