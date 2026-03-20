<?php

declare (strict_types=1);
namespace Psalm\Internal\Data_Flow;

/**
 * @internal
 */
final class Taint_Source extends Data_Flow_Node
{
    public static function from_node(Data_Flow_Node $node): self
    {
        return new self($node->id, $node->label, $node->code_location, $node->specialization_key, $node->taints);
    }
}