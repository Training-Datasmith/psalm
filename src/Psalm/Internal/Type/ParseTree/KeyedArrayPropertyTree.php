<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Keyed_Array_Property_Tree extends Parse_Tree
{
    public function __construct(public string $value, ?Parse_Tree $parent = null)
    {
        $this->parent = $parent;
    }
}