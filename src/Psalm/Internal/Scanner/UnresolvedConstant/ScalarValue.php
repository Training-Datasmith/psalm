<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
/**
 * @psalm-immutable
 * @internal
 */
final class Scalar_Value extends Unresolved_Constant_Component
{
    public function __construct(public readonly string|int|float|bool|null $value)
    {
    }
}