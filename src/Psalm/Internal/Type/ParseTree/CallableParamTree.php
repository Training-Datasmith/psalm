<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Callable_Param_Tree extends Parse_Tree
{
    public bool $variadic = false;
    public bool $has_default = false;
    /**
     * Param name, without the $ prefix
     *
     * @var null|non-empty-string
     */
    public ?string $name = null;
}