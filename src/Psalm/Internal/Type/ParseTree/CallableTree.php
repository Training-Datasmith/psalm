<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Callable_Tree extends Parse_Tree
{
    public bool $terminated = false;
    public function __construct(public string $value, ?Parse_Tree $parent = null)
    {
        $this->parent = $parent;
    }
}