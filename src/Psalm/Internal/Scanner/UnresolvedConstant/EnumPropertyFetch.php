<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
/**
 * @psalm-immutable
 * @internal
 */
abstract class Enum_Property_Fetch extends Unresolved_Constant_Component
{
    public function __construct(public readonly string $fqcln, public readonly string $case)
    {
    }
}