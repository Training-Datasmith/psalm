<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
final class Unresolved_Division_Op extends Unresolved_Binary_Op
{
    use Immutable_Non_Cloneable_Trait;
}