<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Conditional_Tree extends Parse_Tree
{
    public function __construct(public Template_Is_Tree $condition, ?Parse_Tree $parent = null)
    {
        $this->parent = $parent;
    }
}