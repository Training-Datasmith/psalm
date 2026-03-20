<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser\Node;
use Php_Parser\Node_Visitor_Abstract;
/**
 * Visitor cloning all nodes and linking to the original nodes using an attribute.
 *
 * This visitor is required to perform format-preserving pretty prints.
 *
 * @internal
 */
final class Cloning_Visitor extends Node_Visitor_Abstract
{
    #[Override]
    public function enter_node(Node $node): Node
    {
        $node = clone $node;
        if (($cs = $node->get_comments()) !== []) {
            $comments = [];
            foreach ($cs as $i => $comment) {
                $comments[$i] = clone $comment;
            }
            $node->set_attribute('comments', $comments);
        }
        return $node;
    }
}