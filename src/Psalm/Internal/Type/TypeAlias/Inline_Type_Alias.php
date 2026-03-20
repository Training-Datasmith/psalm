<?php

declare (strict_types=1);
namespace Psalm\Internal\Type\Type_Alias;

use Psalm\Internal\Type\Type_Alias;
use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
final class Inline_Type_Alias implements Type_Alias
{
    use Immutable_Non_Cloneable_Trait;
    /**
     * @param list<array{0: string, 1: int, 2?: string}> $replacement_tokens
     */
    public function __construct(public readonly array $replacement_tokens)
    {
    }
}