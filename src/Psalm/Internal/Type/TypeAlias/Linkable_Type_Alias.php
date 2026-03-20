<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Type_Alias;

use Psalm\Internal\Type\Type_Alias;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
final class Linkable_Type_Alias implements Type_Alias
{
    use Immutable_Non_Cloneable_Trait;
    public function __construct(public readonly string $declaring_fq_classlike_name, public readonly string $alias_name, public readonly int $line_number, public readonly int $start_offset, public readonly int $end_offset)
    {
    }
}