<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
/**
 * @internal
 */
final class Node_Counter_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    public int $count = 0;
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        $this->count++;
        return null;
    }
}