<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use const PHP_EOL;
/**
 * @internal
 * @implements Task<null, void, void>
 */
final class Init_Scanner_Task implements Task
{
    #[Override]
    final public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $analyzer = Project_Analyzer::get_instance();
        $analyzer->progress->debug('Initialising forked process for scanning' . PHP_EOL);
        $codebase = $analyzer->get_codebase();
        $statements_provider = $codebase->statements_provider;
        $codebase->scanner->is_forked();
        File_Storage_Provider::delete_all();
        Class_Like_Storage_Provider::delete_all();
        $statements_provider->reset_diffs();
        $analyzer->progress->debug('Have initialised forked process for scanning' . PHP_EOL);
        return null;
    }
}