<?php

declare (strict_types=1);
namespace Psalm\Internal\Fork;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Override;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Codebase\Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\File_Manipulation\Function_Docblock_Manipulator;
use Psalm\Issue_Buffer;
/**
 * @internal
 * @psalm-import-type WorkerData from Analyzer
 * @implements Task<WorkerData, void, void>
 */
final class Shutdown_Analyzer_Task implements Task
{
    /**
     * @return WorkerData
     */
    #[Override]
    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $analyzer = $codebase->analyzer;
        $file_reference_provider = $codebase->file_reference_provider;
        $project_analyzer->progress->debug('Gathering data for forked process' . "\n");
        // @codingStandardsIgnoreStart
        return ['issues' => Issue_Buffer::get_issues_data(), 'fixable_issue_counts' => Issue_Buffer::get_fixable_issues(), 'nonmethod_references_to_classes' => $file_reference_provider->get_all_non_method_references_to_classes(), 'method_references_to_classes' => $file_reference_provider->get_all_method_references_to_classes(), 'file_references_to_class_members' => $file_reference_provider->get_all_file_references_to_class_members(), 'method_references_to_class_members' => $file_reference_provider->get_all_method_references_to_class_members(), 'method_dependencies' => $file_reference_provider->get_all_method_dependencies(), 'file_references_to_class_properties' => $file_reference_provider->get_all_file_references_to_class_properties(), 'file_references_to_method_returns' => $file_reference_provider->get_all_file_references_to_method_returns(), 'method_references_to_class_properties' => $file_reference_provider->get_all_method_references_to_class_properties(), 'method_references_to_method_returns' => $file_reference_provider->get_all_method_references_to_method_returns(), 'file_references_to_missing_class_members' => $file_reference_provider->get_all_file_references_to_missing_class_members(), 'method_references_to_missing_class_members' => $file_reference_provider->get_all_method_references_to_missing_class_members(), 'method_param_uses' => $file_reference_provider->get_all_method_param_uses(), 'mixed_member_names' => $analyzer->get_mixed_member_names(), 'file_manipulations' => File_Manipulation_Buffer::get_all(), 'mixed_counts' => $analyzer->get_mixed_counts(), 'function_timings' => $analyzer->get_function_timings(), 'analyzed_methods' => $analyzer->get_analyzed_methods(), 'file_maps' => $analyzer->get_file_maps(), 'class_locations' => $file_reference_provider->get_all_class_locations(), 'class_method_locations' => $file_reference_provider->get_all_class_method_locations(), 'class_property_locations' => $file_reference_provider->get_all_class_property_locations(), 'possible_method_param_types' => $analyzer->get_possible_method_param_types(), 'taint_data' => $codebase->taint_flow_graph, 'unused_suppressions' => $codebase->track_unused_suppressions ? Issue_Buffer::get_unused_suppressions() : [], 'used_suppressions' => $codebase->track_unused_suppressions ? Issue_Buffer::get_used_suppressions() : [], 'function_docblock_manipulators' => Function_Docblock_Manipulator::get_manipulators(), 'mutable_classes' => $codebase->analyzer->mutable_classes, 'issue_handlers' => $codebase->config->get_issue_handler_suppressions()];
        // @codingStandardsIgnoreEnd
    }
}