<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
/**
 * @psalm-immutable
 * @internal
 */
final class Constant extends Unresolved_Constant_Component
{
    public function __construct(public readonly string $name, public readonly bool $is_fully_qualified)
    {
    }
}