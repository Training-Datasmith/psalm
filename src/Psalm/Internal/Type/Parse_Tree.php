<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

/**
 * @internal
 */
class Parse_Tree
{
    /**
     * @var list<ParseTree>
     */
    public array $children = [];
    public bool $possibly_undefined = false;
    public function __construct(public ?Parse_Tree $parent = null)
    {
    }
    public function __destruct()
    {
        $this->parent = null;
    }
    public function clean_parents(): void
    {
        foreach ($this->children as $child) {
            $child->clean_parents();
        }
        $this->parent = null;
    }
}