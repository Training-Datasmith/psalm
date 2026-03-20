<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Mutable_Type_Visitor;
use Psalm\Type\Mutable_Union;
use Psalm\Type\Type_Node;
use Psalm\Type\Union;
/**
 * @internal
 */
final class From_Docblock_Setter extends Mutable_Type_Visitor
{
    public function __construct(private readonly bool $from_docblock)
    {
    }
    /**
     * @return self::STOP_TRAVERSAL|self::DONT_TRAVERSE_CHILDREN|null
     */
    #[Override]
    protected function enter_node(Type_Node &$type): ?int
    {
        if (!$type instanceof Atomic && !$type instanceof Union && !$type instanceof Mutable_Union) {
            return null;
        }
        if ($type->from_docblock === $this->from_docblock) {
            return null;
        }
        if ($type instanceof Mutable_Union) {
            $type->from_docblock = true;
        } elseif ($type instanceof Union) {
            $type = $type->set_properties(['from_docblock' => $this->from_docblock]);
        } else {
            $type = $type->set_from_docblock($this->from_docblock);
        }
        if ($type instanceof T_Template_Param && $type->as->is_mixed()) {
            return self::DONT_TRAVERSE_CHILDREN;
        }
        return null;
    }
}