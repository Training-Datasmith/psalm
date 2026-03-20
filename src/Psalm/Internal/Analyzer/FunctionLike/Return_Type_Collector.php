<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Function_Like;

use Php_Parser;
use Php_Parser\Node_Traverser;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Php_Visitor\Yield_Type_Collector;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function array_merge;
/**
 * A class for analysing a given method call's effects in relation to $this/self and also looking at return types
 *
 * @internal
 */
final class Return_Type_Collector
{
    /**
     * Gets the return types from a list of statements
     *
     * @param  array<PhpParser\Node>     $stmts
     * @param  list<Union>               $yield_types
     * @return list<Union>               a list of return types
     * @psalm-suppress ComplexMethod to be refactored
     */
    public static function get_return_types(Codebase $codebase, Node_Data_Provider $nodes, array $stmts, array &$yield_types, bool $collapse_types = false): array
    {
        $return_types = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Return_) {
                if (!$stmt->expr) {
                    $return_types[] = Type::get_void();
                } elseif ($stmt_type = $nodes->get_type($stmt)) {
                    $return_types[] = $stmt_type;
                    $yield_types = array_merge($yield_types, self::get_yield_type_from_expression($stmt->expr, $nodes));
                } elseif ($stmt->expr instanceof Php_Parser\Node\Scalar\String_) {
                    $return_types[] = Type::get_string();
                } elseif ($stmt->expr instanceof Php_Parser\Node\Scalar\Int_) {
                    $return_types[] = Type::get_int();
                } elseif ($stmt->expr instanceof Php_Parser\Node\Expr\Const_Fetch) {
                    if ((string) $stmt->expr->name === 'true') {
                        $return_types[] = Type::get_true();
                    } elseif ((string) $stmt->expr->name === 'false') {
                        $return_types[] = Type::get_false();
                    } elseif ((string) $stmt->expr->name === 'null') {
                        $return_types[] = Type::get_null();
                    }
                } else {
                    $return_types[] = Type::get_mixed();
                }
                break;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Break_ || $stmt instanceof Php_Parser\Node\Stmt\Continue_) {
                break;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression) {
                if ($stmt->expr instanceof Php_Parser\Node\Expr\Exit_ || $stmt->expr instanceof Php_Parser\Node\Expr\Throw_) {
                    $return_types[] = Type::get_never();
                    break;
                }
                if ($stmt->expr instanceof Php_Parser\Node\Expr\Func_Call || $stmt->expr instanceof Php_Parser\Node\Expr\Method_Call || $stmt->expr instanceof Php_Parser\Node\Expr\Nullsafe_Method_Call || $stmt->expr instanceof Php_Parser\Node\Expr\Static_Call) {
                    $stmt_type = $nodes->get_type($stmt->expr);
                    if ($stmt_type && ($stmt_type->is_never() || $stmt_type->explicit_never)) {
                        $return_types[] = Type::get_never();
                        break;
                    }
                }
                if ($stmt->expr instanceof Php_Parser\Node\Expr\Assign) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, [$stmt->expr->expr], $yield_types)];
                }
                $yield_types = array_merge($yield_types, self::get_yield_type_from_expression($stmt->expr, $nodes));
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\If_) {
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
                foreach ($stmt->elseifs as $elseif) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $elseif->stmts, $yield_types)];
                }
                if ($stmt->else) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->else->stmts, $yield_types)];
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Try_Catch) {
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
                foreach ($stmt->catches as $catch) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $catch->stmts, $yield_types)];
                }
                if ($stmt->finally) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->finally->stmts, $yield_types)];
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\For_) {
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Foreach_) {
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\While_) {
                $yield_types = array_merge($yield_types, self::get_yield_type_from_expression($stmt->cond, $nodes));
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Do_) {
                $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $stmt->stmts, $yield_types)];
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Switch_) {
                foreach ($stmt->cases as $case) {
                    $return_types = [...$return_types, ...self::get_return_types($codebase, $nodes, $case->stmts, $yield_types)];
                }
            }
        }
        // if we're at the top level and we're not ending in a return, make sure to add possible null
        if ($collapse_types) {
            // if it's a generator, boil everything down to a single generator return type
            if ($yield_types) {
                $yield_types = self::process_yield_types($codebase, $return_types, $yield_types);
            }
        }
        return $return_types;
    }
    /**
     * @param  list<Union>           $return_types
     * @param  non-empty-list<Union> $yield_types
     * @return non-empty-list<Union>
     */
    private static function process_yield_types(Codebase $codebase, array $return_types, array $yield_types): array
    {
        $key_type = null;
        $value_type = null;
        $yield_type = Type::combine_union_type_array($yield_types, null);
        foreach ($yield_type->get_atomic_types() as $type) {
            if ($type instanceof T_Keyed_Array) {
                $type = $type->get_generic_array_type();
            }
            if ($type instanceof T_Array) {
                [$key_type_param, $value_type_param] = $type->type_params;
                $key_type = Type::combine_union_types($key_type_param, $key_type);
                $value_type = Type::combine_union_types($value_type_param, $value_type);
            } elseif ($type instanceof T_Iterable || $type instanceof T_Named_Object) {
                Foreach_Analyzer::get_key_value_params_for_traversable_object($type, $codebase, $key_type, $value_type);
            }
        }
        return [new Union([new T_Generic_Object('Generator', [$key_type ?? Type::get_mixed(), $value_type ?? Type::get_mixed(), Type::get_mixed(), $return_types ? Type::combine_union_type_array($return_types, null) : Type::get_void()])])];
    }
    /**
     * @return list<Union>
     */
    private static function get_yield_type_from_expression(Php_Parser\Node\Expr $stmt, Node_Data_Provider $nodes): array
    {
        $collector = new Yield_Type_Collector($nodes);
        $traverser = new Node_Traverser();
        $traverser->add_visitor($collector);
        $traverser->traverse([$stmt]);
        return $collector->get_yield_types();
    }
}