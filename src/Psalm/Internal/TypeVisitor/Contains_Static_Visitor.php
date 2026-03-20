<?php

declare (strict_types=1);
namespace Psalm\Internal\Type_Visitor;

use Override;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Type_Node;
use Psalm\Type\Type_Visitor;
/**
 * @internal
 */
final class Contains_Static_Visitor extends Type_Visitor
{
    private bool $contains_static = false;
    #[Override]
    protected function enter_node(Type_Node $type): ?int
    {
        if ($type instanceof T_Named_Object && ($type->value === 'static' || $type->is_static)) {
            $this->contains_static = true;
            return self::STOP_TRAVERSAL;
        }
        return null;
    }
    public function matches(): bool
    {
        return $this->contains_static;
    }
}