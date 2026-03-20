<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
/**
 * @internal
 * @implements Task<null, void, void>
 */
final class Scanner_Task implements Task
{
    public function __construct(private readonly string $file)
    {
    }
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        return Project_Analyzer::get_instance()->get_codebase()->scanner->scan_a_path($this->file);
    }
}