<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
/**
 * @internal
 */
final class Contains_Literal_Visitor extends Type_Visitor
{
    private bool $contains_literal = false;
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof T_Literal_String || $type instanceof T_Literal_Int || $type instanceof T_Literal_Float || $type instanceof T_True || $type instanceof T_False) {
            $this->contains_literal = true;
            return self::STOP_TRAVERSAL;
        }
        if ($type instanceof T_Array && $type->is_empty_array()) {
            $this->contains_literal = true;
            return self::STOP_TRAVERSAL;
        }
        return null;
    }
    public function matches(): bool
    {
        return $this->contains_literal;
    }
}