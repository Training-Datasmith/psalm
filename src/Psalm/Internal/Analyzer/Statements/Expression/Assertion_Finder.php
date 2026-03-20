<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Php_Parser\Node\Expr\Binary_Op;
use Php_Parser\Node\Expr\Binary_Op\Equal;
use Php_Parser\Node\Expr\Binary_Op\Greater;
use Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal;
use Php_Parser\Node\Expr\Binary_Op\Identical;
use Php_Parser\Node\Expr\Binary_Op\Not_Equal;
use Php_Parser\Node\Expr\Binary_Op\Not_Identical;
use Php_Parser\Node\Expr\Binary_Op\Smaller;
use Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal;
use Php_Parser\Node\Expr\Unary_Minus;
use Php_Parser\Node\Expr\Unary_Plus;
use Php_Parser\Node\Scalar\Int_;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\File_Source;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Docblock_Type_Contradiction;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Redundant_Condition;
use Psalm\Issue\Redundant_Condition_Given_Docblock_Type;
use Psalm\Issue\Redundant_Identity_With_True;
use Psalm\Issue\Type_Does_Not_Contain_Null;
use Psalm\Issue\Type_Does_Not_Contain_Type;
use Psalm\Issue\Unevaluated_Code;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Binary_Op\Virtual_Identical;
use Psalm\Node\Expr\Binary_Op\Virtual_Not_Identical;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Array_Key_Exists;
use Psalm\Storage\Assertion\Does_Not_Have_At_Least_Count;
use Psalm\Storage\Assertion\Does_Not_Have_Exact_Count;
use Psalm\Storage\Assertion\Empty_;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Has_At_Least_Count;
use Psalm\Storage\Assertion\Has_Exact_Count;
use Psalm\Storage\Assertion\Has_Method;
use Psalm\Storage\Assertion\In_Array;
use Psalm\Storage\Assertion\Is_A_Class;
use Psalm\Storage\Assertion\Is_Class_Equal;
use Psalm\Storage\Assertion\Is_Class_Not_Equal;
use Psalm\Storage\Assertion\Is_Countable;
use Psalm\Storage\Assertion\Is_Equal_Isset;
use Psalm\Storage\Assertion\Is_Greater_Than;
use Psalm\Storage\Assertion\Is_Greater_Than_Or_Equal_To;
use Psalm\Storage\Assertion\Is_Identical;
use Psalm\Storage\Assertion\Is_Isset;
use Psalm\Storage\Assertion\Is_Less_Than;
use Psalm\Storage\Assertion\Is_Less_Than_Or_Equal_To;
use Psalm\Storage\Assertion\Is_Loosely_Equal;
use Psalm\Storage\Assertion\Is_Not_Identical;
use Psalm\Storage\Assertion\Is_Not_Loosely_Equal;
use Psalm\Storage\Assertion\Is_Not_Type;
use Psalm\Storage\Assertion\Is_Type;
use Psalm\Storage\Assertion\Nested_Assertions;
use Psalm\Storage\Assertion\Non_Empty_Countable;
use Psalm\Storage\Assertion\Not_Non_Empty_Countable;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\Possibilities;
use Psalm\Storage\Property_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closed_Resource;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Resource;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Trait_String;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Reconciler;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_key_exists;
use function assert;
use function count;
use function explode;
use function in_array;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 * This class transform conditions in code into "assertions" that will be reconciled with the type already known of a
 * given variable to narrow the type or find paradox.
 * For example if $a is an int, if($a > 0) will be turned into an assertion to make psalm understand that in the
 * if block, $a is a positive-int
 */
