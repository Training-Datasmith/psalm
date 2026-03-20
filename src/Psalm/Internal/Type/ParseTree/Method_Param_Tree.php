<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Method_Param_Tree extends Parse_Tree
{
    public string $default = '';
    public function __construct(public string $name, public bool $byref, public bool $variadic, ?Parse_Tree $parent = null)
    {
        $this->parent = $parent;
    }
}