<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Provider;

use Override;
use Psalm\Internal\Provider\Project_Cache_Provider as PsalmProjectCacheProvider;
/**
 * @internal
 */
final class In_Memory_Project_Cache_Provider extends Psalm_Project_Cache_Provider
{
    private int $last_run = 0;
    #[Override]
    public function process_successful_run(float $start_time, string $psalm_version): void
    {
        $this->last_run = (int) $start_time;
    }
    #[Override]
    public function can_diff_files(): bool
    {
        return $this->last_run > 0;
    }
}