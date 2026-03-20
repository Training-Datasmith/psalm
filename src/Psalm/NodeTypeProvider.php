<?php

declare (strict_types=1);
namespace Psalm;

use Php_Parser;
use Psalm\Type\Union;
interface Node_Type_Provider
{
    /**
     * @param PhpParser\Node\Expr|PhpParser\Node\Name|PhpParser\Node\Stmt\Return_ $node
     */
    public function set_type(Php_Parser\Node_Abstract $node, Union $type): void;
    /**
     * @param PhpParser\Node\Expr|PhpParser\Node\Name|PhpParser\Node\Stmt\Return_ $node
     */
    public function get_type(Php_Parser\Node_Abstract $node): ?Union;
}