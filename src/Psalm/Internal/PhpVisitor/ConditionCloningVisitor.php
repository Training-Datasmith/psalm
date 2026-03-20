<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser\Node;
use Php_Parser\Node\Expr;
use Php_Parser\Node_Visitor_Abstract;
use Psalm\Internal\Provider\Node_Data_Provider;
/**
 * @internal
 */
final class Condition_Cloning_Visitor extends Node_Visitor_Abstract
{
    public function __construct(private readonly Node_Data_Provider $type_provider)
    {
    }
    /**
     * @return Node\Expr
     */
    #[Override]
    public function enter_node(Node $node): Node
    {
        /** @var Expr $node */
        $orig_node = $node;
        $node = clone $node;
        $node_type = $this->type_provider->get_type($orig_node);
        if ($node_type) {
            $this->type_provider->set_type($node, $node_type);
        }
        return $node;
    }
}