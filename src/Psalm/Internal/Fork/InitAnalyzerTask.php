<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
/**
 * @internal
 * @implements Task<null, void, void>
 */
final class Init_Analyzer_Task implements Task
{
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $file_reference_provider = $codebase->file_reference_provider;
        if ($codebase->taint_flow_graph) {
            $codebase->taint_flow_graph = new Taint_Flow_Graph();
        }
        $file_reference_provider->set_non_method_references_to_classes([]);
        $file_reference_provider->set_calling_method_references_to_class_members([]);
        $file_reference_provider->set_calling_method_references_to_class_properties([]);
        $file_reference_provider->set_file_references_to_class_members([]);
        $file_reference_provider->set_file_references_to_class_properties([]);
        $file_reference_provider->set_calling_method_references_to_missing_class_members([]);
        $file_reference_provider->set_file_references_to_missing_class_members([]);
        $file_reference_provider->set_references_to_mixed_member_names([]);
        $file_reference_provider->set_method_param_uses([]);
        return null;
    }
}