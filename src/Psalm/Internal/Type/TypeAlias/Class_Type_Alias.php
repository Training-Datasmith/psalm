<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Type_Alias;

use Psalm\Internal\Type\Type_Alias;
use Psalm\Type\Atomic;
/**
 * @internal
 */
final class Class_Type_Alias implements Type_Alias
{
    /**
     * @param list<Atomic> $replacement_atomic_types
     */
    public function __construct(public array $replacement_atomic_types)
    {
    }
}