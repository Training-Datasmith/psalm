<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Codebase\Scanner;
use Psalm\Issue_Buffer;
use const PHP_EOL;
/**
 * @internal
 * @psalm-import-type PoolData from Scanner
 * @implements Task<PoolData, void, void>
 */
final class Shutdown_Scanner_Task implements Task
{
    /**
     * @return PoolData
     */
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $project_analyzer->progress->debug('Collecting data from forked scanner process' . PHP_EOL);
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $statements_provider = $codebase->statements_provider;
        return ['classlikes_data' => $codebase->classlikes->get_thread_data(), 'scanner_data' => $codebase->scanner->get_thread_data(), 'issues' => Issue_Buffer::get_issues_data(), 'changed_members' => $statements_provider->get_changed_members(), 'unchanged_signature_members' => $statements_provider->get_unchanged_signature_members(), 'diff_map' => $statements_provider->get_diff_map(), 'deletion_ranges' => $statements_provider->get_deletion_ranges(), 'errors' => $statements_provider->get_errors(), 'classlike_storage' => $codebase->classlike_storage_provider->get_all(), 'file_storage' => $codebase->file_storage_provider->get_all(), 'taint_data' => $codebase->taint_flow_graph, 'global_constants' => $codebase->get_all_stubbed_constants(), 'global_functions' => $codebase->functions->get_all_stubbed_functions()];
    }
}