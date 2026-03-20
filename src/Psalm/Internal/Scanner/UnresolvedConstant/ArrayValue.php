<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner\Unresolved_Constant;

use Psalm\Internal\Scanner\Unresolved_Constant_Component;
/**
 * @psalm-immutable
 * @internal
 */
final class Array_Value extends Unresolved_Constant_Component
{
    /** @param list<KeyValuePair|ArraySpread> $entries */
    public function __construct(public readonly array $entries)
    {
    }
}