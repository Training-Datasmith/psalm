<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Keyed_Array;
use function strtolower;
/**
 * @internal
 */
final class Yield_From_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Yield_From $stmt, Context $context): bool
    {
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->inside_call = $was_inside_call;
            return false;
        }
        if ($stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr)) {
            $key_type = null;
            $value_type = null;
            $always_non_empty_array = true;
            if (Foreach_Analyzer::check_iterator_type($statements_analyzer, $stmt, $stmt->expr, $stmt_expr_type, $statements_analyzer->get_codebase(), $context, $key_type, $value_type, $always_non_empty_array) === false) {
                $context->inside_call = $was_inside_call;
                return false;
            }
            $yield_from_type = null;
            foreach ($stmt_expr_type->get_atomic_types() as $atomic_type) {
                if ($yield_from_type === null) {
                    if ($atomic_type instanceof T_Generic_Object && strtolower($atomic_type->value) === 'generator' && isset($atomic_type->type_params[3])) {
                        $yield_from_type = $atomic_type->type_params[3];
                    } elseif ($atomic_type instanceof T_Array) {
                        $yield_from_type = Type::get_null();
                    } elseif ($atomic_type instanceof T_Keyed_Array) {
                        $yield_from_type = Type::get_null();
                    }
                } else {
                    $yield_from_type = Type::get_mixed();
                }
            }
            // this should be whatever the generator above returns, but *not* the return type
            $statements_analyzer->node_data->set_type($stmt, $yield_from_type ?: Type::get_mixed());
        }
        $context->inside_call = $was_inside_call;
        return true;
    }
}