<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Codebase;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
use Psalm\Type\Union;
/** @internal */
final class Can_Contain_Object_Type_Visitor extends Type_Visitor
{
    private bool $contains_object_type = false;
    public function __construct(private readonly Codebase $codebase)
    {
    }
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof Union && ($type->has_object_type() || $type->has_iterable() || $type->has_mixed()) || $type instanceof Atomic && ($type->is_object_type() || $type->is_iterable($this->codebase) || $type instanceof T_Mixed)) {
            $this->contains_object_type = true;
            return self::STOP_TRAVERSAL;
        }
        return null;
    }
    public function matches(): bool
    {
        return $this->contains_object_type;
    }
}