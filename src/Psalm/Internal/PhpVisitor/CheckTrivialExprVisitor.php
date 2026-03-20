<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
/**
 * @internal
 */
final class Check_Trivial_Expr_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    private bool $has_non_trivial_expr = false;
    private function check_non_trivial_expr(Php_Parser\Node\Expr $node): bool
    {
        if ($node instanceof Php_Parser\Node\Expr\Array_Dim_Fetch || $node instanceof Php_Parser\Node\Expr\Closure || $node instanceof Php_Parser\Node\Expr\Eval_ || $node instanceof Php_Parser\Node\Expr\Exit_ || $node instanceof Php_Parser\Node\Expr\Include_ || $node instanceof Php_Parser\Node\Expr\Func_Call || $node instanceof Php_Parser\Node\Expr\Method_Call || $node instanceof Php_Parser\Node\Expr\Arrow_Function || $node instanceof Php_Parser\Node\Expr\Shell_Exec || $node instanceof Php_Parser\Node\Expr\Static_Call || $node instanceof Php_Parser\Node\Expr\Yield_ || $node instanceof Php_Parser\Node\Expr\Yield_From || $node instanceof Php_Parser\Node\Expr\New_ || $node instanceof Php_Parser\Node\Expr\Cast\String_) {
            if (($node instanceof Php_Parser\Node\Expr\Func_Call || $node instanceof Php_Parser\Node\Expr\Method_Call || $node instanceof Php_Parser\Node\Expr\Static_Call) && $node->get_attribute('pure', false)) {
                return false;
            }
            if ($node instanceof Php_Parser\Node\Expr\New_ && $node->get_attribute('external_mutation_free', false)) {
                return false;
            }
            return true;
        }
        return false;
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        if ($node instanceof Php_Parser\Node\Expr) {
            // Check for Non-Trivial Expression first
            if ($this->check_non_trivial_expr($node)) {
                $this->has_non_trivial_expr = true;
                return self::STOP_TRAVERSAL;
            }
            if ($node instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $node instanceof Php_Parser\Node\Expr\Const_Fetch || $node instanceof Php_Parser\Node\Expr\Error || $node instanceof Php_Parser\Node\Expr\Property_Fetch || $node instanceof Php_Parser\Node\Expr\Static_Property_Fetch) {
                return self::STOP_TRAVERSAL;
            }
        } elseif ($node instanceof Php_Parser\Node\Closure_Use) {
            $this->has_non_trivial_expr = true;
            return self::STOP_TRAVERSAL;
        }
        return null;
    }
    public function has_non_trivial_expr(): bool
    {
        return $this->has_non_trivial_expr;
    }
}