final class Assertion_Finder
{
    public const ASSIGNMENT_TO_RIGHT = 1;
    public const ASSIGNMENT_TO_LEFT = -1;
    /**
     * Gets all the type assertions in a conditional
     *
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    public static function scrape_assertions(Php_Parser\Node\Expr $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase = null, bool $inside_negation = false, bool $cache = true, bool $inside_conditional = true): array
    {
        $if_types = [];
        if ($conditional instanceof Php_Parser\Node\Expr\Instanceof_) {
            return self::get_and_check_instanceof_assertions($conditional, $codebase, $source, $this_class_name, $inside_negation);
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Assign) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->var, $this_class_name, $source);
            $candidate_if_types = $inside_conditional ? self::scrape_assertions($conditional->expr, $this_class_name, $source, $codebase, $inside_negation, $cache, $inside_conditional) : [];
            if ($var_name) {
                if ($candidate_if_types) {
                    $if_types[$var_name] = [[new Nested_Assertions($candidate_if_types[0])]];
                } else {
                    $if_types[$var_name] = [[new Truthy()]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        $var_name = Expression_Identifier::get_extended_var_id($conditional, $this_class_name, $source);
        if ($var_name) {
            $if_types[$var_name] = [[new Truthy()]];
            if (!$conditional instanceof Php_Parser\Node\Expr\Method_Call && !$conditional instanceof Php_Parser\Node\Expr\Static_Call) {
                return [$if_types];
            }
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Boolean_Not) {
            return [];
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal) {
            return self::scrape_equality_assertions($conditional, $this_class_name, $source, $codebase, $cache, $inside_conditional);
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal) {
            return self::scrape_inequality_assertions($conditional, $this_class_name, $source, $codebase, $cache, $inside_conditional);
        }
        //A nullsafe method call basically adds an assertion !null for the checked variable
        if ($conditional instanceof Php_Parser\Node\Expr\Nullsafe_Method_Call) {
            $if_types = [];
            $var_name = Expression_Identifier::get_extended_var_id($conditional->var, $this_class_name, $source);
            if ($var_name) {
                $if_types[$var_name] = [[new Is_Not_Type(new T_Null())]];
            }
            //we may throw a RedundantNullsafeMethodCall here in the future if $var_name is never null
            return $if_types ? [$if_types] : [];
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal) {
            return self::get_greater_assertions($conditional, $source, $this_class_name);
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Smaller || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal) {
            return self::get_smaller_assertions($conditional, $source, $this_class_name);
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Func_Call && !$conditional->is_first_class_callable()) {
            return self::process_function_call($conditional, $this_class_name, $source, $codebase, $inside_negation);
        }
        if (($conditional instanceof Php_Parser\Node\Expr\Method_Call || $conditional instanceof Php_Parser\Node\Expr\Static_Call) && !$conditional->is_first_class_callable()) {
            $custom_assertions = self::process_custom_assertion($conditional, $this_class_name, $source);
            if ($custom_assertions) {
                return $custom_assertions;
            }
            return $if_types ? [$if_types] : [];
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Empty_) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->expr, $this_class_name, $source);
            if ($var_name) {
                if ($conditional->expr instanceof Php_Parser\Node\Expr\Variable && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($conditional->expr)) && !$var_type->is_mixed() && !$var_type->possibly_undefined) {
                    $if_types[$var_name] = [[new Falsy()]];
                } else {
                    $if_types[$var_name] = [[new Empty_()]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Isset_) {
            foreach ($conditional->vars as $isset_var) {
                $var_name = Expression_Identifier::get_extended_var_id($isset_var, $this_class_name, $source);
                if ($var_name) {
                    if ($isset_var instanceof Php_Parser\Node\Expr\Variable && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($isset_var)) && !$var_type->is_mixed() && !$var_type->possibly_undefined && !$var_type->possibly_undefined_from_try) {
                        $if_types[$var_name] = [[new Is_Not_Type(new T_Null())]];
                    } else {
                        $if_types[$var_name] = [[new Is_Isset()]];
                    }
                } else {
                    // look for any variables we *can* use for an isset assertion
                    $array_root = $isset_var;
                    while ($array_root instanceof Php_Parser\Node\Expr\Array_Dim_Fetch && !$var_name) {
                        $array_root = $array_root->var;
                        $var_name = Expression_Identifier::get_extended_var_id($array_root, $this_class_name, $source);
                    }
                    if ($var_name) {
                        $if_types[$var_name] = [[new Is_Equal_Isset()]];
                    }
                }
            }
            return $if_types ? [$if_types] : [];
        }
        return [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function scrape_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase = null, bool $cache = true, bool $inside_conditional = true): array
    {
        $null_position = self::has_null_variable($conditional, $source);
        if ($null_position !== null) {
            return self::get_null_equality_assertions($conditional, $this_class_name, $source, $codebase, $null_position);
        }
        $false_position = self::has_false_variable($conditional);
        if ($false_position) {
            return self::get_false_equality_assertions($conditional, $this_class_name, $source, $codebase, $false_position, $cache, $inside_conditional);
        }
        $true_position = self::has_true_variable($conditional);
        if ($true_position) {
            return self::get_true_equality_assertions($conditional, $this_class_name, $source, $codebase, $true_position, $cache, $inside_conditional);
        }
        $empty_array_position = self::has_empty_array_variable($conditional);
        if ($empty_array_position !== null) {
            return self::get_empty_array_equality_assertions($conditional, $this_class_name, $source, $codebase, $empty_array_position);
        }
        $gettype_position = self::has_get_type_check($conditional);
        if ($gettype_position) {
            return self::get_gettype_equality_assertions($conditional, $this_class_name, $source, $gettype_position);
        }
        $get_debug_type_position = self::has_get_debug_type_check($conditional);
        if ($get_debug_type_position) {
            return self::get_getdebugtype_equality_assertions($conditional, $this_class_name, $source, $get_debug_type_position);
        }
        $count = null;
        $count_equality_position = self::has_count_equality_check($conditional, $count);
        if ($count_equality_position) {
            $if_types = [];
            if ($count_equality_position === self::ASSIGNMENT_TO_RIGHT) {
                $count_expr = $conditional->left;
            } elseif ($count_equality_position === self::ASSIGNMENT_TO_LEFT) {
                $count_expr = $conditional->right;
            } else {
                throw new UnexpectedValueException('$count_equality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = Expression_Identifier::get_extended_var_id($count_expr->get_args()[0]->value, $this_class_name, $source);
            if ($source instanceof Statements_Analyzer) {
                $var_type = $source->node_data->get_type($conditional->left);
                $other_type = $source->node_data->get_type($conditional->right);
                if ($codebase && $other_type && $var_type && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                    self::handle_paradoxical_assertions($source, $var_type, $this_class_name, $other_type, $codebase, $conditional);
                }
            }
            if ($var_name) {
                if ($count > 0) {
                    $if_types[$var_name] = [[new Has_Exact_Count($count)]];
                } else {
                    $if_types[$var_name] = [[new Not_Non_Empty_Countable()]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if (!$source instanceof Statements_Analyzer) {
            return [];
        }
        $getclass_position = self::has_get_class_check($conditional, $source);
        if ($getclass_position) {
            return self::get_getclass_equality_assertions($conditional, $this_class_name, $source, $getclass_position);
        }
        $typed_value_position = self::has_typed_value_comparison($conditional, $source);
        if ($typed_value_position) {
            return self::get_typed_value_equality_assertions($conditional, $this_class_name, $source, $codebase, $typed_value_position);
        }
        $var_type = $source->node_data->get_type($conditional->left);
        $other_type = $source->node_data->get_type($conditional->right);
        if ($codebase && $var_type && $other_type && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $var_type, $other_type)) {
                Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($var_type->get_id() . ' cannot be identical to ' . $other_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $other_type->get_id()), $source->get_suppressed_issues());
            } else {
                // both side of the Identical can be asserted to the intersection of both
                $intersection_type = Type::intersect_union_types($var_type, $other_type, $codebase, false, false);
                if ($intersection_type !== null) {
                    $if_types = [];
                    $var_name_left = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
                    $var_assertion_different = $var_type->get_id() !== $intersection_type->get_id();
                    $all_assertions = [];
                    foreach ($intersection_type->get_atomic_types() as $atomic_type) {
                        $all_assertions[] = new Is_Identical($atomic_type);
                    }
                    if ($var_name_left && $var_assertion_different) {
                        $if_types[$var_name_left] = [$all_assertions];
                    }
                    $var_name_right = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
                    $other_assertion_different = $other_type->get_id() !== $intersection_type->get_id();
                    if ($var_name_right && $other_assertion_different) {
                        $if_types[$var_name_right] = [$all_assertions];
                    }
                    return $if_types ? [$if_types] : [];
                }
            }
        }
        return [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function scrape_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase = null, bool $cache = true, bool $inside_conditional = true): array
    {
        $null_position = self::has_null_variable($conditional, $source);
        if ($null_position !== null) {
            return self::get_null_inequality_assertions($conditional, $source, $this_class_name, $codebase, $null_position);
        }
        $false_position = self::has_false_variable($conditional);
        if ($false_position) {
            return self::get_false_inequality_assertions($conditional, $this_class_name, $source, $codebase, $false_position, $cache, $inside_conditional);
        }
        $true_position = self::has_true_variable($conditional);
        if ($true_position) {
            return self::get_true_inequality_assertions($conditional, $this_class_name, $source, $codebase, $true_position, $cache, $inside_conditional);
        }
        $empty_array_position = self::has_empty_array_variable($conditional);
        if ($empty_array_position !== null) {
            return self::get_empty_inequality_assertions($conditional, $this_class_name, $source, $codebase, $empty_array_position);
        }
        $gettype_position = self::has_get_type_check($conditional);
        if ($gettype_position) {
            return self::get_gettype_inequality_assertions($conditional, $this_class_name, $source, $gettype_position);
        }
        $get_debug_type_position = self::has_get_debug_type_check($conditional);
        if ($get_debug_type_position) {
            return self::get_getdebug_type_inequality_assertions($conditional, $this_class_name, $source, $get_debug_type_position);
        }
        $count = null;
        $count_inequality_position = self::has_count_equality_check($conditional, $count);
        if ($count_inequality_position) {
            $if_types = [];
            if ($count_inequality_position === self::ASSIGNMENT_TO_RIGHT) {
                $count_expr = $conditional->left;
            } elseif ($count_inequality_position === self::ASSIGNMENT_TO_LEFT) {
                $count_expr = $conditional->right;
            } else {
                throw new UnexpectedValueException('$count_inequality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = Expression_Identifier::get_extended_var_id($count_expr->get_args()[0]->value, $this_class_name, $source);
            if ($source instanceof Statements_Analyzer) {
                $var_type = $source->node_data->get_type($conditional->left);
                $other_type = $source->node_data->get_type($conditional->right);
                if ($codebase && $other_type && $var_type && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                    self::handle_paradoxical_assertions($source, $var_type, $this_class_name, $other_type, $codebase, $conditional);
                }
            }
            if ($var_name) {
                if ($count > 0) {
                    $if_types[$var_name] = [[new Does_Not_Have_Exact_Count($count)]];
                } else {
                    $if_types[$var_name] = [[new Non_Empty_Countable(true)]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if (!$source instanceof Statements_Analyzer) {
            return [];
        }
        $getclass_position = self::has_get_class_check($conditional, $source);
        if ($getclass_position) {
            return self::get_getclass_inequality_assertions($conditional, $this_class_name, $source, $getclass_position);
        }
        $typed_value_position = self::has_typed_value_comparison($conditional, $source);
        if ($typed_value_position) {
            return self::get_typed_value_inequality_assertions($conditional, $this_class_name, $source, $codebase, $typed_value_position);
        }
        return [];
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    public static function process_function_call(Php_Parser\Node\Expr\Func_Call $expr, ?string $this_class_name, File_Source $source, ?Codebase $codebase = null, bool $negate = false): array
    {
        $first_var_name = isset($expr->get_args()[0]->value) ? Expression_Identifier::get_extended_var_id($expr->get_args()[0]->value, $this_class_name, $source) : null;
        $if_types = [];
        $first_var_type = isset($expr->get_args()[0]->value) && $source instanceof Statements_Analyzer ? $source->node_data->get_type($expr->get_args()[0]->value) : null;
        if ($tmp_if_types = self::handle_is_type_check($codebase, $source, $expr, $first_var_name, $first_var_type, $expr, $negate)) {
            $if_types = $tmp_if_types;
        } elseif ($source instanceof Statements_Analyzer && self::has_is_a_check($expr, $source)) {
            return self::get_isa_assertions($expr, $source, $this_class_name, $first_var_name);
        } elseif (self::has_callable_check($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[new Is_Type(new T_Callable())]];
            } elseif ($expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Array_ && isset($expr->get_args()[0]->value->items[0], $expr->get_args()[0]->value->items[1]) && $expr->get_args()[0]->value->items[1]->value instanceof Php_Parser\Node\Scalar\String_) {
                $first_var_name_in_array_argument = Expression_Identifier::get_extended_var_id($expr->get_args()[0]->value->items[0]->value, $this_class_name, $source);
                if ($first_var_name_in_array_argument) {
                    $if_types[$first_var_name_in_array_argument] = [[new Has_Method($expr->get_args()[0]->value->items[1]->value->value)]];
                }
            }
        } elseif ($class_exists_check_type = self::has_class_exists_check($expr)) {
            if ($first_var_name) {
                $class_string_type = new T_Class_String('object', null, $class_exists_check_type === 1);
                $if_types[$first_var_name] = [[new Is_Type($class_string_type)]];
            }
        } elseif ($class_exists_check_type = self::has_trait_exists_check($expr)) {
            if ($first_var_name) {
                if ($class_exists_check_type === 2) {
                    $if_types[$first_var_name] = [[new Is_Type(new T_Trait_String())]];
                } else {
                    $if_types[$first_var_name] = [[new Is_Identical(new T_Trait_String())]];
                }
            }
        } elseif (self::has_enum_exists_check($expr)) {
            if ($first_var_name) {
                $class_string = new T_Class_String('object', null, false, false, true);
                $if_types[$first_var_name] = [[new Is_Type($class_string)]];
            }
        } elseif (self::has_interface_exists_check($expr)) {
            if ($first_var_name) {
                $class_string = new T_Class_String('object', null, false, true, false);
                $if_types[$first_var_name] = [[new Is_Type($class_string)]];
            }
        } elseif (self::has_function_exists_check($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[new Is_Type(new T_Callable_String())]];
            }
        } elseif ($expr->name instanceof Php_Parser\Node\Name && strtolower($expr->name->get_first()) === 'method_exists' && isset($expr->get_args()[1]) && $expr->get_args()[1]->value instanceof Php_Parser\Node\Scalar\String_) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[new Has_Method($expr->get_args()[1]->value->value)]];
            }
        } elseif (self::has_in_array_check($expr) && $source instanceof Statements_Analyzer) {
            return self::get_inarray_assertions($expr, $source, $first_var_name);
        } elseif (self::has_array_key_exists_check($expr)) {
            return self::get_array_key_exists_assertions($expr, $first_var_type, $first_var_name, $source, $this_class_name, $codebase && $codebase->literal_array_key_check);
        } elseif (self::has_non_empty_count_check($expr)) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[new Non_Empty_Countable(true)]];
            }
        } else {
            return self::process_custom_assertion($expr, $this_class_name, $source);
        }
        return $if_types ? [$if_types] : [];
    }
    private static function process_irreconcilable_function_call(Union $first_var_type, Union $expected_type, Php_Parser\Node\Expr $expr, Statements_Analyzer $source, Codebase $codebase, bool $negate): void
    {
        if ($first_var_type->has_mixed()) {
            return;
        }
        if (!Union_Type_Comparator::is_contained_by($codebase, $first_var_type, $expected_type)) {
            return;
        }
        if (!$negate) {
            if ($first_var_type->from_docblock) {
                Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Docblock type ' . $first_var_type . ' always contains ' . $expected_type, new Code_Location($source, $expr), $first_var_type . ' ' . $expected_type), $source->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Redundant_Condition($first_var_type . ' always contains ' . $expected_type, new Code_Location($source, $expr), $first_var_type . ' ' . $expected_type), $source->get_suppressed_issues());
            }
        } else if ($first_var_type->from_docblock) {
            Issue_Buffer::maybe_add(new Docblock_Type_Contradiction('Docblock type !' . $first_var_type . ' does not contain ' . $expected_type, new Code_Location($source, $expr), $first_var_type . ' ' . $expected_type), $source->get_suppressed_issues());
        } else {
            Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type('!' . $first_var_type . ' does not contain ' . $expected_type, new Code_Location($source, $expr), $first_var_type . ' ' . $expected_type), $source->get_suppressed_issues());
        }
    }
    /**
     * @param  PhpParser\Node\Expr\FuncCall|PhpParser\Node\Expr\MethodCall|PhpParser\Node\Expr\StaticCall $expr
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function process_custom_assertion(Php_Parser\Node\Expr $expr, ?string $this_class_name, File_Source $source): array
    {
        if (!$source instanceof Statements_Analyzer) {
            return [];
        }
        $if_true_assertions = $source->node_data->get_if_true_assertions($expr);
        $if_false_assertions = $source->node_data->get_if_false_assertions($expr);
        if ($if_true_assertions === null && $if_false_assertions === null) {
            return [];
        }
        $first_var_name = isset($expr->get_args()[0]->value) ? Expression_Identifier::get_extended_var_id($expr->get_args()[0]->value, $this_class_name, $source) : null;
        $anded_types = [];
        if ($if_true_assertions) {
            foreach ($if_true_assertions as $assertion) {
                $if_types = [];
                $new_rules = [];
                foreach ($assertion->rule as $rule) {
                    $rule_type = $rule->get_atomic_type();
                    if ($rule_type instanceof T_Class_Constant) {
                        $codebase = $source->get_codebase();
                        $new_rules[] = $rule->set_atomic_type(Type_Expander::expand_atomic($codebase, $rule_type, null, null, null)[0]);
                    } else {
                        $new_rules[] = $rule;
                    }
                }
                $assertion = new Possibilities($assertion->var_id, $new_rules);
                if (is_int($assertion->var_id) && isset($expr->get_args()[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = Expression_Identifier::get_extended_var_id($expr->get_args()[$assertion->var_id]->value, $this_class_name, $source);
                    }
                    if ($var_name) {
                        $if_types[$var_name] = [[$assertion->rule[0]]];
                    }
                } elseif ($assertion->var_id === '$this') {
                    if (!$expr instanceof Php_Parser\Node\Expr\Method_Call) {
                        Issue_Buffer::maybe_add(new Invalid_Docblock('Assertion of $this can be done only on method of a class', new Code_Location($source, $expr)));
                        continue;
                    }
                    $var_id = Expression_Identifier::get_extended_var_id($expr->var, $this_class_name, $source);
                    if ($var_id) {
                        $if_types[$var_id] = [[$assertion->rule[0]]];
                    }
                } elseif (is_string($assertion->var_id)) {
                    $is_function = str_ends_with($assertion->var_id, '()');
                    $exploded_id = explode('->', $assertion->var_id);
                    $var_id = $exploded_id[0] ?? null;
                    $property = $exploded_id[1] ?? null;
                    if (is_numeric($var_id) && null !== $property && !$is_function) {
                        $var_id_int = (int) $var_id;
                        assert($var_id_int >= 0);
                        $args = $expr->get_args();
                        if (!array_key_exists($var_id_int, $args)) {
                            Issue_Buffer::maybe_add(new Invalid_Docblock('Variable ' . $var_id . ' is not an argument so cannot be asserted', new Code_Location($source, $expr)));
                            continue;
                        }
                        $arg_value = $args[$var_id_int]->value;
                        assert($arg_value instanceof Php_Parser\Node\Expr\Variable);
                        $arg_var_id = Expression_Identifier::get_extended_var_id($arg_value, null, $source);
                        if (null === $arg_var_id) {
                            Issue_Buffer::maybe_add(new Invalid_Docblock('Variable being asserted as argument ' . ($var_id + 1) . ' cannot be found
                                    in local scope', new Code_Location($source, $expr)));
                            continue;
                        }
                        if (count($exploded_id) === 2) {
                            $failed_message = self::is_property_immutable_on_argument($property, $source->get_node_type_provider(), $source->get_codebase()->classlike_storage_provider, $arg_value);
                            if (null !== $failed_message) {
                                Issue_Buffer::maybe_add(new Invalid_Docblock($failed_message, new Code_Location($source, $expr)));
                                continue;
                            }
                        }
                        $assertion_var_id = str_replace($var_id, $arg_var_id, $assertion->var_id);
                    } elseif (!$expr instanceof Php_Parser\Node\Expr\Func_Call) {
                        $assertion_var_id = $assertion->var_id;
                        if (str_starts_with($assertion_var_id, 'self::')) {
                            $assertion_var_id = $this_class_name . '::' . substr($assertion_var_id, 6);
                        }
                    } else {
                        Issue_Buffer::maybe_add(new Invalid_Docblock(sprintf('Assertion of variable "%s" cannot be recognized', $assertion->var_id), new Code_Location($source, $expr)));
                        continue;
                    }
                    $if_types[$assertion_var_id] = [[$assertion->rule[0]]];
                }
                if ($if_types) {
                    $anded_types[] = $if_types;
                }
            }
        }
        if ($if_false_assertions) {
            foreach ($if_false_assertions as $assertion) {
                $if_types = [];
                $new_rules = [];
                foreach ($assertion->rule as $rule) {
                    $rule_type = $rule->get_atomic_type();
                    if ($rule_type instanceof T_Class_Constant) {
                        $codebase = $source->get_codebase();
                        $new_rules[] = $rule->set_atomic_type(Type_Expander::expand_atomic($codebase, $rule_type, null, null, null)[0]);
                    } else {
                        $new_rules[] = $rule;
                    }
                }
                $assertion = new Possibilities($assertion->var_id, $new_rules);
                if (is_int($assertion->var_id) && isset($expr->get_args()[$assertion->var_id])) {
                    if ($assertion->var_id === 0) {
                        $var_name = $first_var_name;
                    } else {
                        $var_name = Expression_Identifier::get_extended_var_id($expr->get_args()[$assertion->var_id]->value, $this_class_name, $source);
                    }
                    if ($var_name) {
                        $if_types[$var_name] = [[$assertion->rule[0]->get_negation()]];
                    }
                } elseif ($assertion->var_id === '$this' && $expr instanceof Php_Parser\Node\Expr\Method_Call) {
                    $var_id = Expression_Identifier::get_extended_var_id($expr->var, $this_class_name, $source);
                    if ($var_id) {
                        $if_types[$var_id] = [[$assertion->rule[0]->get_negation()]];
                    }
                } elseif (is_string($assertion->var_id)) {
                    $is_function = str_ends_with($assertion->var_id, '()');
                    $exploded_id = explode('->', $assertion->var_id);
                    $var_id = $exploded_id[0] ?? null;
                    $property = $exploded_id[1] ?? null;
                    if (is_numeric($var_id) && null !== $property && !$is_function) {
                        $args = $expr->get_args();
                        $var_id_int = (int) $var_id;
                        if (!array_key_exists($var_id_int, $args)) {
                            Issue_Buffer::maybe_add(new Invalid_Docblock('Variable ' . $var_id . ' is not an argument so cannot be asserted', new Code_Location($source, $expr)));
                            continue;
                        }
                        /** @var PhpParser\Node\Expr\Variable $arg_value */
                        $arg_value = $args[$var_id_int]->value;
                        $arg_var_id = Expression_Identifier::get_extended_var_id($arg_value, null, $source);
                        if (null === $arg_var_id) {
                            Issue_Buffer::maybe_add(new Invalid_Docblock('Variable being asserted as argument ' . ($var_id + 1) . ' cannot be found
                                     in local scope', new Code_Location($source, $expr)));
                            continue;
                        }
                        if (count($exploded_id) === 2) {
                            $failed_message = self::is_property_immutable_on_argument($property, $source->get_node_type_provider(), $source->get_codebase()->classlike_storage_provider, $arg_value);
                            if (null !== $failed_message) {
                                Issue_Buffer::maybe_add(new Invalid_Docblock($failed_message, new Code_Location($source, $expr)));
                                continue;
                            }
                        }
                        $rule = $assertion->rule[0]->get_negation();
                        $assertion_var_id = str_replace($var_id, $arg_var_id, $assertion->var_id);
                        $if_types[$assertion_var_id] = [[$rule]];
                    } elseif (!$expr instanceof Php_Parser\Node\Expr\Func_Call) {
                        $var_id = $assertion->var_id;
                        if (str_starts_with($var_id, 'self::')) {
                            $var_id = $this_class_name . '::' . substr($var_id, 6);
                        }
                        $if_types[$var_id] = [[$assertion->rule[0]->get_negation()]];
                    } else {
                        Issue_Buffer::maybe_add(new Invalid_Docblock(sprintf('Assertion of variable "%s" cannot be recognized', $assertion->var_id), new Code_Location($source, $expr)));
                    }
                }
                if ($if_types) {
                    $anded_types[] = $if_types;
                }
            }
        }
        return $anded_types;
    }
    /**
     * @return list<Assertion>
     */
    private static function get_instance_of_assertions(Php_Parser\Node\Expr\Instanceof_ $stmt, ?string $this_class_name, File_Source $source): array
    {
        if ($stmt->class instanceof Php_Parser\Node\Name) {
            if (!in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
                $instanceof_class = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $source->get_aliases());
                if ($source instanceof Statements_Analyzer) {
                    $codebase = $source->get_codebase();
                    $instanceof_class = $codebase->classlikes->get_un_aliased_name($instanceof_class);
                }
                return [new Is_Type(T_Named_Object::create_from_name($instanceof_class))];
            }
            if ($this_class_name !== null && in_array(strtolower($stmt->class->get_first()), ['self', 'static'], true)) {
                $is_static = $stmt->class->get_first() === 'static';
                $named_object = new T_Named_Object($this_class_name, $is_static);
                if ($is_static) {
                    return [new Is_Identical($named_object)];
                }
                return [new Is_Type($named_object)];
            }
        } elseif ($stmt->class instanceof Php_Parser\Node\Expr\Variable && $stmt->class->name === 'this') {
            if ($this_class_name !== null) {
                $named_object = new T_Named_Object($this_class_name, true);
                return [new Is_Identical($named_object)];
            }
        } elseif ($source instanceof Statements_Analyzer) {
            $stmt_class_type = $source->node_data->get_type($stmt->class);
            if ($stmt_class_type) {
                $literal_class_strings = [];
                foreach ($stmt_class_type->get_atomic_types() as $atomic_type) {
                    if ($atomic_type instanceof T_Literal_Class_String) {
                        $literal_class_strings[] = new Is_Type(new T_Named_Object($atomic_type->value));
                    } elseif ($atomic_type instanceof T_Template_Param_Class) {
                        $literal_class_strings[] = new Is_Type(new T_Template_Param($atomic_type->param_name, new Union([$atomic_type->as_type ?: new T_Object()]), $atomic_type->defining_class));
                    } elseif ($atomic_type instanceof T_Class_String && $atomic_type->as !== 'object') {
                        $literal_class_strings[] = new Is_Type($atomic_type->as_type ?: new T_Named_Object($atomic_type->as));
                    }
                }
                return $literal_class_strings;
            }
        }
        return [];
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     */
    private static function has_null_variable(Php_Parser\Node\Expr\Binary_Op $conditional, File_Source $source): ?int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->right->name->get_first()) === 'null') {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->left->name->get_first()) === 'null') {
            return self::ASSIGNMENT_TO_LEFT;
        }
        if ($source instanceof Statements_Analyzer && ($right_type = $source->node_data->get_type($conditional->right)) && $right_type->is_null()) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        return null;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     */
    public static function has_false_variable(Php_Parser\Node\Expr\Binary_Op $conditional): ?int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->right->name->get_first()) === 'false') {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->left->name->get_first()) === 'false') {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return null;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     */
    public static function has_true_variable(Php_Parser\Node\Expr\Binary_Op $conditional): ?int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->right->name->get_first()) === 'true') {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($conditional->left->name->get_first()) === 'true') {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return null;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     */
    private static function has_empty_array_variable(Php_Parser\Node\Expr\Binary_Op $conditional): ?int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Array_ && !$conditional->right->items) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Array_ && !$conditional->left->items) {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return null;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     * @return false|int
     */
    private static function has_get_type_check(Php_Parser\Node\Expr\Binary_Op $conditional): bool|int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && strtolower($conditional->right->name->get_first()) === 'gettype' && $conditional->right->get_args() && $conditional->left instanceof Php_Parser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && strtolower($conditional->left->name->get_first()) === 'gettype' && $conditional->left->get_args() && $conditional->right instanceof Php_Parser\Node\Scalar\String_) {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     * @return false|int
     */
    private static function has_get_debug_type_check(Php_Parser\Node\Expr\Binary_Op $conditional): bool|int
    {
        if ($conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && strtolower($conditional->right->name->get_first()) === 'get_debug_type' && $conditional->right->get_args() && ($conditional->left instanceof Php_Parser\Node\Scalar\String_ || $conditional->left instanceof Php_Parser\Node\Expr\Class_Const_Fetch)) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if ($conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && strtolower($conditional->left->name->get_first()) === 'get_debug_type' && $conditional->left->get_args() && ($conditional->right instanceof Php_Parser\Node\Scalar\String_ || $conditional->right instanceof Php_Parser\Node\Expr\Class_Const_Fetch)) {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     * @return false|int
     */
    private static function has_get_class_check(Php_Parser\Node\Expr\Binary_Op $conditional, File_Source $source): bool|int
    {
        if (!$source instanceof Statements_Analyzer) {
            return false;
        }
        $right_get_class = $conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && strtolower($conditional->right->name->get_first()) === 'get_class';
        $right_static_class = $conditional->right instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $conditional->right->class instanceof Php_Parser\Node\Name && $conditional->right->class->get_parts() === ['static'] && $conditional->right->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->right->name->name) === 'class';
        $right_variable_class_const = $conditional->right instanceof Php_Parser\Node\Expr\Class_Const_Fetch && !$conditional->right->class instanceof Php_Parser\Node\Name && $conditional->right->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->right->name->name) === 'class';
        $left_class_string = $conditional->left instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $conditional->left->class instanceof Php_Parser\Node\Name && $conditional->left->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->left->name->name) === 'class';
        $left_variable_class_const = $conditional->left instanceof Php_Parser\Node\Expr\Class_Const_Fetch && !$conditional->left->class instanceof Php_Parser\Node\Name && $conditional->left->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->left->name->name) === 'class';
        $left_class_string_t = false;
        if (!$left_variable_class_const) {
            $left_type = $source->node_data->get_type($conditional->left);
            if ($left_type && $left_type->is_single()) {
                foreach ($left_type->get_atomic_types() as $type_part) {
                    if ($type_part instanceof T_Class_String) {
                        $left_class_string_t = true;
                        break;
                    }
                }
            }
        }
        if (($right_get_class || $right_static_class || $right_variable_class_const) && ($left_class_string || $left_class_string_t)) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        $left_get_class = $conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && strtolower($conditional->left->name->get_first()) === 'get_class';
        $left_static_class = $conditional->left instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $conditional->left->class instanceof Php_Parser\Node\Name && $conditional->left->class->get_parts() === ['static'] && $conditional->left->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->left->name->name) === 'class';
        $right_class_string = $conditional->right instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $conditional->right->class instanceof Php_Parser\Node\Name && $conditional->right->name instanceof Php_Parser\Node\Identifier && strtolower($conditional->right->name->name) === 'class';
        $right_class_string_t = false;
        if (!$right_variable_class_const) {
            $right_type = $source->node_data->get_type($conditional->right);
            if ($right_type && $right_type->is_single()) {
                foreach ($right_type->get_atomic_types() as $type_part) {
                    if ($type_part instanceof T_Class_String) {
                        $right_class_string_t = true;
                        break;
                    }
                }
            }
        }
        if (($left_get_class || $left_static_class || $left_variable_class_const) && ($right_class_string || $right_class_string_t)) {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param Greater|GreaterOrEqual|Smaller|SmallerOrEqual $conditional
     * @return false|int
     */
    private static function has_non_empty_count_equality_check(Php_Parser\Node\Expr\Binary_Op $conditional, ?int &$min_count): bool|int
    {
        if ($conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->left->name->get_first()), ['count', 'sizeof']) && $conditional->left->get_args() && ($conditional instanceof Binary_Op\Greater || $conditional instanceof Binary_Op\Greater_Or_Equal)) {
            $assignment_to = self::ASSIGNMENT_TO_RIGHT;
            $compare_to = $conditional->right;
            $comparison_adjustment = $conditional instanceof Binary_Op\Greater ? 1 : 0;
        } elseif ($conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->right->name->get_first()), ['count', 'sizeof']) && $conditional->right->get_args() && ($conditional instanceof Binary_Op\Smaller || $conditional instanceof Binary_Op\Smaller_Or_Equal)) {
            $assignment_to = self::ASSIGNMENT_TO_LEFT;
            $compare_to = $conditional->left;
            $comparison_adjustment = $conditional instanceof Binary_Op\Smaller ? 1 : 0;
        } else {
            return false;
        }
        // TODO get node type provider here somehow and check literal ints and int ranges
        if ($compare_to instanceof Php_Parser\Node\Scalar\Int_ && $compare_to->value > -1 * $comparison_adjustment) {
            $min_count = $compare_to->value + $comparison_adjustment;
            return $assignment_to;
        }
        return false;
    }
    /**
     * @param Greater|GreaterOrEqual|Smaller|SmallerOrEqual $conditional
     * @return false|int
     */
    private static function has_less_than_count_equality_check(Php_Parser\Node\Expr\Binary_Op $conditional, ?int &$max_count): bool|int
    {
        $left_count = $conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->left->name->get_first()), ['count', 'sizeof']) && $conditional->left->get_args();
        $operator_less_than_or_equal = $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Smaller;
        if ($left_count && $operator_less_than_or_equal && $conditional->right instanceof Php_Parser\Node\Scalar\Int_) {
            $max_count = $conditional->right->value - ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Smaller ? 1 : 0);
            return self::ASSIGNMENT_TO_RIGHT;
        }
        $right_count = $conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->right->name->get_first()), ['count', 'sizeof']) && $conditional->right->get_args();
        $operator_greater_than_or_equal = $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater;
        if ($right_count && $operator_greater_than_or_equal && $conditional->left instanceof Php_Parser\Node\Scalar\Int_) {
            $max_count = $conditional->left->value - ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater ? 1 : 0);
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param Equal|Identical|NotEqual|NotIdentical $conditional
     * @return false|int
     */
    private static function has_count_equality_check(Php_Parser\Node\Expr\Binary_Op $conditional, ?int &$count): bool|int
    {
        $left_count = $conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->left->name->get_first()), ['count', 'sizeof']) && $conditional->left->get_args();
        if ($left_count && $conditional->right instanceof Php_Parser\Node\Scalar\Int_) {
            $count = $conditional->right->value;
            return self::ASSIGNMENT_TO_RIGHT;
        }
        $right_count = $conditional->right instanceof Php_Parser\Node\Expr\Func_Call && $conditional->right->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->right->name->get_first()), ['count', 'sizeof']) && $conditional->right->get_args();
        if ($right_count && $conditional->left instanceof Php_Parser\Node\Scalar\Int_) {
            $count = $conditional->left->value;
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Greater|PhpParser\Node\Expr\BinaryOp\GreaterOrEqual $conditional
     * @return false|int
     */
    private static function has_superior_number_check(File_Source $source, Php_Parser\Node\Expr\Binary_Op $conditional, ?int &$literal_value_comparison): bool|int
    {
        $right_assignment = false;
        $value_right = null;
        if ($source instanceof Statements_Analyzer && ($type = $source->node_data->get_type($conditional->right)) && $type->is_single_int_literal()) {
            $right_assignment = true;
            $value_right = $type->get_single_int_literal()->value;
        } elseif ($conditional->right instanceof Int_) {
            $right_assignment = true;
            $value_right = $conditional->right->value;
        } elseif ($conditional->right instanceof Unary_Minus && $conditional->right->expr instanceof Int_) {
            $right_assignment = true;
            $value_right = -$conditional->right->expr->value;
        } elseif ($conditional->right instanceof Unary_Plus && $conditional->right->expr instanceof Int_) {
            $right_assignment = true;
            $value_right = $conditional->right->expr->value;
        }
        if ($right_assignment === true && $value_right !== null) {
            $literal_value_comparison = $value_right;
            return self::ASSIGNMENT_TO_RIGHT;
        }
        $left_assignment = false;
        $value_left = null;
        if ($source instanceof Statements_Analyzer && ($type = $source->node_data->get_type($conditional->left)) && $type->is_single_int_literal()) {
            $left_assignment = true;
            $value_left = $type->get_single_int_literal()->value;
        } elseif ($conditional->left instanceof Int_) {
            $left_assignment = true;
            $value_left = $conditional->left->value;
        } elseif ($conditional->left instanceof Unary_Minus && $conditional->left->expr instanceof Int_) {
            $left_assignment = true;
            $value_left = -$conditional->left->expr->value;
        } elseif ($conditional->left instanceof Unary_Plus && $conditional->left->expr instanceof Int_) {
            $left_assignment = true;
            $value_left = $conditional->left->expr->value;
        }
        if ($left_assignment === true && $value_left !== null) {
            $literal_value_comparison = $value_left;
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Smaller|PhpParser\Node\Expr\BinaryOp\SmallerOrEqual $conditional
     * @return false|int
     */
    private static function has_inferior_number_check(File_Source $source, Php_Parser\Node\Expr\Binary_Op $conditional, ?int &$literal_value_comparison): bool|int
    {
        $right_assignment = false;
        $value_right = null;
        if ($source instanceof Statements_Analyzer && ($type = $source->node_data->get_type($conditional->right)) && $type->is_single_int_literal()) {
            $right_assignment = true;
            $value_right = $type->get_single_int_literal()->value;
        } elseif ($conditional->right instanceof Int_) {
            $right_assignment = true;
            $value_right = $conditional->right->value;
        } elseif ($conditional->right instanceof Unary_Minus && $conditional->right->expr instanceof Int_) {
            $right_assignment = true;
            $value_right = -$conditional->right->expr->value;
        } elseif ($conditional->right instanceof Unary_Plus && $conditional->right->expr instanceof Int_) {
            $right_assignment = true;
            $value_right = $conditional->right->expr->value;
        }
        if ($right_assignment === true && $value_right !== null) {
            $literal_value_comparison = $value_right;
            return self::ASSIGNMENT_TO_RIGHT;
        }
        $left_assignment = false;
        $value_left = null;
        if ($source instanceof Statements_Analyzer && ($type = $source->node_data->get_type($conditional->left)) && $type->is_single_int_literal()) {
            $left_assignment = true;
            $value_left = $type->get_single_int_literal()->value;
        } elseif ($conditional->left instanceof Int_) {
            $left_assignment = true;
            $value_left = $conditional->left->value;
        } elseif ($conditional->left instanceof Unary_Minus && $conditional->left->expr instanceof Int_) {
            $left_assignment = true;
            $value_left = -$conditional->left->expr->value;
        } elseif ($conditional->left instanceof Unary_Plus && $conditional->left->expr instanceof Int_) {
            $left_assignment = true;
            $value_left = $conditional->left->expr->value;
        }
        if ($left_assignment === true && $value_left !== null) {
            $literal_value_comparison = $value_left;
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Greater|PhpParser\Node\Expr\BinaryOp\GreaterOrEqual $conditional
     * @return false|int
     */
    private static function has_reconcilable_non_empty_count_equality_check(Php_Parser\Node\Expr\Binary_Op $conditional): bool|int
    {
        $left_count = $conditional->left instanceof Php_Parser\Node\Expr\Func_Call && $conditional->left->name instanceof Php_Parser\Node\Name && in_array(strtolower($conditional->left->name->get_first()), ['count', 'sizeof']);
        $right_number = $conditional->right instanceof Php_Parser\Node\Scalar\Int_ && $conditional->right->value === ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Greater ? 0 : 1);
        if ($left_count && $right_number) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        return false;
    }
    /**
     * @param Identical|Equal|NotIdentical|NotEqual $conditional
     * @return false|int
     */
    private static function has_typed_value_comparison(Php_Parser\Node\Expr\Binary_Op $conditional, File_Source $source): bool|int
    {
        if (!$source instanceof Statements_Analyzer) {
            return false;
        }
        if (($right_type = $source->node_data->get_type($conditional->right)) && (!$conditional->right instanceof Php_Parser\Node\Expr\Variable && !$conditional->right instanceof Php_Parser\Node\Expr\Property_Fetch && !$conditional->right instanceof Php_Parser\Node\Expr\Static_Property_Fetch || $conditional->left instanceof Php_Parser\Node\Expr\Variable || $conditional->left instanceof Php_Parser\Node\Expr\Property_Fetch || $conditional->left instanceof Php_Parser\Node\Expr\Static_Property_Fetch) && count($right_type->get_atomic_types()) === 1 && !$right_type->has_mixed()) {
            return self::ASSIGNMENT_TO_RIGHT;
        }
        if (($left_type = $source->node_data->get_type($conditional->left)) && !$conditional->left instanceof Php_Parser\Node\Expr\Variable && !$conditional->left instanceof Php_Parser\Node\Expr\Property_Fetch && !$conditional->left instanceof Php_Parser\Node\Expr\Static_Property_Fetch && count($left_type->get_atomic_types()) === 1 && !$left_type->has_mixed()) {
            return self::ASSIGNMENT_TO_LEFT;
        }
        return false;
    }
    private static function has_is_a_check(Php_Parser\Node\Expr\Func_Call $stmt, Statements_Analyzer $source): bool
    {
        if ($stmt->name instanceof Php_Parser\Node\Name && (strtolower($stmt->name->get_first()) === 'is_a' || strtolower($stmt->name->get_first()) === 'is_subclass_of') && isset($stmt->get_args()[1])) {
            $second_arg = $stmt->get_args()[1]->value;
            if ($second_arg instanceof Php_Parser\Node\Scalar\String_ || $second_arg instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $second_arg->class instanceof Php_Parser\Node\Name && $second_arg->name instanceof Php_Parser\Node\Identifier && strtolower($second_arg->name->name) === 'class' || ($second_arg_type = $source->node_data->get_type($second_arg)) && $second_arg_type->has_string()) {
                return true;
            }
        }
        return false;
    }
    private static function get_is_assertion(string $function_name): ?Assertion
    {
        return match ($function_name) {
            'is_string' => new Is_Type(new Atomic\T_String()),
            'is_int', 'is_integer', 'is_long' => new Is_Type(new Atomic\T_Int()),
            'is_float', 'is_double', 'is_real' => new Is_Type(new Atomic\T_Float()),
            'is_scalar' => new Is_Type(new Atomic\T_Scalar()),
            'is_bool' => new Is_Type(new Atomic\T_Bool()),
            'is_resource' => new Is_Type(new Atomic\T_Resource()),
            'is_object' => new Is_Type(new Atomic\T_Object()),
            'array_is_list' => new Is_Type(Type::get_list_atomic(Type::get_mixed())),
            'is_array' => new Is_Type(new Atomic\T_Array([Type::get_array_key(), Type::get_mixed()])),
            'is_numeric' => new Is_Type(new Atomic\T_Numeric()),
            'is_null' => new Is_Type(new Atomic\T_Null()),
            'is_iterable' => new Is_Type(new Atomic\T_Iterable()),
            'is_countable' => new Is_Countable(),
            'ctype_digit' => new Is_Type(new Atomic\T_Numeric_String()),
            'ctype_lower' => new Is_Type(new Atomic\T_Non_Empty_Lowercase_String()),
            default => null,
        };
    }
    /**
     * @return array<string, non-empty-list<non-empty-list<Assertion>>>
     */
    private static function handle_is_type_check(?Codebase $codebase, File_Source $source, Php_Parser\Node\Expr\Func_Call $stmt, ?string $first_var_name, ?Union $first_var_type, Php_Parser\Node\Expr\Func_Call $expr, bool $negate): array
    {
        $if_types = [];
        if ($stmt->name instanceof Php_Parser\Node\Name && ($function_name = strtolower($stmt->name->get_first())) && ($assertion_type = self::get_is_assertion($function_name)) && $source instanceof Statements_Analyzer && ($source->get_namespace() === null || $stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified || isset($source->get_aliases()->functions[$function_name]) || $codebase && !$codebase->functions->function_exists($source, strtolower($source->get_namespace() . "\\" . $function_name)))) {
            if ($first_var_name) {
                $if_types[$first_var_name] = [[$assertion_type]];
            } elseif ($first_var_type && $codebase && $assertion_type instanceof Is_Type) {
                self::process_irreconcilable_function_call($first_var_type, new Union([$assertion_type->type]), $expr, $source, $codebase, $negate);
            }
        }
        return $if_types;
    }
    private static function has_callable_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'is_callable';
    }
    /**
     * @return Reconciler::RECONCILIATION_*
     */
    private static function has_class_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): int
    {
        if ($stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'class_exists') {
            if (!isset($stmt->get_args()[1])) {
                return 2;
            }
            $second_arg = $stmt->get_args()[1]->value;
            if ($second_arg instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($second_arg->name->get_first()) === 'true') {
                return 2;
            }
            return 1;
        }
        return 0;
    }
    /**
     * @return  0|1|2
     */
    private static function has_trait_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): int
    {
        if ($stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'trait_exists') {
            if (!isset($stmt->get_args()[1])) {
                return 2;
            }
            $second_arg = $stmt->get_args()[1]->value;
            if ($second_arg instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($second_arg->name->get_first()) === 'true') {
                return 2;
            }
            return 1;
        }
        return 0;
    }
    private static function has_enum_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'enum_exists';
    }
    private static function has_interface_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'interface_exists';
    }
    private static function has_function_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'function_exists';
    }
    private static function has_in_array_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        if ($stmt->name instanceof Php_Parser\Node\Name && strtolower($stmt->name->get_first()) === 'in_array' && isset($stmt->get_args()[2])) {
            $second_arg = $stmt->get_args()[2]->value;
            if ($second_arg instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($second_arg->name->get_first()) === 'true') {
                return true;
            }
        }
        return false;
    }
    private static function has_non_empty_count_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && in_array(strtolower($stmt->name->get_first()), ['count', 'sizeof']);
    }
    private static function has_array_key_exists_check(Php_Parser\Node\Expr\Func_Call $stmt): bool
    {
        return $stmt->name instanceof Php_Parser\Node\Name && (strtolower($stmt->name->get_first()) === 'array_key_exists' || strtolower($stmt->name->get_first()) === 'key_exists');
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_null_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, File_Source $source, ?string $this_class_name, ?Codebase $codebase, int $null_position): array
    {
        $if_types = [];
        if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('Bad null variable position');
        }
        $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
        if ($var_name) {
            if ($base_conditional instanceof Php_Parser\Node\Expr\Assign) {
                $var_name = '=' . $var_name;
            }
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $if_types[$var_name] = [[new Is_Not_Type(new T_Null())]];
            } else {
                $if_types[$var_name] = [[new Truthy()]];
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && $var_type = $source->node_data->get_type($base_conditional)) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $null_type = Type::get_null();
                if (!Union_Type_Comparator::is_contained_by($codebase, $var_type, $null_type) && !Union_Type_Comparator::is_contained_by($codebase, $null_type, $var_type)) {
                    if ($var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Docblock-defined type ' . $var_type . ' can never contain null', new Code_Location($source, $conditional), $var_type->get_id() . ' null'), $source->get_suppressed_issues());
                    } else {
                        Issue_Buffer::maybe_add(new Redundant_Condition($var_type . ' can never contain null', new Code_Location($source, $conditional), $var_type->get_id() . ' null'), $source->get_suppressed_issues());
                    }
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_false_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $false_position, bool $cache, bool $inside_conditional): array
    {
        $if_types = [];
        if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('Bad false variable position');
        }
        $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
        if ($var_name) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $if_types[$var_name] = [[new Is_Not_Type(new T_False())]];
            } else {
                $if_types[$var_name] = [[new Truthy()]];
            }
            $if_types = [$if_types];
        } else {
            $if_types = null;
            if ($source instanceof Statements_Analyzer && $cache) {
                $if_types = $source->node_data->get_assertions($base_conditional);
            }
            if ($if_types === null) {
                $if_types = self::scrape_assertions($base_conditional, $this_class_name, $source, $codebase, false, $cache, $inside_conditional);
                if ($source instanceof Statements_Analyzer && $cache) {
                    $source->node_data->set_assertions($base_conditional, $if_types);
                }
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($base_conditional)) && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
            $config = $source->get_codebase()->config;
            if (!$config->allow_bool_to_literal_bool_comparison && $var_type->is_single() && $var_type->has_bool() && !$var_type->from_docblock && !$conditional instanceof Virtual_Not_Identical) {
                Issue_Buffer::maybe_add(new Redundant_Identity_With_True('The "!== false" part of this comparison is redundant', new Code_Location($source, $conditional)), $source->get_suppressed_issues());
            }
            $false_type = Type::get_false();
            if (!Union_Type_Comparator::is_contained_by($codebase, $var_type, $false_type) && !Union_Type_Comparator::is_contained_by($codebase, $false_type, $var_type)) {
                if ($var_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Docblock-defined type ' . $var_type . ' can never contain false', new Code_Location($source, $conditional), $var_type->get_id() . ' false'), $source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Redundant_Condition($var_type . ' can never contain false', new Code_Location($source, $conditional), $var_type->get_id() . ' false'), $source->get_suppressed_issues());
                }
            }
        }
        return $if_types;
    }
    /**
     * @psalm-suppress MoreSpecificReturnType
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_true_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $true_position, bool $cache, bool $inside_conditional): array
    {
        $if_types = [];
        if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('Bad null variable position');
        }
        if ($base_conditional instanceof Php_Parser\Node\Expr\Func_Call) {
            $notif_types = self::process_function_call($base_conditional, $this_class_name, $source, $codebase, true);
        } else {
            $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
            if ($var_name) {
                if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                    $if_types[$var_name] = [[new Is_Not_Type(new T_True())]];
                } else {
                    $if_types[$var_name] = [[new Falsy()]];
                }
                $notif_types = [];
            } else {
                $notif_types = null;
                if ($source instanceof Statements_Analyzer && $cache) {
                    $notif_types = $source->node_data->get_assertions($base_conditional);
                }
                if ($notif_types === null) {
                    $notif_types = self::scrape_assertions($base_conditional, $this_class_name, $source, $codebase, false, $cache, $inside_conditional);
                    if ($source instanceof Statements_Analyzer && $cache) {
                        $source->node_data->set_assertions($base_conditional, $notif_types);
                    }
                }
            }
        }
        if (count($notif_types) === 1) {
            $notif_type = $notif_types[0];
            if (count($notif_type) === 1) {
                $if_types = Algebra::negate_types($notif_type);
            }
        }
        $if_types = $if_types ? [$if_types] : [];
        if ($if_types === [] && count($notif_types) === 2) {
            $check_var_assertion = null;
            $check_var = null;
            foreach ($notif_types as $notif_type) {
                foreach ($notif_type as $var => $assertions) {
                    if (count($assertions) !== 1 || count($assertions[0]) !== 1) {
                        $if_types = [];
                        break 2;
                    }
                    $is_not_assertion = $assertions[0][0] instanceof Is_Not_Type ? true : false;
                    if (!isset($check_var)) {
                        $check_var_assertion = $is_not_assertion;
                        $check_var = $var;
                        continue;
                    }
                    // only if we have 1 IsType and 1 IsNotType assertion for same variable
                    if ($check_var !== $var || !isset($check_var_assertion) || $check_var_assertion === $is_not_assertion) {
                        $if_types = [];
                        break 2;
                    }
                }
                $if_types[] = Algebra::negate_types($notif_type);
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && $var_type = $source->node_data->get_type($base_conditional)) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $true_type = Type::get_true();
                if (!Union_Type_Comparator::is_contained_by($codebase, $var_type, $true_type) && !Union_Type_Comparator::is_contained_by($codebase, $true_type, $var_type)) {
                    if ($var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Docblock-defined type ' . $var_type . ' can never contain true', new Code_Location($source, $conditional), $var_type->get_id() . ' true'), $source->get_suppressed_issues());
                    } else {
                        Issue_Buffer::maybe_add(new Redundant_Condition($var_type . ' can never contain ' . $true_type, new Code_Location($source, $conditional), $var_type->get_id() . ' true'), $source->get_suppressed_issues());
                    }
                }
            }
        }
        /** @psalm-suppress LessSpecificReturnStatement */
        return $if_types;
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_empty_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $empty_array_position): array
    {
        $if_types = [];
        if ($empty_array_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($empty_array_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('Bad empty array variable position');
        }
        $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
        if ($var_name) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $if_types[$var_name] = [[new Non_Empty_Countable(true)]];
            } else {
                $if_types[$var_name] = [[new Truthy()]];
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && $var_type = $source->node_data->get_type($base_conditional)) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                $empty_array_type = Type::get_empty_array();
                if (!Union_Type_Comparator::is_contained_by($codebase, $var_type, $empty_array_type) && !Union_Type_Comparator::is_contained_by($codebase, $empty_array_type, $var_type)) {
                    if ($var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type('Docblock-defined type ' . $var_type->get_id() . ' can never contain null', new Code_Location($source, $conditional), $var_type->get_id() . ' null'), $source->get_suppressed_issues());
                    } else {
                        Issue_Buffer::maybe_add(new Redundant_Condition($var_type->get_id() . ' can never contain null', new Code_Location($source, $conditional), $var_type->get_id() . ' null'), $source->get_suppressed_issues());
                    }
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_gettype_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, int $gettype_position): array
    {
        $if_types = [];
        if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
            $whichclass_expr = $conditional->left;
            $gettype_expr = $conditional->right;
        } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
            $whichclass_expr = $conditional->right;
            $gettype_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$gettype_position value');
        }
        /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
        $var_name = Expression_Identifier::get_extended_var_id($gettype_expr->get_args()[0]->value, $this_class_name, $source);
        if ($whichclass_expr instanceof Php_Parser\Node\Scalar\String_) {
            $var_type = $whichclass_expr->value;
        } elseif ($whichclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $whichclass_expr->class instanceof Php_Parser\Node\Name) {
            $var_type = Class_Like_Analyzer::get_fqcln_from_name_object($whichclass_expr->class, $source->get_aliases());
        } else {
            throw new UnexpectedValueException('Shouldn’t get here');
        }
        if (!isset(Class_Like_Analyzer::GETTYPE_TYPES[$var_type])) {
            Issue_Buffer::maybe_add(new Unevaluated_Code('gettype cannot return this value', new Code_Location($source, $whichclass_expr)));
        } else if ($var_name && $var_type) {
            if ($var_type === 'class@anonymous') {
                $if_types[$var_name] = [[new Is_Not_Identical(new T_Object())]];
            } elseif ($var_type === 'resource (closed)') {
                $if_types[$var_name] = [[new Is_Not_Type(new T_Closed_Resource())]];
            } elseif (str_starts_with($var_type, 'resource (')) {
                $if_types[$var_name] = [[new Is_Not_Identical(new T_Resource())]];
            } else {
                $if_types[$var_name] = [[new Is_Not_Type(Atomic::create($var_type))]];
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_getdebug_type_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, int $get_debug_type_position): array
    {
        $if_types = [];
        if ($get_debug_type_position === self::ASSIGNMENT_TO_RIGHT) {
            $whichclass_expr = $conditional->left;
            $get_debug_type_expr = $conditional->right;
        } elseif ($get_debug_type_position === self::ASSIGNMENT_TO_LEFT) {
            $whichclass_expr = $conditional->right;
            $get_debug_type_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$gettype_position value');
        }
        /** @var PhpParser\Node\Expr\FuncCall $get_debug_type_expr */
        $var_name = Expression_Identifier::get_extended_var_id($get_debug_type_expr->get_args()[0]->value, $this_class_name, $source);
        if ($whichclass_expr instanceof Php_Parser\Node\Scalar\String_) {
            $var_type = $whichclass_expr->value;
        } elseif ($whichclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $whichclass_expr->class instanceof Php_Parser\Node\Name) {
            $var_type = Class_Like_Analyzer::get_fqcln_from_name_object($whichclass_expr->class, $source->get_aliases());
        } else {
            throw new UnexpectedValueException('Shouldn’t get here');
        }
        if ($var_name && $var_type) {
            if ($var_type === 'class@anonymous') {
                $if_types[$var_name] = [[new Is_Not_Identical(new T_Object())]];
            } elseif ($var_type === 'resource (closed)') {
                $if_types[$var_name] = [[new Is_Not_Type(new T_Closed_Resource())]];
            } elseif (str_starts_with($var_type, 'resource (')) {
                $if_types[$var_name] = [[new Is_Not_Identical(new T_Resource())]];
            } else {
                $if_types[$var_name] = [[new Is_Not_Type(Atomic::create($var_type))]];
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_getclass_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, Statements_Analyzer $source, int $getclass_position): array
    {
        $if_types = [];
        if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
            $whichclass_expr = $conditional->left;
            $getclass_expr = $conditional->right;
        } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
            $whichclass_expr = $conditional->right;
            $getclass_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$getclass_position value');
        }
        if ($getclass_expr instanceof Php_Parser\Node\Expr\Func_Call) {
            $var_name = Expression_Identifier::get_extended_var_id($getclass_expr->get_args()[0]->value, $this_class_name, $source);
        } elseif ($getclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $getclass_expr->class instanceof Php_Parser\Node\Expr) {
            $var_name = Expression_Identifier::get_extended_var_id($getclass_expr->class, $this_class_name, $source);
        } else {
            $var_name = '$this';
        }
        if ($whichclass_expr instanceof Php_Parser\Node\Scalar\String_) {
            $var_type = $whichclass_expr->value;
        } elseif ($whichclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $whichclass_expr->class instanceof Php_Parser\Node\Name) {
            $var_type = Class_Like_Analyzer::get_fqcln_from_name_object($whichclass_expr->class, $source->get_aliases());
            if ($var_type === 'self' || $var_type === 'static') {
                $var_type = $this_class_name;
            } elseif ($var_type === 'parent') {
                $var_type = null;
            }
        } else {
            $type = $source->node_data->get_type($whichclass_expr);
            if ($type && $var_name) {
                foreach ($type->get_atomic_types() as $type_part) {
                    if ($type_part instanceof T_Template_Param_Class) {
                        $if_types[$var_name] = [[new Is_Not_Identical(new T_Template_Param($type_part->param_name, new Union([$type_part->as_type ?: new T_Object()]), $type_part->defining_class))]];
                    }
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if (!$var_type || Class_Like_Analyzer::check_fully_qualified_class_like_name($source, $var_type, new Code_Location($source, $whichclass_expr), null, null, $source->get_suppressed_issues()) !== false) {
            if ($var_name && $var_type) {
                $if_types[$var_name] = [[new Is_Class_Not_Equal($var_type)]];
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\NotIdentical|PhpParser\Node\Expr\BinaryOp\NotEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_typed_value_inequality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, Statements_Analyzer $source, ?Codebase $codebase, int $typed_value_position): array
    {
        $if_types = [];
        if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
            $other_type = $source->node_data->get_type($conditional->left);
            $var_type = $source->node_data->get_type($conditional->right);
        } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
            $var_type = $source->node_data->get_type($conditional->left);
            $other_type = $source->node_data->get_type($conditional->right);
        } else {
            throw new UnexpectedValueException('$typed_value_position value');
        }
        if ($var_type) {
            if ($var_name) {
                $not_identical = $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical || $other_type && ($var_type->is_int() && $other_type->is_int() || $var_type->is_float() && $other_type->is_float());
                $anded_types = [];
                foreach ($var_type->get_atomic_types() as $atomic_var_type) {
                    if ($not_identical) {
                        $anded_types[] = [new Is_Not_Identical($atomic_var_type)];
                    } else {
                        $anded_types[] = [new Is_Not_Loosely_Equal($atomic_var_type)];
                    }
                }
                $if_types[$var_name] = $anded_types;
            }
            if ($codebase && $other_type && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical) {
                self::handle_paradoxical_assertions($source, $var_type, $this_class_name, $other_type, $codebase, $conditional);
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_null_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $null_position): array
    {
        $if_types = [];
        if ($null_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($null_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('$null_position value');
        }
        $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
        if ($var_name && $base_conditional instanceof Php_Parser\Node\Expr\Assign) {
            $var_name = '=' . $var_name;
        }
        if ($var_name) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                $if_types[$var_name] = [[new Is_Type(new T_Null())]];
            } else {
                $if_types[$var_name] = [[new Falsy()]];
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($base_conditional)) && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            $null_type = Type::get_null();
            if (!Union_Type_Comparator::is_contained_by($codebase, $var_type, $null_type) && !Union_Type_Comparator::is_contained_by($codebase, $null_type, $var_type)) {
                if ($var_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($var_type . ' does not contain null', new Code_Location($source, $conditional), $var_type . ' null'), $source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Null($var_type . ' does not contain null', new Code_Location($source, $conditional), $var_type->get_id()), $source->get_suppressed_issues());
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_true_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $true_position, bool $cache, bool $inside_conditional): array
    {
        $if_types = [];
        if ($true_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($true_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('Unrecognised position');
        }
        if ($base_conditional instanceof Php_Parser\Node\Expr\Func_Call) {
            $if_types = self::process_function_call($base_conditional, $this_class_name, $source, $codebase, false);
        } else {
            $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
            if ($var_name) {
                if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                    $if_types[$var_name] = [[new Is_Type(new T_True())]];
                } else {
                    $if_types[$var_name] = [[new Truthy()]];
                }
                $if_types = [$if_types];
            } else {
                $base_assertions = null;
                if ($source instanceof Statements_Analyzer && $cache) {
                    $base_assertions = $source->node_data->get_assertions($base_conditional);
                }
                if ($base_assertions === null) {
                    $base_assertions = self::scrape_assertions($base_conditional, $this_class_name, $source, $codebase, false, $cache, $inside_conditional);
                    if ($source instanceof Statements_Analyzer && $cache) {
                        $source->node_data->set_assertions($base_conditional, $base_assertions);
                    }
                }
                $if_types = $base_assertions;
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($base_conditional)) && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            $config = $source->get_codebase()->config;
            if (!$config->allow_bool_to_literal_bool_comparison && $var_type->is_single() && $var_type->has_bool() && !$var_type->from_docblock && !$conditional instanceof Virtual_Identical) {
                Issue_Buffer::maybe_add(new Redundant_Identity_With_True('The "=== true" part of this comparison is redundant', new Code_Location($source, $conditional)), $source->get_suppressed_issues());
            }
            $true_type = Type::get_true();
            if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $true_type, $var_type)) {
                if ($var_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($var_type . ' does not contain true', new Code_Location($source, $conditional), $var_type . ' true'), $source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($var_type . ' does not contain true', new Code_Location($source, $conditional), $var_type . ' true'), $source->get_suppressed_issues());
                }
            }
        }
        return $if_types;
    }
    /**
     * @psalm-suppress MoreSpecificReturnType
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_false_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $false_position, bool $cache, bool $inside_conditional): array
    {
        $if_types = [];
        if ($false_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($false_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('$false_position value');
        }
        if ($base_conditional instanceof Php_Parser\Node\Expr\Func_Call) {
            $notif_types = self::process_function_call($base_conditional, $this_class_name, $source, $codebase, true);
        } else {
            $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
            if ($var_name) {
                if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                    $if_types[$var_name] = [[new Is_Type(new T_False())]];
                } else {
                    $if_types[$var_name] = [[new Falsy()]];
                }
                $notif_types = [];
            } else {
                $notif_types = null;
                if ($source instanceof Statements_Analyzer && $cache) {
                    $notif_types = $source->node_data->get_assertions($base_conditional);
                }
                if ($notif_types === null) {
                    $notif_types = self::scrape_assertions($base_conditional, $this_class_name, $source, $codebase, false, $cache, $inside_conditional);
                    if ($source instanceof Statements_Analyzer && $cache) {
                        $source->node_data->set_assertions($base_conditional, $notif_types);
                    }
                }
            }
        }
        if (count($notif_types) === 1) {
            $notif_type = $notif_types[0];
            if (count($notif_type) === 1) {
                $if_types = Algebra::negate_types($notif_type);
            }
        }
        $if_types = $if_types ? [$if_types] : [];
        // @psalm-assert-if-true and @psalm-assert-if-false for same variable in same function, e.g. array/list cases
        // @todo optionally extend this to arbitrary number of assert-if cases of multiple variables in the function
        // same code above too
        if ($if_types === [] && count($notif_types) === 2) {
            $check_var_assertion = null;
            $check_var = null;
            foreach ($notif_types as $notif_type) {
                foreach ($notif_type as $var => $assertions) {
                    if (count($assertions) !== 1 || count($assertions[0]) !== 1) {
                        $if_types = [];
                        break 2;
                    }
                    $is_not_assertion = $assertions[0][0] instanceof Is_Not_Type ? true : false;
                    if (!isset($check_var)) {
                        $check_var_assertion = $is_not_assertion;
                        $check_var = $var;
                        continue;
                    }
                    // only if we have 1 IsType and 1 IsNotType assertion for same variable
                    if ($check_var !== $var || !isset($check_var_assertion) || $check_var_assertion === $is_not_assertion) {
                        $if_types = [];
                        break 2;
                    }
                }
                $if_types[] = Algebra::negate_types($notif_type);
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && $var_type = $source->node_data->get_type($base_conditional)) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                $false_type = Type::get_false();
                if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $false_type, $var_type)) {
                    if ($var_type->from_docblock) {
                        Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($var_type . ' does not contain false', new Code_Location($source, $conditional), $var_type . ' false'), $source->get_suppressed_issues());
                    } else {
                        Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($var_type . ' does not contain false', new Code_Location($source, $conditional), $var_type . ' false'), $source->get_suppressed_issues());
                    }
                }
            }
        }
        /** @psalm-suppress LessSpecificReturnStatement */
        return $if_types;
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_empty_array_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase, int $empty_array_position): array
    {
        $if_types = [];
        if ($empty_array_position === self::ASSIGNMENT_TO_RIGHT) {
            $base_conditional = $conditional->left;
        } elseif ($empty_array_position === self::ASSIGNMENT_TO_LEFT) {
            $base_conditional = $conditional->right;
        } else {
            throw new UnexpectedValueException('$empty_array_position value');
        }
        $var_name = Expression_Identifier::get_extended_var_id($base_conditional, $this_class_name, $source);
        if ($var_name) {
            if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
                $if_types[$var_name] = [[new Not_Non_Empty_Countable()]];
            } else {
                $if_types[$var_name] = [[new Falsy()]];
            }
        }
        if ($codebase && $source instanceof Statements_Analyzer && ($var_type = $source->node_data->get_type($base_conditional)) && $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical) {
            $empty_array_type = Type::get_empty_array();
            if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $empty_array_type, $var_type)) {
                if ($var_type->from_docblock) {
                    Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($var_type . ' does not contain an empty array', new Code_Location($source, $conditional), $var_type . ' !== []'), $source->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($var_type . ' does not contain empty array', new Code_Location($source, $conditional), $var_type . ' !== []'), $source->get_suppressed_issues());
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_gettype_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, int $gettype_position): array
    {
        $if_types = [];
        if ($gettype_position === self::ASSIGNMENT_TO_RIGHT) {
            $string_expr = $conditional->left;
            $gettype_expr = $conditional->right;
        } elseif ($gettype_position === self::ASSIGNMENT_TO_LEFT) {
            $string_expr = $conditional->right;
            $gettype_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$gettype_position value');
        }
        /** @var PhpParser\Node\Expr\FuncCall $gettype_expr */
        $var_name = Expression_Identifier::get_extended_var_id($gettype_expr->get_args()[0]->value, $this_class_name, $source);
        /** @var PhpParser\Node\Scalar\String_ $string_expr */
        $var_type = $string_expr->value;
        if (!isset(Class_Like_Analyzer::GETTYPE_TYPES[$var_type])) {
            Issue_Buffer::maybe_add(new Unevaluated_Code('gettype cannot return this value', new Code_Location($source, $string_expr)));
        } else if ($var_name && $var_type) {
            if ($var_type === 'class@anonymous') {
                $if_types[$var_name] = [[new Is_Identical(new T_Object())]];
            } elseif ($var_type === 'resource (closed)') {
                $if_types[$var_name] = [[new Is_Type(new T_Closed_Resource())]];
            } elseif (str_starts_with($var_type, 'resource (')) {
                $if_types[$var_name] = [[new Is_Identical(new T_Resource())]];
            } elseif ($var_type === 'integer') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Int())]];
            } elseif ($var_type === 'double') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Float())]];
            } elseif ($var_type === 'boolean') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Bool())]];
            } else {
                $if_types[$var_name] = [[new Is_Type(Atomic::create($var_type))]];
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_getdebugtype_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, File_Source $source, int $get_debug_type_position): array
    {
        $if_types = [];
        if ($get_debug_type_position === self::ASSIGNMENT_TO_RIGHT) {
            $whichclass_expr = $conditional->left;
            $get_debug_type_expr = $conditional->right;
        } elseif ($get_debug_type_position === self::ASSIGNMENT_TO_LEFT) {
            $whichclass_expr = $conditional->right;
            $get_debug_type_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$gettype_position value');
        }
        /** @var PhpParser\Node\Expr\FuncCall $get_debug_type_expr */
        $var_name = Expression_Identifier::get_extended_var_id($get_debug_type_expr->get_args()[0]->value, $this_class_name, $source);
        if ($whichclass_expr instanceof Php_Parser\Node\Scalar\String_) {
            $var_type = $whichclass_expr->value;
        } elseif ($whichclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $whichclass_expr->class instanceof Php_Parser\Node\Name) {
            $var_type = Class_Like_Analyzer::get_fqcln_from_name_object($whichclass_expr->class, $source->get_aliases());
        } else {
            throw new UnexpectedValueException('Shouldn’t get here');
        }
        if ($var_name && $var_type) {
            if ($var_type === 'class@anonymous') {
                $if_types[$var_name] = [[new Is_Identical(new T_Object())]];
            } elseif ($var_type === 'resource (closed)') {
                $if_types[$var_name] = [[new Is_Type(new T_Closed_Resource())]];
            } elseif (str_starts_with($var_type, 'resource (')) {
                $if_types[$var_name] = [[new Is_Identical(new T_Resource())]];
            } elseif ($var_type === 'integer') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Int())]];
            } elseif ($var_type === 'double') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Float())]];
            } elseif ($var_type === 'boolean') {
                $if_types[$var_name] = [[new Is_Type(new Atomic\T_Bool())]];
            } else {
                $if_types[$var_name] = [[new Is_Type(Atomic::create($var_type))]];
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_getclass_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, Statements_Analyzer $source, int $getclass_position): array
    {
        $if_types = [];
        if ($getclass_position === self::ASSIGNMENT_TO_RIGHT) {
            $whichclass_expr = $conditional->left;
            $getclass_expr = $conditional->right;
        } elseif ($getclass_position === self::ASSIGNMENT_TO_LEFT) {
            $whichclass_expr = $conditional->right;
            $getclass_expr = $conditional->left;
        } else {
            throw new UnexpectedValueException('$getclass_position value');
        }
        if ($getclass_expr instanceof Php_Parser\Node\Expr\Func_Call && isset($getclass_expr->get_args()[0])) {
            $var_name = Expression_Identifier::get_extended_var_id($getclass_expr->get_args()[0]->value, $this_class_name, $source);
        } elseif ($getclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $getclass_expr->class instanceof Php_Parser\Node\Expr) {
            $var_name = Expression_Identifier::get_extended_var_id($getclass_expr->class, $this_class_name, $source);
        } else {
            $var_name = '$this';
        }
        if ($whichclass_expr instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $whichclass_expr->class instanceof Php_Parser\Node\Name) {
            $var_type = Class_Like_Analyzer::get_fqcln_from_name_object($whichclass_expr->class, $source->get_aliases());
            if ($var_type === 'self' || $var_type === 'static') {
                $var_type = $this_class_name;
            } elseif ($var_type === 'parent') {
                $var_type = null;
            }
            if ($var_type) {
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($source, $var_type, new Code_Location($source, $whichclass_expr), null, null, $source->get_suppressed_issues(), new Class_Like_Name_Options(true)) === false) {
                    return [];
                }
            }
            if ($var_name && $var_type) {
                $if_types[$var_name] = [[new Is_Class_Equal($var_type)]];
            }
        } else {
            $type = $source->node_data->get_type($whichclass_expr);
            if ($type && $var_name) {
                foreach ($type->get_atomic_types() as $type_part) {
                    if ($type_part instanceof T_Template_Param_Class) {
                        $if_types[$var_name] = [[new Is_Identical(new T_Template_Param($type_part->param_name, $type_part->as_type ? new Union([$type_part->as_type]) : Type::get_object(), $type_part->defining_class))]];
                    }
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Identical|PhpParser\Node\Expr\BinaryOp\Equal $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_typed_value_equality_assertions(Php_Parser\Node\Expr\Binary_Op $conditional, ?string $this_class_name, Statements_Analyzer $source, ?Codebase $codebase, int $typed_value_position): array
    {
        $if_types = [];
        if ($typed_value_position === self::ASSIGNMENT_TO_RIGHT) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
            $other_var_name = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
            $other_type = $source->node_data->get_type($conditional->left);
            $var_type = $source->node_data->get_type($conditional->right);
        } elseif ($typed_value_position === self::ASSIGNMENT_TO_LEFT) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
            $other_var_name = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
            $var_type = $source->node_data->get_type($conditional->left);
            $other_type = $source->node_data->get_type($conditional->right);
        } else {
            throw new UnexpectedValueException('$typed_value_position value');
        }
        //soit on a un === explicite, soit on compare des types strictement égaux
        $identical = $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $other_type && $var_type && ($var_type->is_string(true) && $other_type->is_string(true) || $var_type->is_int(true) && $other_type->is_int(true) || $var_type->is_float() && $other_type->is_float());
        if ($var_name && $var_type && !$var_type->is_mixed() && count($var_type->get_atomic_types()) === 1) {
            $orred_types = [];
            foreach ($var_type->get_atomic_types() as $atomic_var_type) {
                if ($identical) {
                    $orred_types[] = new Is_Identical($atomic_var_type);
                } else {
                    $orred_types[] = new Is_Loosely_Equal($atomic_var_type);
                }
            }
            $if_types[$var_name] = [$orred_types];
        }
        if ($other_var_name && $other_type && !$other_type->is_mixed() && count($other_type->get_atomic_types()) === 1 && $other_var_name[0] === '$') {
            $orred_types = [];
            foreach ($other_type->get_atomic_types() as $atomic_other_type) {
                if ($identical) {
                    $orred_types[] = new Is_Identical($atomic_other_type);
                } else {
                    $orred_types[] = new Is_Loosely_Equal($atomic_other_type);
                }
            }
            $if_types[$other_var_name] = [$orred_types];
        }
        if ($codebase && $other_type && $var_type && $identical) {
            self::handle_paradoxical_assertions($source, $var_type, $this_class_name, $other_type, $codebase, $conditional);
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_isa_assertions(Php_Parser\Node\Expr\Func_Call $expr, Statements_Analyzer $source, ?string $this_class_name, ?string $first_var_name): array
    {
        $if_types = [];
        if ($expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $expr->get_args()[0]->value->name instanceof Php_Parser\Node\Identifier && strtolower($expr->get_args()[0]->value->name->name) === 'class' && $expr->get_args()[0]->value->class instanceof Php_Parser\Node\Name && count($expr->get_args()[0]->value->class->get_parts()) === 1 && strtolower($expr->get_args()[0]->value->class->get_first()) === 'static') {
            $first_var_name = '$this';
        }
        if ($first_var_name) {
            $first_arg = $expr->get_args()[0]->value;
            $second_arg = $expr->get_args()[1]->value;
            $third_arg = $expr->get_args()[2]->value ?? null;
            if ($third_arg instanceof Php_Parser\Node\Expr\Const_Fetch) {
                if (!in_array(strtolower($third_arg->name->get_first()), ['true', 'false'])) {
                    return [];
                }
                $third_arg_value = strtolower($third_arg->name->get_first());
            } else {
                $third_arg_value = $expr->name instanceof Php_Parser\Node\Name && strtolower($expr->name->get_first()) === 'is_subclass_of' ? 'true' : 'false';
            }
            if (($first_arg_type = $source->node_data->get_type($first_arg)) && $first_arg_type->is_single_string_literal() && $source->get_source()->get_source() instanceof Trait_Analyzer && $first_arg_type->get_single_string_literal()->value === $this_class_name) {
                // do nothing
            } else if ($second_arg instanceof Php_Parser\Node\Scalar\String_) {
                $fq_class_name = $second_arg->value;
                if ($fq_class_name[0] === '\\') {
                    $fq_class_name = substr($fq_class_name, 1);
                }
                $obj = new T_Named_Object($fq_class_name);
                $if_types[$first_var_name] = [[new Is_A_Class($obj, $third_arg_value === 'true')]];
            } elseif ($second_arg instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $second_arg->class instanceof Php_Parser\Node\Name && $second_arg->name instanceof Php_Parser\Node\Identifier && strtolower($second_arg->name->name) === 'class') {
                $class_node = $second_arg->class;
                if ($class_node->get_parts() === ['static']) {
                    if ($this_class_name !== null) {
                        $object = new T_Named_Object($this_class_name, true);
                        $if_types[$first_var_name] = [[new Is_A_Class($object, $third_arg_value === 'true')]];
                    }
                } elseif ($class_node->get_parts() === ['self']) {
                    if ($this_class_name !== null) {
                        $object = new T_Named_Object($this_class_name);
                        $if_types[$first_var_name] = [[new Is_A_Class($object, $third_arg_value === 'true')]];
                    }
                } elseif ($class_node->get_parts() === ['parent']) {
                    // do nothing
                } else {
                    $object = new T_Named_Object(Class_Like_Analyzer::get_fqcln_from_name_object($class_node, $source->get_aliases()));
                    $if_types[$first_var_name] = [[new Is_A_Class($object, $third_arg_value === 'true')]];
                }
            } elseif (($second_arg_type = $source->node_data->get_type($second_arg)) && $second_arg_type->has_string()) {
                $vals = [];
                foreach ($second_arg_type->get_atomic_types() as $second_arg_atomic_type) {
                    if ($second_arg_atomic_type instanceof T_Template_Param_Class) {
                        $vals[] = [new Is_A_Class($second_arg_atomic_type, $third_arg_value === 'true')];
                    }
                }
                if ($vals) {
                    $if_types[$first_var_name] = $vals;
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_inarray_assertions(Php_Parser\Node\Expr\Func_Call $expr, Statements_Analyzer $source, ?string $first_var_name): array
    {
        $if_types = [];
        if ($first_var_name && ($second_arg_type = $source->node_data->get_type($expr->get_args()[1]->value)) && isset($expr->get_args()[0]->value) && !$expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch) {
            foreach ($second_arg_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Array || $atomic_type instanceof T_Keyed_Array) {
                    $is_sealed = false;
                    if ($atomic_type instanceof T_Keyed_Array) {
                        $value_type = $atomic_type->get_generic_value_type();
                        $is_sealed = $atomic_type->fallback_params === null;
                    } else {
                        $value_type = $atomic_type->type_params[1];
                    }
                    $assertions = [];
                    if (!$is_sealed) {
                        // `in-array-*` has special handling in the detection of paradoxical
                        // conditions and the fact the negation doesn't imply anything.
                        //
                        // In the vast majority of cases, the negation of `in-array-*`
                        // (`Algebra::negateType`) doesn't imply anything because:
                        // - The array can be empty, or
                        // - The array may have one of the types but not the others.
                        //
                        // NOTE: the negation of the negation is the original assertion.
                        if ($value_type->get_id() !== '' && !$value_type->is_mixed() && !$value_type->has_template()) {
                            $assertions[] = new In_Array($value_type);
                        }
                    } else {
                        foreach ($value_type->get_atomic_types() as $atomic_value_type) {
                            if ($atomic_value_type instanceof T_Literal_Int || $atomic_value_type instanceof T_Literal_String || $atomic_value_type instanceof T_Literal_Float || $atomic_value_type instanceof T_Enum_Case) {
                                $assertions[] = new Is_Identical($atomic_value_type);
                            } elseif ($atomic_value_type instanceof T_False || $atomic_value_type instanceof T_True || $atomic_value_type instanceof T_Null) {
                                $assertions[] = new Is_Type($atomic_value_type);
                            } elseif (!$atomic_value_type instanceof T_Mixed) {
                                // mixed doesn't tell us anything and can be omitted.
                                //
                                // For the meaning of in-array, see the above comment.
                                $assertions[] = new In_Array($value_type);
                            }
                        }
                    }
                    if ($assertions !== []) {
                        $if_types[$first_var_name] = [$assertions];
                    }
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_array_key_exists_assertions(Php_Parser\Node\Expr\Func_Call $expr, ?Union $first_var_type, ?string $first_var_name, File_Source $source, ?string $this_class_name, bool $check_literal_keys): array
    {
        if ($check_literal_keys && $first_var_type && $source instanceof Statements_Analyzer && $second_var_type = $source->node_data->get_type($expr->get_args()[1]->value)) {
            Array_Fetch_Analyzer::validate_array_offset($source, $expr, $second_var_type, $first_var_type);
        }
        $if_types = [];
        $literal_assertions = [];
        $safe_to_track_literals = true;
        if (isset($expr->get_args()[0]) && isset($expr->get_args()[1]) && $first_var_type && $first_var_name !== null && !$expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $source instanceof Statements_Analyzer && $second_var_type = $source->node_data->get_type($expr->get_args()[1]->value)) {
            foreach ($second_var_type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof T_Array || $atomic_type instanceof T_Keyed_Array) {
                    if ($atomic_type instanceof T_Keyed_Array) {
                        $key_type = $atomic_type->get_generic_key_type(!$atomic_type->all_shape_keys_always_defined());
                    } else {
                        $key_type = $atomic_type->type_params[0];
                    }
                    if ($key_type->all_string_literals() && !$key_type->possibly_undefined) {
                        foreach ($key_type->get_literal_strings() as $array_literal_type) {
                            $string_to_int = Array_Analyzer::get_literal_array_key_int($array_literal_type->value);
                            if ($string_to_int === false) {
                                $literal_assertions[] = new Is_Identical($array_literal_type);
                            } else {
                                $literal_assertions[] = new Is_Loosely_Equal(new T_Literal_Int($string_to_int));
                            }
                        }
                    } elseif ($key_type->all_int_literals() && !$key_type->possibly_undefined) {
                        foreach ($key_type->get_literal_ints() as $array_literal_type) {
                            $literal_assertions[] = new Is_Loosely_Equal($array_literal_type);
                        }
                    } else {
                        $safe_to_track_literals = false;
                    }
                }
            }
        }
        if ($literal_assertions && $first_var_name !== null && $safe_to_track_literals) {
            $if_types[$first_var_name] = [$literal_assertions];
        } else {
            $array_root = isset($expr->get_args()[1]->value) ? Expression_Identifier::get_extended_var_id($expr->get_args()[1]->value, $this_class_name, $source) : null;
            if ($array_root && isset($expr->get_args()[0])) {
                if ($first_var_name === null) {
                    $first_arg = $expr->get_args()[0];
                    if ($first_arg->value instanceof Php_Parser\Node\Scalar\String_) {
                        $string_to_int = Array_Analyzer::get_literal_array_key_int($first_arg->value->value);
                        $first_var_name = $string_to_int === false ? '\'' . $first_arg->value->value . '\'' : (string) $string_to_int;
                    } elseif ($first_arg->value instanceof Php_Parser\Node\Scalar\L_Number) {
                        $first_var_name = (string) $first_arg->value->value;
                    }
                }
                if ($expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $expr->get_args()[0]->value->name instanceof Php_Parser\Node\Identifier && $expr->get_args()[0]->value->name->name !== 'class') {
                    $const_type = null;
                    if ($source instanceof Statements_Analyzer) {
                        $const_type = $source->node_data->get_type($expr->get_args()[0]->value);
                    }
                    if ($const_type) {
                        if ($const_type->is_single_string_literal()) {
                            $string_to_int = Array_Analyzer::get_literal_array_key_int($const_type->get_single_string_literal()->value);
                            $first_var_name = $string_to_int === false ? '\'' . $const_type->get_single_string_literal()->value . '\'' : (string) $string_to_int;
                        } elseif ($const_type->is_single_int_literal()) {
                            $first_var_name = (string) $const_type->get_single_int_literal()->value;
                        } else {
                            $first_var_name = null;
                        }
                    } else {
                        $first_var_name = null;
                    }
                } elseif (($expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Variable || $expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Property_Fetch || $expr->get_args()[0]->value instanceof Php_Parser\Node\Expr\Static_Property_Fetch) && $source instanceof Statements_Analyzer && $first_var_type = $source->node_data->get_type($expr->get_args()[0]->value)) {
                    foreach ($first_var_type->get_literal_strings() as $array_literal_type) {
                        $string_to_int = Array_Analyzer::get_literal_array_key_int($array_literal_type->value);
                        $literal_key = $string_to_int === false ? "'" . $array_literal_type->value . "'" : $string_to_int;
                        $if_types[$array_root . "[" . $literal_key . "]"] = [[new Array_Key_Exists()]];
                    }
                    foreach ($first_var_type->get_literal_ints() as $array_literal_type) {
                        $if_types[$array_root . "[" . $array_literal_type->value . "]"] = [[new Array_Key_Exists()]];
                    }
                }
                if ($first_var_name !== null && !strpos($first_var_name, '->') && !strpos($first_var_name, '[')) {
                    $if_types[$array_root . '[' . $first_var_name . ']'] = [[new Array_Key_Exists()]];
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Greater|PhpParser\Node\Expr\BinaryOp\GreaterOrEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_greater_assertions(Php_Parser\Node\Expr $conditional, File_Source $source, ?string $this_class_name): array
    {
        $if_types = [];
        $min_count = null;
        $count_equality_position = self::has_non_empty_count_equality_check($conditional, $min_count);
        $max_count = null;
        $count_inequality_position = self::has_less_than_count_equality_check($conditional, $max_count);
        $superior_value_comparison = null;
        $superior_value_position = self::has_superior_number_check($source, $conditional, $superior_value_comparison);
        if ($count_equality_position) {
            if ($count_equality_position === self::ASSIGNMENT_TO_RIGHT) {
                $counted_expr = $conditional->left;
            } else {
                throw new UnexpectedValueException('$count_equality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $counted_expr */
            $var_name = Expression_Identifier::get_extended_var_id($counted_expr->get_args()[0]->value, $this_class_name, $source);
            if ($var_name) {
                if (self::has_reconcilable_non_empty_count_equality_check($conditional)) {
                    $if_types[$var_name] = [[new Non_Empty_Countable(true)]];
                } else if ($min_count > 0) {
                    $if_types[$var_name] = [[new Has_At_Least_Count($min_count)]];
                } else {
                    $if_types[$var_name] = [[new Non_Empty_Countable(false)]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if ($count_inequality_position) {
            if ($count_inequality_position === self::ASSIGNMENT_TO_LEFT) {
                $count_expr = $conditional->right;
            } else {
                throw new UnexpectedValueException('$count_inequality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = Expression_Identifier::get_extended_var_id($count_expr->get_args()[0]->value, $this_class_name, $source);
            if ($var_name) {
                if ($max_count > 0) {
                    $if_types[$var_name] = [[new Does_Not_Have_At_Least_Count($max_count + 1)]];
                } else {
                    $if_types[$var_name] = [[new Not_Non_Empty_Countable()]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if ($superior_value_position && $superior_value_comparison !== null) {
            if ($superior_value_position === self::ASSIGNMENT_TO_RIGHT) {
                $var_name = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
            } else {
                $var_name = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
            }
            if ($var_name !== null) {
                if ($superior_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    if ($conditional instanceof Greater_Or_Equal) {
                        $if_types[$var_name] = [[new Is_Greater_Than_Or_Equal_To($superior_value_comparison)]];
                    } else {
                        $if_types[$var_name] = [[new Is_Greater_Than($superior_value_comparison)]];
                    }
                } else if ($conditional instanceof Greater_Or_Equal) {
                    $if_types[$var_name] = [[new Is_Less_Than_Or_Equal_To($superior_value_comparison)]];
                } else {
                    $if_types[$var_name] = [[new Is_Less_Than($superior_value_comparison)]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        return [];
    }
    /**
     * @param PhpParser\Node\Expr\BinaryOp\Smaller|PhpParser\Node\Expr\BinaryOp\SmallerOrEqual $conditional
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_smaller_assertions(Php_Parser\Node\Expr $conditional, File_Source $source, ?string $this_class_name): array
    {
        $if_types = [];
        $min_count = null;
        $count_equality_position = self::has_non_empty_count_equality_check($conditional, $min_count);
        $max_count = null;
        $count_inequality_position = self::has_less_than_count_equality_check($conditional, $max_count);
        $inferior_value_comparison = null;
        $inferior_value_position = self::has_inferior_number_check($source, $conditional, $inferior_value_comparison);
        if ($count_equality_position) {
            if ($count_equality_position === self::ASSIGNMENT_TO_LEFT) {
                $count_expr = $conditional->right;
            } else {
                throw new UnexpectedValueException('$count_equality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = Expression_Identifier::get_extended_var_id($count_expr->get_args()[0]->value, $this_class_name, $source);
            if ($var_name) {
                if ($min_count > 0) {
                    $if_types[$var_name] = [[new Has_At_Least_Count($min_count)]];
                } else {
                    $if_types[$var_name] = [[new Non_Empty_Countable(false)]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if ($count_inequality_position) {
            if ($count_inequality_position === self::ASSIGNMENT_TO_RIGHT) {
                $count_expr = $conditional->left;
            } else {
                throw new UnexpectedValueException('$count_inequality_position value');
            }
            /** @var PhpParser\Node\Expr\FuncCall $count_expr */
            $var_name = Expression_Identifier::get_extended_var_id($count_expr->get_args()[0]->value, $this_class_name, $source);
            if ($var_name) {
                if ($max_count > 0) {
                    $if_types[$var_name] = [[new Does_Not_Have_At_Least_Count($max_count + 1)]];
                } else {
                    $if_types[$var_name] = [[new Not_Non_Empty_Countable()]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        if ($inferior_value_position) {
            if ($inferior_value_position === self::ASSIGNMENT_TO_RIGHT) {
                $var_name = Expression_Identifier::get_extended_var_id($conditional->left, $this_class_name, $source);
            } else {
                $var_name = Expression_Identifier::get_extended_var_id($conditional->right, $this_class_name, $source);
            }
            if ($var_name !== null && $inferior_value_comparison !== null) {
                if ($inferior_value_position === self::ASSIGNMENT_TO_RIGHT) {
                    if ($conditional instanceof Smaller_Or_Equal) {
                        $if_types[$var_name] = [[new Is_Less_Than_Or_Equal_To($inferior_value_comparison)]];
                    } else {
                        $if_types[$var_name] = [[new Is_Less_Than($inferior_value_comparison)]];
                    }
                } else if ($conditional instanceof Smaller_Or_Equal) {
                    $if_types[$var_name] = [[new Is_Greater_Than_Or_Equal_To($inferior_value_comparison)]];
                } else {
                    $if_types[$var_name] = [[new Is_Greater_Than($inferior_value_comparison)]];
                }
            }
            return $if_types ? [$if_types] : [];
        }
        return [];
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>
     */
    private static function get_and_check_instanceof_assertions(Php_Parser\Node\Expr\Instanceof_ $conditional, ?Codebase $codebase, File_Source $source, ?string $this_class_name, bool $inside_negation): array
    {
        $if_types = [];
        $instanceof_assertions = self::get_instance_of_assertions($conditional, $this_class_name, $source);
        if ($instanceof_assertions) {
            $var_name = Expression_Identifier::get_extended_var_id($conditional->expr, $this_class_name, $source);
            if ($var_name) {
                $if_types[$var_name] = [$instanceof_assertions];
                $var_type = $source instanceof Statements_Analyzer ? $source->node_data->get_type($conditional->expr) : null;
                foreach ($instanceof_assertions as $instanceof_assertion) {
                    $instanceof_type = $instanceof_assertion->get_atomic_type();
                    if (!$instanceof_type instanceof T_Named_Object) {
                        continue;
                    }
                    if ($codebase && $var_type && $inside_negation && $source instanceof Statements_Analyzer) {
                        if ($codebase->interface_exists($instanceof_type->value)) {
                            continue;
                        }
                        $instanceof_type = new Union([$instanceof_type]);
                        if (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $instanceof_type, $var_type)) {
                            if ($var_type->from_docblock) {
                                Issue_Buffer::maybe_add(new Redundant_Condition_Given_Docblock_Type($var_type->get_id() . ' does not contain ' . $instanceof_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $instanceof_type->get_id()), $source->get_suppressed_issues());
                            } else {
                                Issue_Buffer::maybe_add(new Redundant_Condition($var_type->get_id() . ' cannot be identical to ' . $instanceof_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $instanceof_type->get_id()), $source->get_suppressed_issues());
                            }
                        }
                    }
                }
            }
        }
        return $if_types ? [$if_types] : [];
    }
    /**
     * @param NotIdentical|NotEqual|Identical|Equal $conditional
     */
    private static function handle_paradoxical_assertions(Statements_Analyzer $source, Union $var_type, ?string $this_class_name, Union $other_type, Codebase $codebase, Php_Parser\Node\Expr\Binary_Op $conditional): void
    {
        $parent_source = $source->get_source();
        if ($parent_source->get_source() instanceof Trait_Analyzer && ($var_type->is_single_string_literal() && $var_type->get_single_string_literal()->value === $this_class_name || $other_type->is_single_string_literal() && $other_type->get_single_string_literal()->value === $this_class_name)) {
            // do nothing
        } elseif (!Union_Type_Comparator::can_expression_types_be_identical($codebase, $other_type, $var_type)) {
            if ($var_type->from_docblock || $other_type->from_docblock) {
                Issue_Buffer::maybe_add(new Docblock_Type_Contradiction($var_type->get_id() . ' does not contain ' . $other_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $other_type->get_id()), $source->get_suppressed_issues());
            } else if ($conditional instanceof Not_Equal || $conditional instanceof Not_Identical) {
                Issue_Buffer::maybe_add(new Redundant_Condition($var_type->get_id() . ' can never contain ' . $other_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $other_type->get_id()), $source->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Type_Does_Not_Contain_Type($var_type->get_id() . ' cannot be identical to ' . $other_type->get_id(), new Code_Location($source, $conditional), $var_type->get_id() . ' ' . $other_type->get_id()), $source->get_suppressed_issues());
            }
        }
    }
    public static function is_property_immutable_on_argument(string $property, Node_Data_Provider $node_provider, Class_Like_Storage_Provider $class_provider, Php_Parser\Node\Expr\Variable $arg_expr): ?string
    {
        $type = $node_provider->get_type($arg_expr);
        /** @var string $name */
        $name = $arg_expr->name;
        if (null === $type) {
            return 'Cannot resolve a type of variable ' . $name;
        }
        foreach ($type->get_atomic_types() as $type) {
            if (!$type instanceof T_Named_Object) {
                return 'Variable ' . $name . ' is not an object so the assertion cannot be applied';
            }
            $class_definition = $class_provider->get($type->value);
            $property_definition = $class_definition->properties[$property] ?? null;
            if (!$property_definition instanceof Property_Storage) {
                $magic_type = $class_definition->pseudo_property_get_types['$' . $property] ?? null;
                if ($magic_type === null) {
                    return sprintf('Property %s is not defined on variable %s so the assertion cannot be applied', $property, $name);
                }
                $magic_getter = $class_definition->methods['__get'] ?? null;
                if ($magic_getter === null || !$magic_getter->mutation_free) {
                    return "{$class_definition->name}::__get is not mutation-free, so the assertion cannot be applied";
                }
            }
        }
        return null;
    }
}