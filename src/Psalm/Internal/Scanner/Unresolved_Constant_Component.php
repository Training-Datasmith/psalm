<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner;

use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
abstract class Unresolved_Constant_Component
{
    use Immutable_Non_Cloneable_Trait;
}