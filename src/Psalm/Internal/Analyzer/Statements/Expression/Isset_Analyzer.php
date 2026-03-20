<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Instance_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Invalid_Argument;
use Psalm\Issue_Buffer;
use Psalm\Type;
/**
 * @internal
 */
final class Isset_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Isset_ $stmt, Context $context): void
    {
        foreach ($stmt->vars as $isset_var) {
            if ($isset_var instanceof Php_Parser\Node\Expr\Property_Fetch && $isset_var->var instanceof Php_Parser\Node\Expr\Variable && $isset_var->var->name === 'this' && $isset_var->name instanceof Php_Parser\Node\Identifier) {
                $var_id = '$this->' . $isset_var->name->name;
                if (!isset($context->vars_in_scope[$var_id])) {
                    if (isset($context->vars_in_scope['$this'])) {
                        Instance_Property_Fetch_Analyzer::analyze($statements_analyzer, $isset_var, $context);
                    }
                    if (!isset($context->vars_in_scope[$var_id])) {
                        $context->vars_in_scope[$var_id] = Type::get_mixed();
                    }
                    $context->vars_possibly_in_scope[$var_id] = true;
                }
            } elseif (!self::is_valid_statement($isset_var)) {
                Issue_Buffer::maybe_add(new Invalid_Argument('Isset only works with variables and array elements', new Code_Location($statements_analyzer->get_source(), $isset_var), 'empty'), $statements_analyzer->get_suppressed_issues());
            }
            self::analyze_isset_var($statements_analyzer, $isset_var, $context);
        }
        $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
    }
    public static function analyze_isset_var(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context): void
    {
        $context->inside_isset = true;
        Expression_Analyzer::analyze($statements_analyzer, $stmt, $context);
        $context->inside_isset = false;
    }
    private static function is_valid_statement(Php_Parser\Node\Expr $stmt): bool
    {
        return $stmt instanceof Php_Parser\Node\Expr\Variable || $stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch || $stmt instanceof Php_Parser\Node\Expr\Property_Fetch || $stmt instanceof Php_Parser\Node\Expr\Static_Property_Fetch || $stmt instanceof Php_Parser\Node\Expr\Nullsafe_Property_Fetch || $stmt instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $stmt instanceof Php_Parser\Node\Expr\Assign_Ref;
    }
}