<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Call_Return_Type_Fetcher;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Issue\Invalid_Cast;
use Psalm\Issue\Possibly_Invalid_Cast;
use Psalm\Issue\Redundant_Cast;
use Psalm\Issue\Redundant_Cast_Given_Docblock_Type;
use Psalm\Issue\Risky_Cast;
use Psalm\Issue\Unrecognized_Expression;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\Scalar;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Closed_Resource;
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
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_Nonspecific_Literal_Int;
use Psalm\Type\Atomic\T_Nonspecific_Literal_String;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Numeric;
use Psalm\Type\Atomic\T_Numeric_String;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Resource;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Union;
use function array_merge;
use function array_pop;
use function array_values;
use function range;
use function strtolower;
/**
 * @internal
 */
final class Cast_Analyzer
{
    /** @var string[] */
    private const PSEUDO_CASTABLE_CLASSES = ['SimpleXMLElement', 'DOMNode', 'GMP', \Decimal\Decimal::class];
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Cast $stmt, Context $context): bool
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Int_) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $maybe_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($maybe_type) {
                if ($maybe_type->is_int()) {
                    if (!$maybe_type->from_calculation) {
                        self::handle_redundant_cast($maybe_type, $statements_analyzer, $stmt);
                    }
                }
                $type = self::cast_int_attempt($statements_analyzer, $maybe_type, $stmt->expr, true);
            } else {
                $type = Type::get_int();
            }
            $statements_analyzer->node_data->set_type($stmt, $type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Double) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $maybe_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($maybe_type) {
                if ($maybe_type->is_float()) {
                    self::handle_redundant_cast($maybe_type, $statements_analyzer, $stmt);
                }
                $type = self::cast_float_attempt($statements_analyzer, $maybe_type, $stmt->expr, true);
            } else {
                $type = Type::get_float();
            }
            $statements_analyzer->node_data->set_type($stmt, $type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Bool_) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $maybe_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($maybe_type) {
                if ($maybe_type->is_bool()) {
                    self::handle_redundant_cast($maybe_type, $statements_analyzer, $stmt);
                }
            }
            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                $type = new Union([new T_Bool()], ['parent_nodes' => $maybe_type->parent_nodes ?? []]);
            } else {
                $type = Type::get_bool();
            }
            $statements_analyzer->node_data->set_type($stmt, $type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\String_) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($stmt_expr_type) {
                if ($stmt_expr_type->is_string()) {
                    self::handle_redundant_cast($stmt_expr_type, $statements_analyzer, $stmt);
                }
                $stmt_type = self::cast_string_attempt($statements_analyzer, $context, $stmt_expr_type, $stmt->expr, true);
            } else {
                $stmt_type = Type::get_string();
            }
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Object_) {
            if (!self::check_expr_general_use($statements_analyzer, $stmt, $context)) {
                return false;
            }
            $permissible_atomic_types = [];
            $all_permissible = false;
            if ($stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
                if ($stmt_expr_type->is_object_type()) {
                    self::handle_redundant_cast($stmt_expr_type, $statements_analyzer, $stmt);
                }
                $all_permissible = true;
                foreach ($stmt_expr_type->get_atomic_types() as $type) {
                    if ($type instanceof Scalar) {
                        $obj_with_props = new T_Object_With_Properties(['scalar' => new Union([$type])]);
                        $permissible_atomic_types[] = $obj_with_props;
                    } elseif ($type instanceof T_Keyed_Array) {
                        $permissible_atomic_types[] = new T_Object_With_Properties($type->properties);
                    } else {
                        $all_permissible = false;
                        break;
                    }
                }
            }
            if ($permissible_atomic_types && $all_permissible) {
                $type = Type_Combiner::combine($permissible_atomic_types);
            } else {
                $type = Type::get_object();
            }
            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                $type = $type->set_parent_nodes($stmt_expr_type->parent_nodes ?? []);
            }
            $statements_analyzer->node_data->set_type($stmt, $type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Array_) {
            if (!self::check_expr_general_use($statements_analyzer, $stmt, $context)) {
                return false;
            }
            $permissible_atomic_types = [];
            $all_permissible = false;
            if ($stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
                if ($stmt_expr_type->is_array()) {
                    self::handle_redundant_cast($stmt_expr_type, $statements_analyzer, $stmt);
                }
                $all_permissible = true;
                foreach ($stmt_expr_type->get_atomic_types() as $type) {
                    if ($type instanceof Scalar) {
                        $keyed_array = new T_Keyed_Array([new Union([$type])], null, null, true);
                        $permissible_atomic_types[] = $keyed_array;
                    } elseif ($type instanceof T_Null) {
                        $permissible_atomic_types[] = new T_Array([Type::get_never(), Type::get_never()]);
                    } elseif ($type instanceof T_Array || $type instanceof T_Keyed_Array) {
                        $permissible_atomic_types[] = $type;
                    } elseif ($type instanceof T_Object_With_Properties) {
                        $array_type = $type->properties === [] ? Type::get_array_atomic() : new T_Keyed_Array($type->properties, null, [Type::get_array_key(), Type::get_mixed()]);
                        $permissible_atomic_types[] = $array_type;
                    } else {
                        $all_permissible = false;
                        break;
                    }
                }
            }
            if ($permissible_atomic_types && $all_permissible) {
                $type = Type_Combiner::combine($permissible_atomic_types);
            } else {
                $type = Type::get_array();
            }
            if ($statements_analyzer->data_flow_graph) {
                $type = $type->set_parent_nodes($stmt_expr_type->parent_nodes ?? []);
            }
            $statements_analyzer->node_data->set_type($stmt, $type);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast\Unset_ && $statements_analyzer->get_codebase()->analysis_php_version_id <= 70400) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $statements_analyzer->node_data->set_type($stmt, Type::get_null());
            return true;
        }
        Issue_Buffer::maybe_add(new Unrecognized_Expression('Psalm does not understand the cast ' . $stmt::class, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        return false;
    }
    public static function cast_int_attempt(Statements_Analyzer $statements_analyzer, Union $stmt_type, Php_Parser\Node\Expr $stmt, bool $explicit_cast = false): Union
    {
        $codebase = $statements_analyzer->get_codebase();
        $risky_cast = [];
        $invalid_casts = [];
        $valid_ints = [];
        $castable_types = [];
        $atomic_types = $stmt_type->get_atomic_types();
        $parent_nodes = [];
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
            $parent_nodes = $stmt_type->parent_nodes;
        }
        while ($atomic_types) {
            $atomic_type = array_pop($atomic_types);
            if ($atomic_type instanceof T_Int) {
                $valid_ints[] = $atomic_type;
                continue;
            }
            if ($atomic_type instanceof T_Float) {
                if ($atomic_type instanceof T_Literal_Float) {
                    $valid_ints[] = new T_Literal_Int((int) $atomic_type->value);
                } else {
                    $castable_types[] = new T_Int();
                }
                continue;
            }
            if ($atomic_type instanceof T_String) {
                if ($atomic_type instanceof T_Literal_String) {
                    $valid_ints[] = new T_Literal_Int((int) $atomic_type->value);
                } elseif ($atomic_type instanceof T_Numeric_String) {
                    $castable_types[] = new T_Int();
                } else {
                    // any normal string is technically $valid_int[] = new TLiteralInt(0);
                    // however we cannot be certain that it's not inferred, therefore less strict
                    $castable_types[] = new T_Int();
                }
                continue;
            }
            if ($atomic_type instanceof T_Null || $atomic_type instanceof T_False) {
                $valid_ints[] = new T_Literal_Int(0);
                continue;
            }
            if ($atomic_type instanceof T_True) {
                $valid_ints[] = new T_Literal_Int(1);
                continue;
            }
            if ($atomic_type instanceof T_Bool) {
                // do NOT use TIntRange here, as it will cause invalid behavior, e.g. bitwiseAssignment
                $valid_ints[] = new T_Literal_Int(0);
                $valid_ints[] = new T_Literal_Int(1);
                continue;
            }
            // could be invalid, but allow it, as it is allowed for TString below too
            if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Closed_Resource || $atomic_type instanceof T_Resource || $atomic_type instanceof Scalar) {
                $castable_types[] = new T_Int();
                continue;
            }
            if ($atomic_type instanceof T_Named_Object) {
                $intersection_types = [$atomic_type];
                if ($atomic_type->extra_types) {
                    $intersection_types = [...$intersection_types, ...$atomic_type->extra_types];
                }
                foreach ($intersection_types as $intersection_type) {
                    if (!$intersection_type instanceof T_Named_Object) {
                        continue;
                    }
                    // prevent "Could not get class storage for mixed"
                    if (!$codebase->class_exists($intersection_type->value)) {
                        continue;
                    }
                    foreach (self::PSEUDO_CASTABLE_CLASSES as $pseudo_castable_class) {
                        if (strtolower($intersection_type->value) === strtolower($pseudo_castable_class) || $codebase->class_extends($intersection_type->value, $pseudo_castable_class)) {
                            $castable_types[] = new T_Int();
                            continue 3;
                        }
                    }
                }
            }
            if ($atomic_type instanceof T_Non_Empty_Array || $atomic_type instanceof T_Keyed_Array && $atomic_type->is_non_empty()) {
                $risky_cast[] = $atomic_type->get_id();
                $valid_ints[] = new T_Literal_Int(1);
                continue;
            }
            if ($atomic_type instanceof T_Array || $atomic_type instanceof T_Keyed_Array) {
                // if type is not specific, it can be both 0 or 1, depending on whether the array has data or not
                // welcome to off-by-one hell if that happens :-)
                $risky_cast[] = $atomic_type->get_id();
                $valid_ints[] = new T_Literal_Int(0);
                $valid_ints[] = new T_Literal_Int(1);
                continue;
            }
            if ($atomic_type instanceof T_Template_Param) {
                $atomic_types = array_merge($atomic_types, $atomic_type->as->get_atomic_types());
                continue;
            }
            // always 1 for "error" cases
            $valid_ints[] = new T_Literal_Int(1);
            $invalid_casts[] = $atomic_type->get_id();
        }
        if ($invalid_casts) {
            Issue_Buffer::maybe_add(new Invalid_Cast($invalid_casts[0] . ' cannot be cast to int', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        } elseif ($risky_cast) {
            Issue_Buffer::maybe_add(new Risky_Cast('Casting ' . $risky_cast[0] . ' to int has possibly unintended value of 0/1', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        } elseif ($explicit_cast && !$castable_types) {
            // todo: emit error here
        }
        $valid_types = [...$valid_ints, ...$castable_types];
        if (!$valid_types) {
            $int_type = Type::get_int();
        } else {
            $int_type = Type_Combiner::combine($valid_types, $codebase);
        }
        if ($statements_analyzer->data_flow_graph) {
            return $int_type->set_parent_nodes($parent_nodes);
        }
        return $int_type;
    }
    public static function cast_float_attempt(Statements_Analyzer $statements_analyzer, Union $stmt_type, Php_Parser\Node\Expr $stmt, bool $explicit_cast = false): Union
    {
        $codebase = $statements_analyzer->get_codebase();
        $risky_cast = [];
        $invalid_casts = [];
        $valid_floats = [];
        $castable_types = [];
        $atomic_types = $stmt_type->get_atomic_types();
        $parent_nodes = [];
        if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
            $parent_nodes = $stmt_type->parent_nodes;
        }
        while ($atomic_types) {
            $atomic_type = array_pop($atomic_types);
            if ($atomic_type instanceof T_Float) {
                $valid_floats[] = $atomic_type;
                continue;
            }
            if ($atomic_type instanceof T_Int_Range && $atomic_type->min_bound !== null && $atomic_type->max_bound !== null && $atomic_type->max_bound - $atomic_type->min_bound < 500) {
                foreach (range($atomic_type->min_bound, $atomic_type->max_bound) as $literal_int_value) {
                    $valid_floats[] = new T_Literal_Float((float) $literal_int_value);
                }
                continue;
            }
            if ($atomic_type instanceof T_Int) {
                if ($atomic_type instanceof T_Literal_Int) {
                    $valid_floats[] = new T_Literal_Float((float) $atomic_type->value);
                } else {
                    $castable_types[] = new T_Float();
                }
                continue;
            }
            if ($atomic_type instanceof T_String) {
                if ($atomic_type instanceof T_Literal_String) {
                    $valid_floats[] = new T_Literal_Float((float) $atomic_type->value);
                } elseif ($atomic_type instanceof T_Numeric_String) {
                    $castable_types[] = new T_Float();
                } else {
                    // any normal string is technically $valid_floats[] = new TLiteralFloat(0.0);
                    // however we cannot be certain that it's not inferred, therefore less strict
                    $castable_types[] = new T_Float();
                }
                continue;
            }
            if ($atomic_type instanceof T_Null || $atomic_type instanceof T_False) {
                $valid_floats[] = new T_Literal_Float(0.0);
                continue;
            }
            if ($atomic_type instanceof T_True) {
                $valid_floats[] = new T_Literal_Float(1.0);
                continue;
            }
            if ($atomic_type instanceof T_Bool) {
                $valid_floats[] = new T_Literal_Float(0.0);
                $valid_floats[] = new T_Literal_Float(1.0);
                continue;
            }
            // could be invalid, but allow it, as it is allowed for TString below too
            if ($atomic_type instanceof T_Mixed || $atomic_type instanceof T_Closed_Resource || $atomic_type instanceof T_Resource || $atomic_type instanceof Scalar) {
                $castable_types[] = new T_Float();
                continue;
            }
            if ($atomic_type instanceof T_Named_Object) {
                $intersection_types = [$atomic_type];
                if ($atomic_type->extra_types) {
                    $intersection_types = [...$intersection_types, ...$atomic_type->extra_types];
                }
                foreach ($intersection_types as $intersection_type) {
                    if (!$intersection_type instanceof T_Named_Object) {
                        continue;
                    }
                    // prevent "Could not get class storage for mixed"
                    if (!$codebase->class_exists($intersection_type->value)) {
                        continue;
                    }
                    foreach (self::PSEUDO_CASTABLE_CLASSES as $pseudo_castable_class) {
                        if (strtolower($intersection_type->value) === strtolower($pseudo_castable_class) || $codebase->class_extends($intersection_type->value, $pseudo_castable_class)) {
                            $castable_types[] = new T_Float();
                            continue 3;
                        }
                    }
                }
            }
            if ($atomic_type instanceof T_Non_Empty_Array || $atomic_type instanceof T_Keyed_Array && $atomic_type->is_non_empty()) {
                $risky_cast[] = $atomic_type->get_id();
                $valid_floats[] = new T_Literal_Float(1.0);
                continue;
            }
            if ($atomic_type instanceof T_Array || $atomic_type instanceof T_Keyed_Array) {
                // if type is not specific, it can be both 0 or 1, depending on whether the array has data or not
                // welcome to off-by-one hell if that happens :-)
                $risky_cast[] = $atomic_type->get_id();
                $valid_floats[] = new T_Literal_Float(0.0);
                $valid_floats[] = new T_Literal_Float(1.0);
                continue;
            }
            if ($atomic_type instanceof T_Template_Param) {
                $atomic_types = array_merge($atomic_types, $atomic_type->as->get_atomic_types());
                continue;
            }
            // always 1.0 for "error" cases
            $valid_floats[] = new T_Literal_Float(1.0);
            $invalid_casts[] = $atomic_type->get_id();
        }
        if ($invalid_casts) {
            Issue_Buffer::maybe_add(new Invalid_Cast($invalid_casts[0] . ' cannot be cast to float', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        } elseif ($risky_cast) {
            Issue_Buffer::maybe_add(new Risky_Cast('Casting ' . $risky_cast[0] . ' to float has possibly unintended value of 0.0/1.0', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        } elseif ($explicit_cast && !$castable_types) {
            // todo: emit error here
        }
        $valid_types = [...$valid_floats, ...$castable_types];
        if (!$valid_types) {
            $float_type = Type::get_float();
        } else {
            $float_type = Type_Combiner::combine($valid_types, $codebase);
        }
        if ($statements_analyzer->data_flow_graph) {
            return $float_type->set_parent_nodes($parent_nodes);
        }
        return $float_type;
    }
    public static function cast_string_attempt(Statements_Analyzer $statements_analyzer, Context $context, Union $stmt_type, Php_Parser\Node\Expr $stmt, bool $explicit_cast = false): Union
    {
        $codebase = $statements_analyzer->get_codebase();
        $invalid_casts = [];
        $valid_strings = [];
        $castable_types = [];
        $atomic_types = $stmt_type->get_atomic_types();
        $parent_nodes = [];
        if ($statements_analyzer->data_flow_graph) {
            $parent_nodes = $stmt_type->parent_nodes;
        }
        while ($atomic_types) {
            $atomic_type = array_pop($atomic_types);
            if ($atomic_type instanceof T_Float || $atomic_type instanceof T_Int || $atomic_type instanceof T_Numeric) {
                if ($atomic_type instanceof T_Literal_Int || $atomic_type instanceof T_Literal_Float) {
                    $valid_strings[] = Type::get_atomic_string_from_literal((string) $atomic_type->value);
                } elseif ($atomic_type instanceof T_Nonspecific_Literal_Int) {
                    $castable_types[] = new T_Nonspecific_Literal_String();
                } elseif ($atomic_type instanceof T_Int_Range && $atomic_type->min_bound !== null && $atomic_type->max_bound !== null && $atomic_type->max_bound - $atomic_type->min_bound < 500) {
                    foreach (range($atomic_type->min_bound, $atomic_type->max_bound) as $literal_int_value) {
                        $valid_strings[] = Type::get_atomic_string_from_literal((string) $literal_int_value);
                    }
                } else {
                    $castable_types[] = new T_Numeric_String();
                }
                continue;
            }
            if ($atomic_type instanceof T_String) {
                $valid_strings[] = $atomic_type;
                continue;
            }
            if ($atomic_type instanceof T_Null || $atomic_type instanceof T_False) {
                $valid_strings[] = Type::get_atomic_string_from_literal('');
                continue;
            }
            if ($atomic_type instanceof T_True) {
                $valid_strings[] = Type::get_atomic_string_from_literal('1');
                continue;
            }
            if ($atomic_type instanceof T_Bool) {
                $valid_strings[] = Type::get_atomic_string_from_literal('1');
                $valid_strings[] = Type::get_atomic_string_from_literal('');
                continue;
            }
            if ($atomic_type instanceof T_Closed_Resource || $atomic_type instanceof T_Resource) {
                $castable_types[] = new T_Non_Empty_String();
                continue;
            }
            if ($atomic_type instanceof T_Mixed || $atomic_type instanceof Scalar) {
                $castable_types[] = new T_String();
                continue;
            }
            if ($atomic_type instanceof T_Named_Object || $atomic_type instanceof T_Object_With_Properties) {
                $intersection_types = [$atomic_type];
                if ($atomic_type->extra_types) {
                    $intersection_types = array_merge($intersection_types, $atomic_type->extra_types);
                }
                foreach ($intersection_types as $intersection_type) {
                    if ($intersection_type instanceof T_Named_Object) {
                        $intersection_method_id = new Method_Identifier($intersection_type->value, '__tostring');
                        if ($codebase->methods->method_exists($intersection_method_id, $context->calling_method_id, new Code_Location($statements_analyzer->get_source(), $stmt))) {
                            $return_type = $codebase->methods->get_method_return_type($intersection_method_id, $self_class) ?? Type::get_string();
                            $declaring_method_id = $codebase->methods->get_declaring_method_id($intersection_method_id);
                            Method_Call_Return_Type_Fetcher::taint_method_call_result($statements_analyzer, $return_type, $stmt, $stmt, [], $intersection_method_id, $declaring_method_id, $intersection_type->value . '::__toString', $context);
                            if ($statements_analyzer->data_flow_graph) {
                                $parent_nodes = array_merge($return_type->parent_nodes, $parent_nodes);
                            }
                            $castable_types = [...$castable_types, ...array_values($return_type->get_atomic_types())];
                            continue 2;
                        }
                    }
                    if ($intersection_type instanceof T_Object_With_Properties && isset($intersection_type->methods['__tostring'])) {
                        $castable_types[] = new T_String();
                        continue 2;
                    }
                }
            }
            if ($atomic_type instanceof T_Template_Param) {
                $atomic_types = array_merge($atomic_types, $atomic_type->as->get_atomic_types());
                continue;
            }
            $invalid_casts[] = $atomic_type->get_id();
        }
        if ($invalid_casts) {
            if ($valid_strings || $castable_types) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Cast($invalid_casts[0] . ' cannot be cast to string', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Cast($invalid_casts[0] . ' cannot be cast to string', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        } elseif ($explicit_cast && !$castable_types) {
            // todo: emit error here
        }
        $valid_types = [...$valid_strings, ...$castable_types];
        if (!$valid_types) {
            $str_type = Type::get_string();
        } else {
            $str_type = Type_Combiner::combine($valid_types, $codebase);
        }
        if ($statements_analyzer->data_flow_graph) {
            return $str_type->set_parent_nodes($parent_nodes);
        }
        return $str_type;
    }
    private static function check_expr_general_use(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Cast $stmt, Context $context): bool
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        $ret_val = Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context);
        $context->inside_general_use = $was_inside_general_use;
        return $ret_val;
    }
    private static function handle_redundant_cast(Union $maybe_type, Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Cast $stmt): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $project_analyzer = $statements_analyzer->get_project_analyzer();
        $file_manipulation = null;
        if ($maybe_type->from_docblock) {
            $issue = new Redundant_Cast_Given_Docblock_Type('Redundant cast to ' . $maybe_type->get_key() . ' given docblock-provided type', new Code_Location($statements_analyzer->get_source(), $stmt));
            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['RedundantCastGivenDocblockType'])) {
                $file_manipulation = new File_Manipulation((int) $stmt->get_attribute('startFilePos'), (int) $stmt->expr->get_attribute('startFilePos'), '');
            }
        } else {
            $issue = new Redundant_Cast('Redundant cast to ' . $maybe_type->get_key(), new Code_Location($statements_analyzer->get_source(), $stmt));
            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['RedundantCast'])) {
                $file_manipulation = new File_Manipulation((int) $stmt->get_attribute('startFilePos'), (int) $stmt->expr->get_attribute('startFilePos'), '');
            }
        }
        if ($file_manipulation) {
            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), [$file_manipulation]);
        }
        Issue_Buffer::maybe_add($issue, $statements_analyzer->get_suppressed_issues());
    }
}