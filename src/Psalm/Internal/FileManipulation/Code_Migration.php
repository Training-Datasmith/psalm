<?php

declare (strict_types=1);
namespace Psalm\Internal\File_Manipulation;

use Psalm\Storage\Immutable_Non_Cloneable_Trait;
/**
 * @psalm-immutable
 * @internal
 */
final class Code_Migration
{
    use Immutable_Non_Cloneable_Trait;
    public function __construct(public readonly string $source_file_path, public readonly int $source_start, public readonly int $source_end, public readonly string $destination_file_path, public readonly int $destination_start)
    {
    }
}