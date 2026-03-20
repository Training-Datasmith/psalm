<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use function is_string;
/**
 * @internal
 */
final class Short_Closure_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    /**
     * @var array<string, bool>
     */
    private array $used_variables = [];
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        if ($node instanceof Php_Parser\Node\Expr\Variable && is_string($node->name)) {
            $this->used_variables['$' . $node->name] = true;
        }
        return null;
    }
    /**
     * @return array<string, bool>
     */
    public function get_used_variables(): array
    {
        return $this->used_variables;
    }
}