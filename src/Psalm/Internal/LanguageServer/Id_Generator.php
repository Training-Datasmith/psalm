<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

/**
 * Generates unique, incremental IDs for use as request IDs
 *
 * @internal
 */
final class Id_Generator
{
    public int $counter = 1;
    /**
     * Returns a unique ID
     */
    public function generate(): int
    {
        return $this->counter++;
    }
}