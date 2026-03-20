<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
abstract class Unresolved_Binary_Op extends Unresolved_Constant_Component
{
    use Immutable_Non_Cloneable_Trait;
    public function __construct(public readonly Unresolved_Constant_Component $left, public readonly Unresolved_Constant_Component $right)
    {
    }
}