<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Codebase\Analyzer;
/**
 * @internal
 * @implements Task<int, void, void>
 */
final class Analyzer_Task implements Task
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(private readonly string $file)
    {
    }
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): int
    {
        $pa = Project_Analyzer::get_instance();
        return Analyzer::analysis_worker($pa->get_config(), $pa->progress, $this->file);
    }
}