<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Parse_Tree;

use Psalm\Internal\Type\Parse_Tree;
/**
 * @internal
 */
final class Value extends Parse_Tree
{
    public ?string $text = null;
    public function __construct(public string $value, public int $offset_start, public int $offset_end, ?string $text, ?Parse_Tree $parent = null)
    {
        $this->parent = $parent;
        $this->text = $text === $value ? null : $text;
    }
}