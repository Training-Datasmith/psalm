<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Invalid_Throw;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Throw_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Throw_ $stmt, Context $context): bool
    {
        $context->inside_throw = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->has_returned = true;
            return false;
        }
        $context->inside_throw = false;
        $context->has_returned = true;
        if ($context->finally_scope) {
            foreach ($context->vars_in_scope as $var_id => &$type) {
                if (isset($context->finally_scope->vars_in_scope[$var_id])) {
                    $context->finally_scope->vars_in_scope[$var_id] = Type::combine_union_types($context->finally_scope->vars_in_scope[$var_id], $type, $statements_analyzer->get_codebase());
                } else {
                    $type = $type->set_possibly_undefined(true, true);
                    $context->finally_scope->vars_in_scope[$var_id] = $type;
                }
            }
        }
        if ($context->check_classes && ($throw_type = $statements_analyzer->node_data->get_type($stmt->expr)) && !$throw_type->has_mixed()) {
            $exception_type = new Union([new T_Named_Object('Exception'), new T_Named_Object('Throwable')]);
            $file_analyzer = $statements_analyzer->get_file_analyzer();
            $codebase = $statements_analyzer->get_codebase();
            foreach ($throw_type->get_atomic_types() as $throw_type_part) {
                $throw_type_candidate = new Union([$throw_type_part]);
                if (!Union_Type_Comparator::is_contained_by($codebase, $throw_type_candidate, $exception_type)) {
                    if (Issue_Buffer::accepts(new Invalid_Throw('Cannot throw ' . $throw_type_part . ' as it does not extend Exception or implement Throwable', new Code_Location($file_analyzer, $stmt), (string) $throw_type_part), $statements_analyzer->get_suppressed_issues())) {
                        return false;
                    }
                } elseif (!$context->is_suppressing_exceptions($statements_analyzer)) {
                    $codelocation = new Code_Location($file_analyzer, $stmt);
                    $hash = $codelocation->get_hash();
                    foreach ($throw_type->get_atomic_types() as $throw_atomic_type) {
                        if ($throw_atomic_type instanceof T_Named_Object) {
                            $context->possibly_thrown_exceptions[$throw_atomic_type->value][$hash] = $codelocation;
                        }
                    }
                }
            }
        }
        $statements_analyzer->node_data->set_type($stmt, Type::get_never());
        return true;
    }
}