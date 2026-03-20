<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
final class Unresolved_Ternary extends Unresolved_Constant_Component
{
    use Immutable_Non_Cloneable_Trait;
    public function __construct(public readonly Unresolved_Constant_Component $cond, public readonly ?Unresolved_Constant_Component $if, public readonly Unresolved_Constant_Component $else)
    {
    }
}