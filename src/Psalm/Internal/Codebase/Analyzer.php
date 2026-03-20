<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Amp\Future;
use InvalidArgumentException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\File_Analyzer;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\File_Manipulation\Class_Docblock_Manipulator;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\File_Manipulation\Function_Docblock_Manipulator;
use Psalm\Internal\File_Manipulation\Property_Docblock_Manipulator;
use Psalm\Internal\Fork\Analyzer_Task;
use Psalm\Internal\Fork\Init_Analyzer_Task;
use Psalm\Internal\Fork\Pool;
use Psalm\Internal\Fork\Shutdown_Analyzer_Task;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Provider\Statements_Provider;
use Psalm\Issue_Buffer;
use Psalm\Progress\Progress;
use Psalm\Type;
use Psalm\Type\Union;
use Sebastian_Bergmann\Diff\Differ;
use Sebastian_Bergmann\Diff\Output\Strict_Unified_Diff_Output_Builder;
use UnexpectedValueException;
use function Amp\Future\await;
use function array_filter;
use function array_intersect_key;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function implode;
use function ksort;
use function number_format;
use function pathinfo;
use function preg_replace;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function usort;
use const PATHINFO_EXTENSION;
use const PHP_INT_MAX;
/**
 * @psalm-type  TaggedCodeType = array<int, array{0: int, 1: non-empty-string}>
 *
 * @psalm-type  FileMapType = array{
 *      0: TaggedCodeType,
 *      1: TaggedCodeType,
 *      2: array<int, array{0: int, 1: non-empty-string, 2: int}>
 * }
 *
 * @psalm-type  WorkerData = array{
 *      issues: array<string, list<IssueData>>,
 *      fixable_issue_counts: array<string, int>,
 *      nonmethod_references_to_classes: array<string, array<string,bool>>,
 *      method_references_to_classes: array<string, array<string,bool>>,
 *      file_references_to_class_members: array<string, array<string,bool>>,
 *      file_references_to_class_properties: array<string, array<string,bool>>,
 *      file_references_to_method_returns: array<string, array<string,bool>>,
 *      file_references_to_missing_class_members: array<string, array<string,bool>>,
 *      mixed_counts: array<string, array{0: int, 1: int}>,
 *      mixed_member_names: array<string, array<string, bool>>,
 *      function_timings: array<string, float>,
 *      file_manipulations: array<string, FileManipulation[]>,
 *      method_references_to_class_members: array<string, array<string,bool>>,
 *      method_dependencies: array<string, array<string,bool>>,
 *      method_references_to_method_returns: array<string, array<string,bool>>,
 *      method_references_to_class_properties: array<string, array<string,bool>>,
 *      method_references_to_missing_class_members: array<string, array<string,bool>>,
 *      method_param_uses: array<string, array<int, array<string, bool>>>,
 *      analyzed_methods: array<string, array<string, int>>,
 *      file_maps: array<string, FileMapType>,
 *      class_locations: array<string, array<int, CodeLocation>>,
 *      class_method_locations: array<string, array<int, CodeLocation>>,
 *      class_property_locations: array<string, array<int, CodeLocation>>,
 *      possible_method_param_types: array<string, array<int, Union>>,
 *      taint_data: ?TaintFlowGraph,
 *      unused_suppressions: array<string, array<int, int>>,
 *      used_suppressions: array<string, array<int, bool>>,
 *      function_docblock_manipulators: array<string, array<int, FunctionDocblockManipulator>>,
 *      mutable_classes: array<string, bool>,
 *      issue_handlers: array{type: string, index: int, count: int}[],
 * }
 */
/**
 * @internal
 *
 * Called in the analysis phase of Psalm's execution
 */
final class Analyzer
{
    /**
     * Used to store counts of mixed vs non-mixed variables
     *
     * @var array<string, list{int, int}>
     */
    private array $mixed_counts = [];
    /**
     * Used to store member names of mixed property/method access
     *
     * @var array<string, array<string, bool>>
     */
    private array $mixed_member_names = [];
    private bool $count_mixed = true;
    /**
     * Used to store debug performance data
     *
     * @var array<string, float>
     */
    private array $function_timings = [];
    /**
     * We analyze more files than we necessarily report errors in
     *
     * @var array<string, string>
     */
    private array $files_to_analyze = [];
    /**
     * We can show analysis results on more files than we analyze
     * because the results can be cached
     *
     * @var array<string, string>
     */
    private array $files_with_analysis_results = [];
    /**
     * We may update fewer files than we analyse (i.e. for dead code detection)
     *
     * @var array<string>|null
     */
    private ?array $files_to_update = null;
    /**
     * @var array<string, array<string, int>>
     */
    private array $analyzed_methods = [];
    /**
     * @var array<string, array<int, IssueData>>
     */
    private array $existing_issues = [];
    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string}>>
     */
    private array $reference_map = [];
    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string}>>
     */
    private array $type_map = [];
    /**
     * @var array<string, array<int, array{0: int, 1: non-empty-string, 2: int}>>
     */
    private array $argument_map = [];
    /**
     * @var array<string, array<int, Union>>
     */
    public array $possible_method_param_types = [];
    /**
     * @var array<string, bool>
     */
    public array $mutable_classes = [];
    public function __construct(private readonly Config $config, private readonly File_Provider $file_provider, private readonly File_Storage_Provider $file_storage_provider, private readonly Progress $progress)
    {
    }
    /**
     * @param array<string, string> $files_to_analyze
     */
    public function add_files_to_analyze(array $files_to_analyze): void
    {
        $this->files_to_analyze += $files_to_analyze;
        $this->files_with_analysis_results += $files_to_analyze;
    }
    /**
     * @param array<string, string> $files_to_analyze
     */
    public function add_files_to_show_results(array $files_to_analyze): void
    {
        $this->files_with_analysis_results += $files_to_analyze;
    }
    /**
     * @param array<string> $files_to_update
     */
    public function set_files_to_update(array $files_to_update): void
    {
        $this->files_to_update = $files_to_update;
    }
    public function can_report_issues(string $file_path): bool
    {
        return isset($this->files_with_analysis_results[$file_path]);
    }
    public function analyze_files(Project_Analyzer $project_analyzer, int $pool_size, bool $alter_code, bool $consolidate_analyzed_data = false): void
    {
        $this->load_cached_results($project_analyzer);
        $codebase = $project_analyzer->get_codebase();
        if ($alter_code) {
            $project_analyzer->interpret_refactors();
        }
        $this->files_to_analyze = array_filter($this->files_to_analyze, $this->file_provider->file_exists(...));
        $this->do_analysis($project_analyzer, $pool_size);
        $scanned_files = $codebase->scanner->get_scanned_files();
        if ($codebase->taint_flow_graph) {
            $codebase->taint_flow_graph->connect_sinks_and_sources();
        }
        $this->progress->finish();
        if ($consolidate_analyzed_data) {
            $project_analyzer->consolidate_analyzed_data();
        }
        foreach (Issue_Buffer::get_issues_data() as $file_path => $file_issues) {
            $codebase->file_reference_provider->clear_existing_issues_for_file($file_path);
            foreach ($file_issues as $issue_data) {
                $codebase->file_reference_provider->add_issue($file_path, $issue_data);
            }
        }
        $codebase->file_reference_provider->update_reference_cache($codebase, $scanned_files);
        if ($codebase->track_unused_suppressions) {
            Issue_Buffer::process_unused_suppressions($codebase->file_provider);
        }
        $codebase->file_reference_provider->set_analyzed_methods($this->analyzed_methods);
        $codebase->file_reference_provider->set_file_maps($this->get_file_maps());
        $codebase->file_reference_provider->set_type_coverage($this->mixed_counts);
        $codebase->file_reference_provider->update_reference_cache($codebase, $scanned_files);
        if ($codebase->diff_methods) {
            $codebase->statements_provider->reset_diffs();
        }
        if ($alter_code) {
            $this->progress->start_altering_files();
            $project_analyzer->prepare_migration();
            $files_to_update = $this->files_to_update ?? $this->files_to_analyze;
            foreach ($files_to_update as $file_path) {
                $this->update_file($file_path, $project_analyzer->dry_run);
            }
            $project_analyzer->migrate_code();
        }
    }
    private function do_analysis(Project_Analyzer $project_analyzer, int $pool_size): void
    {
        $this->progress->start(count($this->files_to_analyze));
        ksort($this->files_to_analyze);
        $codebase = $project_analyzer->get_codebase();
        $task_done_closure = $this->progress->task_done(...);
        if ($pool_size > 1 && count($this->files_to_analyze) > $pool_size) {
            // Run analysis one file at a time, splitting the set of
            // files up among a given number of child processes.
            $pool = new Pool($pool_size, $codebase->config->long_scan_warning, $project_analyzer->progress);
            $this->progress->debug('Forking analysis' . "\n");
            // Wait for all tasks to complete and collect the results.
            await($pool->run_all(new Init_Analyzer_Task()));
            $pool->run($this->files_to_analyze, Analyzer_Task::class, $task_done_closure);
            $forked_pool_data = $pool->run_all(new Shutdown_Analyzer_Task());
            $this->progress->debug('Collecting forked analysis results' . "\n");
            foreach (Future::iterate($forked_pool_data) as $pool_data) {
                $pool_data = $pool_data->await();
                Issue_Buffer::add_issues($pool_data['issues']);
                Issue_Buffer::add_fixable_issues($pool_data['fixable_issue_counts']);
                if ($codebase->track_unused_suppressions) {
                    Issue_Buffer::add_unused_suppressions($pool_data['unused_suppressions']);
                    Issue_Buffer::add_used_suppressions($pool_data['used_suppressions']);
                }
                if ($codebase->config->find_unused_issue_handler_suppression) {
                    $codebase->config->combine_issue_handler_suppressions($pool_data['issue_handlers']);
                }
                if ($codebase->taint_flow_graph && $pool_data['taint_data']) {
                    $codebase->taint_flow_graph->add_graph($pool_data['taint_data']);
                }
                $codebase->file_reference_provider->add_non_method_references_to_classes($pool_data['nonmethod_references_to_classes']);
                $codebase->file_reference_provider->add_method_references_to_classes($pool_data['method_references_to_classes']);
                $codebase->file_reference_provider->add_file_references_to_class_members($pool_data['file_references_to_class_members']);
                $codebase->file_reference_provider->add_file_references_to_class_properties($pool_data['file_references_to_class_properties']);
                $codebase->file_reference_provider->add_file_references_to_method_returns($pool_data['file_references_to_method_returns']);
                $codebase->file_reference_provider->add_method_references_to_class_members($pool_data['method_references_to_class_members']);
                $codebase->file_reference_provider->add_method_dependencies($pool_data['method_dependencies']);
                $codebase->file_reference_provider->add_method_references_to_class_properties($pool_data['method_references_to_class_properties']);
                $codebase->file_reference_provider->add_method_references_to_method_returns($pool_data['method_references_to_method_returns']);
                $codebase->file_reference_provider->add_file_references_to_missing_class_members($pool_data['file_references_to_missing_class_members']);
                $codebase->file_reference_provider->add_method_references_to_missing_class_members($pool_data['method_references_to_missing_class_members']);
                $codebase->file_reference_provider->add_method_param_uses($pool_data['method_param_uses']);
                $this->add_mixed_member_names($pool_data['mixed_member_names']);
                $this->function_timings += $pool_data['function_timings'];
                $codebase->file_reference_provider->add_class_locations($pool_data['class_locations']);
                $codebase->file_reference_provider->add_class_method_locations($pool_data['class_method_locations']);
                $codebase->file_reference_provider->add_class_property_locations($pool_data['class_property_locations']);
                $this->mutable_classes = array_merge($this->mutable_classes, $pool_data['mutable_classes']);
                Function_Docblock_Manipulator::add_manipulators($pool_data['function_docblock_manipulators']);
                $this->analyzed_methods = array_merge($pool_data['analyzed_methods'], $this->analyzed_methods);
                foreach ($pool_data['mixed_counts'] as $file_path => [$mixed_count, $nonmixed_count]) {
                    if (!isset($this->mixed_counts[$file_path])) {
                        $this->mixed_counts[$file_path] = [$mixed_count, $nonmixed_count];
                    } else {
                        $this->mixed_counts[$file_path][0] += $mixed_count;
                        $this->mixed_counts[$file_path][1] += $nonmixed_count;
                    }
                }
                foreach ($pool_data['possible_method_param_types'] as $declaring_method_id => $possible_param_types) {
                    if (!isset($this->possible_method_param_types[$declaring_method_id])) {
                        $this->possible_method_param_types[$declaring_method_id] = $possible_param_types;
                    } else {
                        foreach ($possible_param_types as $offset => $possible_param_type) {
                            $this->possible_method_param_types[$declaring_method_id][$offset] = Type::combine_union_types($this->possible_method_param_types[$declaring_method_id][$offset] ?? null, $possible_param_type, $codebase);
                        }
                    }
                }
                foreach ($pool_data['file_manipulations'] as $file_path => $manipulations) {
                    File_Manipulation_Buffer::add($file_path, $manipulations);
                }
                foreach ($pool_data['file_maps'] as $file_path => $file_maps) {
                    [$reference_map, $type_map, $argument_map] = $file_maps;
                    $this->reference_map[$file_path] = $reference_map;
                    $this->type_map[$file_path] = $type_map;
                    $this->argument_map[$file_path] = $argument_map;
                }
            }
        } else {
            foreach ($this->files_to_analyze as $file_path => $_) {
                $task_done_closure(self::analysis_worker($this->config, $this->progress, $file_path));
            }
        }
    }
    /**
     * @psalm-suppress ComplexMethod
     */
    public function load_cached_results(Project_Analyzer $project_analyzer): void
    {
        $codebase = $project_analyzer->get_codebase();
        $statements_provider = $codebase->statements_provider;
        $file_reference_provider = $codebase->file_reference_provider;
        // Load cached data from disk
        if ($codebase->diff_methods) {
            $this->analyzed_methods = $file_reference_provider->get_analyzed_methods();
            $this->existing_issues = $file_reference_provider->get_existing_issues();
            $file_maps = $file_reference_provider->get_file_maps();
            foreach ($file_maps as $file_path => [$reference_map, $type_map, $argument_map]) {
                $this->reference_map[$file_path] = $reference_map;
                $this->type_map[$file_path] = $type_map;
                $this->argument_map[$file_path] = $argument_map;
            }
        }
        $method_references_to_class_members = $file_reference_provider->get_all_method_references_to_class_members();
        $method_dependencies = $file_reference_provider->get_all_method_dependencies();
        $method_references_to_class_properties = $file_reference_provider->get_all_method_references_to_class_properties();
        $method_references_to_method_returns = $file_reference_provider->get_all_method_references_to_method_returns();
        $method_references_to_missing_class_members = $file_reference_provider->get_all_method_references_to_missing_class_members();
        $all_referencing_methods = $method_references_to_class_members + $method_references_to_missing_class_members + $method_dependencies;
        $nonmethod_references_to_classes = $file_reference_provider->get_all_non_method_references_to_classes();
        $method_references_to_classes = $file_reference_provider->get_all_method_references_to_classes();
        $method_param_uses = $file_reference_provider->get_all_method_param_uses();
        $file_references_to_class_members = $file_reference_provider->get_all_file_references_to_class_members();
        $file_references_to_class_properties = $file_reference_provider->get_all_file_references_to_class_properties();
        $file_references_to_method_returns = $file_reference_provider->get_all_file_references_to_method_returns();
        $file_references_to_missing_class_members = $file_reference_provider->get_all_file_references_to_missing_class_members();
        $references_to_mixed_member_names = $file_reference_provider->get_all_references_to_mixed_member_names();
        $this->mixed_counts = $file_reference_provider->get_type_coverage();
        // Finish loading cached data from disk
        $changed_members = $statements_provider->get_changed_members();
        foreach ($changed_members as $file_path => $members_by_file) {
            foreach ($members_by_file as $changed_member => $_) {
                if (!strpos($changed_member, '&')) {
                    continue;
                }
                [$base_class, $trait] = explode('&', $changed_member);
                foreach ($all_referencing_methods as $member_id => $_) {
                    if (!str_starts_with($member_id, $base_class . '::')) {
                        continue;
                    }
                    $member_bit = substr($member_id, strlen($base_class) + 2);
                    if (isset($all_referencing_methods[$trait . '::' . $member_bit])) {
                        $changed_members[$file_path][$member_id] = true;
                    }
                }
            }
        }
        $newly_invalidated_methods = [];
        foreach ($statements_provider->get_unchanged_signature_members() as $file_unchanged_signature_members) {
            $newly_invalidated_methods = array_merge($newly_invalidated_methods, $file_unchanged_signature_members);
            foreach ($file_unchanged_signature_members as $unchanged_signature_member_id => $_) {
                // also check for things that might invalidate constructor property initialisation
                if (isset($all_referencing_methods[$unchanged_signature_member_id])) {
                    foreach ($all_referencing_methods[$unchanged_signature_member_id] as $referencing_method_id => $_) {
                        if (str_ends_with($referencing_method_id, '::__construct')) {
                            $referencing_base_classlike = explode('::', $referencing_method_id)[0];
                            $unchanged_signature_classlike = explode('::', $unchanged_signature_member_id)[0];
                            if ($referencing_base_classlike === $unchanged_signature_classlike) {
                                $newly_invalidated_methods[$referencing_method_id] = true;
                            } else {
                                try {
                                    $referencing_storage = $codebase->classlike_storage_provider->get($referencing_base_classlike);
                                } catch (InvalidArgumentException) {
                                    // Workaround for #3671
                                    $newly_invalidated_methods[$referencing_method_id] = true;
                                    $referencing_storage = null;
                                }
                                if (isset($referencing_storage->used_traits[$unchanged_signature_classlike]) || isset($referencing_storage->parent_classes[$unchanged_signature_classlike])) {
                                    $newly_invalidated_methods[$referencing_method_id] = true;
                                }
                            }
                        }
                    }
                }
            }
        }
        foreach ($changed_members as $file_changed_members) {
            foreach ($file_changed_members as $member_id => $_) {
                $newly_invalidated_methods[$member_id] = true;
                if (isset($all_referencing_methods[$member_id])) {
                    $newly_invalidated_methods = array_merge($all_referencing_methods[$member_id], $newly_invalidated_methods);
                }
                unset($method_references_to_class_members[$member_id], $method_dependencies[$member_id], $method_references_to_class_properties[$member_id], $method_references_to_method_returns[$member_id], $file_references_to_class_members[$member_id], $file_references_to_class_properties[$member_id], $file_references_to_method_returns[$member_id], $method_references_to_missing_class_members[$member_id], $file_references_to_missing_class_members[$member_id], $references_to_mixed_member_names[$member_id], $method_param_uses[$member_id]);
                $member_stub = (string) preg_replace('/::.*$/', '::*', $member_id, 1);
                if (isset($all_referencing_methods[$member_stub])) {
                    $newly_invalidated_methods = array_merge($all_referencing_methods[$member_stub], $newly_invalidated_methods);
                }
            }
        }
        // This could be optimized by storing method references to files
        foreach ($file_reference_provider->get_deleted_referenced_files() as $deleted_file) {
            foreach ($file_reference_provider->get_files_referencing_file($deleted_file) as $file_referencing_deleted) {
                $methods_referencing_deleted = $this->analyzed_methods[$file_referencing_deleted] ?? [];
                foreach ($methods_referencing_deleted as $method_referencing_deleted => $_) {
                    $newly_invalidated_methods[$method_referencing_deleted] = true;
                }
            }
        }
        foreach ($newly_invalidated_methods as $method_id => $_) {
            foreach ($method_references_to_class_members as $i => $_) {
                unset($method_references_to_class_members[$i][$method_id]);
            }
            foreach ($method_dependencies as $i => $_) {
                unset($method_dependencies[$i][$method_id]);
            }
            foreach ($method_references_to_class_properties as $i => $_) {
                unset($method_references_to_class_properties[$i][$method_id]);
            }
            foreach ($method_references_to_method_returns as $i => $_) {
                unset($method_references_to_method_returns[$i][$method_id]);
            }
            foreach ($method_references_to_classes as $i => $_) {
                unset($method_references_to_classes[$i][$method_id]);
            }
            foreach ($method_references_to_missing_class_members as $i => $_) {
                unset($method_references_to_missing_class_members[$i][$method_id]);
            }
            foreach ($references_to_mixed_member_names as $i => $_) {
                unset($references_to_mixed_member_names[$i][$method_id]);
            }
            foreach ($method_param_uses as $i => $_) {
                foreach ($method_param_uses[$i] as $j => $_) {
                    unset($method_param_uses[$i][$j][$method_id]);
                }
            }
        }
        foreach ($statements_provider->get_errors() as $file_path => $_) {
            unset($this->analyzed_methods[$file_path]);
            unset($this->existing_issues[$file_path]);
        }
        foreach ($this->analyzed_methods as $file_path => $analyzed_methods) {
            foreach ($analyzed_methods as $correct_method_id => $_) {
                $trait_safe_method_id = $correct_method_id;
                $correct_method_ids = explode('&', $correct_method_id);
                $correct_method_id = $correct_method_ids[0];
                if (isset($newly_invalidated_methods[$correct_method_id]) || isset($correct_method_ids[1]) && isset($newly_invalidated_methods[$correct_method_ids[1]])) {
                    unset($this->analyzed_methods[$file_path][$trait_safe_method_id]);
                }
            }
        }
        $this->shift_file_offsets($statements_provider);
        foreach ($this->files_to_analyze as $file_path) {
            $file_reference_provider->clear_existing_issues_for_file($file_path);
            $file_reference_provider->clear_existing_file_maps_for_file($file_path);
            $this->set_mixed_counts_for_file($file_path, [0, 0]);
            foreach ($file_references_to_class_members as $i => $_) {
                unset($file_references_to_class_members[$i][$file_path]);
            }
            foreach ($file_references_to_class_properties as $i => $_) {
                unset($file_references_to_class_properties[$i][$file_path]);
            }
            foreach ($file_references_to_method_returns as $i => $_) {
                unset($file_references_to_method_returns[$i][$file_path]);
            }
            foreach ($nonmethod_references_to_classes as $i => $_) {
                unset($nonmethod_references_to_classes[$i][$file_path]);
            }
            foreach ($references_to_mixed_member_names as $i => $_) {
                unset($references_to_mixed_member_names[$i][$file_path]);
            }
            foreach ($file_references_to_missing_class_members as $i => $_) {
                unset($file_references_to_missing_class_members[$i][$file_path]);
            }
        }
        foreach ($this->existing_issues as $file_path => $issues) {
            if (!isset($this->files_to_analyze[$file_path])) {
                unset($this->existing_issues[$file_path]);
                if ($this->file_provider->file_exists($file_path)) {
                    Issue_Buffer::add_issues([$file_path => array_values($issues)]);
                }
            }
        }
        $method_references_to_class_members = array_filter($method_references_to_class_members);
        $method_dependencies = array_filter($method_dependencies);
        $method_references_to_class_properties = array_filter($method_references_to_class_properties);
        $method_references_to_method_returns = array_filter($method_references_to_method_returns);
        $method_references_to_missing_class_members = array_filter($method_references_to_missing_class_members);
        $file_references_to_class_members = array_filter($file_references_to_class_members);
        $file_references_to_class_properties = array_filter($file_references_to_class_properties);
        $file_references_to_method_returns = array_filter($file_references_to_method_returns);
        $file_references_to_missing_class_members = array_filter($file_references_to_missing_class_members);
        $references_to_mixed_member_names = array_filter($references_to_mixed_member_names);
        $nonmethod_references_to_classes = array_filter($nonmethod_references_to_classes);
        $method_references_to_classes = array_filter($method_references_to_classes);
        $method_param_uses = array_filter($method_param_uses);
        $file_reference_provider->set_calling_method_references_to_class_members($method_references_to_class_members);
        $file_reference_provider->set_method_dependencies($method_dependencies);
        $file_reference_provider->set_calling_method_references_to_class_properties($method_references_to_class_properties);
        $file_reference_provider->set_calling_method_references_to_method_returns($method_references_to_method_returns);
        $file_reference_provider->set_file_references_to_class_members($file_references_to_class_members);
        $file_reference_provider->set_file_references_to_class_properties($file_references_to_class_properties);
        $file_reference_provider->set_file_references_to_method_returns($file_references_to_method_returns);
        $file_reference_provider->set_calling_method_references_to_missing_class_members($method_references_to_missing_class_members);
        $file_reference_provider->set_file_references_to_missing_class_members($file_references_to_missing_class_members);
        $file_reference_provider->set_references_to_mixed_member_names($references_to_mixed_member_names);
        $file_reference_provider->set_calling_method_references_to_classes($method_references_to_classes);
        $file_reference_provider->set_non_method_references_to_classes($nonmethod_references_to_classes);
        $file_reference_provider->set_method_param_uses($method_param_uses);
    }
    public function shift_file_offsets(Statements_Provider $statements_provider): void
    {
        $diff_map = $statements_provider->get_diff_map();
        $deletion_ranges = $statements_provider->get_deletion_ranges();
        foreach ($this->existing_issues as $file_path => $file_issues) {
            if (!isset($this->analyzed_methods[$file_path])) {
                continue;
            }
            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];
            if ($file_deletion_ranges) {
                foreach ($file_issues as $i => $issue_data) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($issue_data->from >= $from && $issue_data->from <= $to) {
                            unset($this->existing_issues[$file_path][$i]);
                            break;
                        }
                    }
                }
            }
            if ($file_diff_map) {
                foreach ($file_issues as $issue_data) {
                    foreach ($file_diff_map as [$from, $to, $file_offset, $line_offset]) {
                        if ($issue_data->from >= $from && $issue_data->from <= $to) {
                            $issue_data->from += $file_offset;
                            $issue_data->to += $file_offset;
                            $issue_data->snippet_from += $file_offset;
                            $issue_data->snippet_to += $file_offset;
                            $issue_data->line_from += $line_offset;
                            $issue_data->line_to += $line_offset;
                            break;
                        }
                    }
                }
            }
        }
        foreach ($this->reference_map as $file_path => $reference_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->reference_map[$file_path]);
                continue;
            }
            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];
            if ($file_deletion_ranges) {
                foreach ($reference_map as $reference_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($reference_from >= $from && $reference_from <= $to) {
                            unset($this->reference_map[$file_path][$reference_from]);
                            break;
                        }
                    }
                }
            }
            if ($file_diff_map) {
                foreach ($reference_map as $reference_from => [$reference_to, $tag]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($reference_from >= $from && $reference_from <= $to) {
                            unset($this->reference_map[$file_path][$reference_from]);
                            $this->reference_map[$file_path][$reference_from + $file_offset] = [$reference_to + $file_offset, $tag];
                            break;
                        }
                    }
                }
            }
        }
        foreach ($this->type_map as $file_path => $type_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->type_map[$file_path]);
                continue;
            }
            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];
            if ($file_deletion_ranges) {
                foreach ($type_map as $type_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($type_from >= $from && $type_from <= $to) {
                            unset($this->type_map[$file_path][$type_from]);
                            break;
                        }
                    }
                }
            }
            if ($file_diff_map) {
                foreach ($type_map as $type_from => [$type_to, $tag]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($type_from >= $from && $type_from <= $to) {
                            unset($this->type_map[$file_path][$type_from]);
                            $this->type_map[$file_path][$type_from + $file_offset] = [$type_to + $file_offset, $tag];
                            break;
                        }
                    }
                }
            }
        }
        foreach ($this->argument_map as $file_path => $argument_map) {
            if (!isset($this->analyzed_methods[$file_path])) {
                unset($this->argument_map[$file_path]);
                continue;
            }
            $file_diff_map = $diff_map[$file_path] ?? [];
            $file_deletion_ranges = $deletion_ranges[$file_path] ?? [];
            if ($file_deletion_ranges) {
                foreach ($argument_map as $argument_from => $_) {
                    foreach ($file_deletion_ranges as [$from, $to]) {
                        if ($argument_from >= $from && $argument_from <= $to) {
                            unset($argument_map[$argument_from]);
                            break;
                        }
                    }
                }
            }
            if ($file_diff_map) {
                foreach ($argument_map as $argument_from => [$argument_to, $method_id, $argument_number]) {
                    foreach ($file_diff_map as [$from, $to, $file_offset]) {
                        if ($argument_from >= $from && $argument_from <= $to) {
                            unset($this->argument_map[$file_path][$argument_from]);
                            $this->argument_map[$file_path][$argument_from + $file_offset] = [$argument_to + $file_offset, $method_id, $argument_number];
                            break;
                        }
                    }
                }
            }
        }
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_mixed_member_names(): array
    {
        return $this->mixed_member_names;
    }
    public function add_mixed_member_name(string $member_id, string $reference): void
    {
        $this->mixed_member_names[$member_id][$reference] = true;
    }
    public function has_mixed_member_name(string $member_id): bool
    {
        return isset($this->mixed_member_names[$member_id]);
    }
    /**
     * @param array<string, array<string, bool>> $names
     */
    public function add_mixed_member_names(array $names): void
    {
        foreach ($names as $key => $name) {
            if (isset($this->mixed_member_names[$key])) {
                $this->mixed_member_names[$key] = array_merge($this->mixed_member_names[$key], $name);
            } else {
                $this->mixed_member_names[$key] = $name;
            }
        }
    }
    /**
     * @return list{int, int}
     */
    public function get_mixed_counts_for_file(string $file_path): array
    {
        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }
        return $this->mixed_counts[$file_path];
    }
    /**
     * @param  list{int, int} $mixed_counts
     */
    public function set_mixed_counts_for_file(string $file_path, array $mixed_counts): void
    {
        $this->mixed_counts[$file_path] = $mixed_counts;
    }
    public function increment_mixed_count(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }
        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }
        ++$this->mixed_counts[$file_path][0];
    }
    public function decrement_mixed_count(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }
        if (!isset($this->mixed_counts[$file_path])) {
            return;
        }
        if ($this->mixed_counts[$file_path][0] === 0) {
            return;
        }
        --$this->mixed_counts[$file_path][0];
    }
    public function increment_non_mixed_count(string $file_path): void
    {
        if (!$this->count_mixed) {
            return;
        }
        if (!isset($this->mixed_counts[$file_path])) {
            $this->mixed_counts[$file_path] = [0, 0];
        }
        ++$this->mixed_counts[$file_path][1];
    }
    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public function get_mixed_counts(): array
    {
        $all_deep_scanned_files = [];
        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;
        }
        return array_intersect_key($this->mixed_counts, $all_deep_scanned_files);
    }
    /**
     * @return array<string, float>
     */
    public function get_function_timings(): array
    {
        return $this->function_timings;
    }
    public function add_function_timing(string $function_id, float $time_per_node): void
    {
        $this->function_timings[$function_id] = $time_per_node;
    }
    public function add_node_type(string $file_path, Php_Parser\Node $node, string $node_type, ?Php_Parser\Node $parent_node = null): void
    {
        if ($node_type === '') {
            throw new UnexpectedValueException('non-empty node_type expected');
        }
        $this->type_map[$file_path][(int) $node->get_attribute('startFilePos')] = [($parent_node ? (int) $parent_node->get_attribute('endFilePos') : (int) $node->get_attribute('endFilePos')) + 1, $node_type];
    }
    public function add_node_argument(string $file_path, int $start_position, int $end_position, string $reference, int $argument_number): void
    {
        if ($reference === '') {
            throw new UnexpectedValueException('non-empty reference expected');
        }
        $this->argument_map[$file_path][$start_position] = [$end_position, $reference, $argument_number];
    }
    /**
     * @param string $reference The symbol name for the reference.
     *                          Prepend with an asterisk (*) to signify a reference that doesn't exist.
     */
    public function add_node_reference(string $file_path, Php_Parser\Node $node, string $reference): void
    {
        if (!$reference) {
            throw new UnexpectedValueException('non-empty node_type expected');
        }
        $this->reference_map[$file_path][(int) $node->get_attribute('startFilePos')] = [(int) $node->get_attribute('endFilePos') + 1, $reference];
    }
    public function add_offset_reference(string $file_path, int $start, int $end, string $reference): void
    {
        if (!$reference) {
            throw new UnexpectedValueException('non-empty node_type expected');
        }
        $this->reference_map[$file_path][$start] = [$end, $reference];
    }
    /**
     * @return array{int, int}
     */
    public function get_total_type_coverage(Codebase $codebase): array
    {
        $mixed_count = 0;
        $nonmixed_count = 0;
        foreach ($codebase->file_reference_provider->get_type_coverage() as $file_path => $counts) {
            if (!$this->config->report_type_stats_for_file($file_path)) {
                continue;
            }
            [$path_mixed_count, $path_nonmixed_count] = $counts;
            if (isset($this->mixed_counts[$file_path])) {
                $mixed_count += $path_mixed_count;
                $nonmixed_count += $path_nonmixed_count;
            }
        }
        return [$mixed_count, $nonmixed_count];
    }
    public function get_type_inference_summary(Codebase $codebase): string
    {
        $all_deep_scanned_files = [];
        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;
            foreach ($this->file_storage_provider->get($file_path)->required_file_paths as $required_file_path) {
                $all_deep_scanned_files[$required_file_path] = true;
            }
        }
        [$mixed_count, $nonmixed_count] = $this->get_total_type_coverage($codebase);
        $total = $mixed_count + $nonmixed_count;
        $total_files = count($all_deep_scanned_files);
        $lines = [];
        if (!$total_files) {
            $lines[] = 'No files analyzed';
        }
        if (!$total) {
            $lines[] = 'Psalm was unable to infer types in the codebase';
        } else {
            $percentage = $nonmixed_count === $total ? '100' : number_format(100 * $nonmixed_count / $total, 4);
            $lines[] = 'Psalm was able to infer types for ' . $percentage . '%' . ' of the codebase';
        }
        return implode("\n", $lines);
    }
    public function get_non_mixed_stats(): string
    {
        $stats = '';
        $all_deep_scanned_files = [];
        foreach ($this->files_to_analyze as $file_path => $_) {
            $all_deep_scanned_files[$file_path] = true;
            if (!$this->config->report_type_stats_for_file($file_path)) {
                continue;
            }
            foreach ($this->file_storage_provider->get($file_path)->required_file_paths as $required_file_path) {
                $all_deep_scanned_files[$required_file_path] = true;
            }
        }
        foreach ($all_deep_scanned_files as $file_path => $_) {
            if (isset($this->mixed_counts[$file_path])) {
                [$path_mixed_count, $path_nonmixed_count] = $this->mixed_counts[$file_path];
                if ($path_mixed_count + $path_nonmixed_count) {
                    $stats .= number_format(100 * $path_nonmixed_count / ($path_mixed_count + $path_nonmixed_count), 3) . '% ' . $this->config->shorten_file_name($file_path) . ' (' . $path_mixed_count . ' mixed)' . "\n";
                }
            }
        }
        return $stats;
    }
    public function disable_mixed_counts(): void
    {
        $this->count_mixed = false;
    }
    public function enable_mixed_counts(): void
    {
        $this->count_mixed = true;
    }
    public function update_file(string $file_path, bool $dry_run): void
    {
        File_Manipulation_Buffer::add($file_path, Function_Docblock_Manipulator::get_manipulations_for_file($file_path));
        File_Manipulation_Buffer::add($file_path, Property_Docblock_Manipulator::get_manipulations_for_file($file_path));
        File_Manipulation_Buffer::add($file_path, Class_Docblock_Manipulator::get_manipulations_for_file($file_path));
        $file_manipulations = File_Manipulation_Buffer::get_manipulations_for_file($file_path);
        if (!$file_manipulations) {
            return;
        }
        usort($file_manipulations, static function (File_Manipulation $a, File_Manipulation $b): int {
            if ($b->end === $a->end) {
                if ($a->start === $b->start) {
                    return $b->insertion_text > $a->insertion_text ? 1 : -1;
                }
                return $b->start > $a->start ? 1 : -1;
            }
            return $b->end > $a->end ? 1 : -1;
        });
        $last_start = PHP_INT_MAX;
        $existing_contents = $this->file_provider->get_contents($file_path);
        foreach ($file_manipulations as $manipulation) {
            if ($manipulation->start <= $last_start) {
                $existing_contents = $manipulation->transform($existing_contents);
                $last_start = $manipulation->start;
            }
        }
        if ($dry_run) {
            echo $file_path . ':' . "\n";
            $differ = new Differ(new Strict_Unified_Diff_Output_Builder(['fromFile' => $file_path, 'toFile' => $file_path]));
            echo $differ->diff($this->file_provider->get_contents($file_path), $existing_contents);
            return;
        }
        $this->progress->alter_file_done($file_path);
        $this->file_provider->set_contents($file_path, $existing_contents);
    }
    /**
     * @return list<IssueData>
     */
    public function get_existing_issues_for_file(string $file_path, int $start, int $end, ?string $issue_type = null): array
    {
        if (!isset($this->existing_issues[$file_path])) {
            return [];
        }
        $applicable_issues = [];
        foreach ($this->existing_issues[$file_path] as $issue_data) {
            if (!($issue_data->from >= $start)) {
                continue;
            }
            if (!($issue_data->from <= $end)) {
                continue;
            }
            if (!($issue_type === null || $issue_type === $issue_data->type)) {
                continue;
            }
            $applicable_issues[] = $issue_data;
        }
        return $applicable_issues;
    }
    public function remove_existing_data_for_file(string $file_path, int $start, int $end, ?string $issue_type = null): void
    {
        if (isset($this->existing_issues[$file_path])) {
            foreach ($this->existing_issues[$file_path] as $i => $issue_data) {
                if (!($issue_data->from >= $start)) {
                    continue;
                }
                if (!($issue_data->from <= $end)) {
                    continue;
                }
                if (!($issue_type === null || $issue_type === $issue_data->type)) {
                    continue;
                }
                unset($this->existing_issues[$file_path][$i]);
            }
        }
        if (isset($this->type_map[$file_path])) {
            foreach ($this->type_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->type_map[$file_path][$map_start]);
                }
            }
        }
        if (isset($this->reference_map[$file_path])) {
            foreach ($this->reference_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->reference_map[$file_path][$map_start]);
                }
            }
        }
        if (isset($this->argument_map[$file_path])) {
            foreach ($this->argument_map[$file_path] as $map_start => $_) {
                if ($map_start >= $start && $map_start <= $end) {
                    unset($this->argument_map[$file_path][$map_start]);
                }
            }
        }
    }
    /**
     * @return array<string, array<string, int>>
     */
    public function get_analyzed_methods(): array
    {
        return $this->analyzed_methods;
    }
    /**
     * @return array<string, FileMapType>
     */
    public function get_file_maps(): array
    {
        $file_maps = [];
        foreach ($this->reference_map as $file_path => $reference_map) {
            $file_maps[$file_path] = [$reference_map, [], []];
        }
        foreach ($this->type_map as $file_path => $type_map) {
            if (isset($file_maps[$file_path])) {
                $file_maps[$file_path][1] = $type_map;
            } else {
                $file_maps[$file_path] = [[], $type_map, []];
            }
        }
        foreach ($this->argument_map as $file_path => $argument_map) {
            if (isset($file_maps[$file_path])) {
                $file_maps[$file_path][2] = $argument_map;
            } else {
                $file_maps[$file_path] = [[], [], $argument_map];
            }
        }
        return $file_maps;
    }
    /**
     * @return FileMapType
     */
    public function get_maps_for_file(string $file_path): array
    {
        return [$this->reference_map[$file_path] ?? [], $this->type_map[$file_path] ?? [], $this->argument_map[$file_path] ?? []];
    }
    /**
     * @return array<string, array<int, Union>>
     */
    public function get_possible_method_param_types(): array
    {
        return $this->possible_method_param_types;
    }
    public function add_mutable_class(string $fqcln): void
    {
        $this->mutable_classes[strtolower($fqcln)] = true;
    }
    public function set_analyzed_method(string $file_path, string $method_id, bool $is_constructor = false): void
    {
        $this->analyzed_methods[$file_path][$method_id] = $is_constructor ? 2 : 1;
    }
    public function is_method_already_analyzed(string $file_path, string $method_id, bool $is_constructor = false): bool
    {
        if ($is_constructor) {
            return isset($this->analyzed_methods[$file_path][$method_id]) && $this->analyzed_methods[$file_path][$method_id] === 2;
        }
        return isset($this->analyzed_methods[$file_path][$method_id]);
    }
    /**
     * @internal
     */
    public static function analysis_worker(Config $config, Progress $progress, string $file_path): int
    {
        $extension = pathinfo($file_path, PATHINFO_EXTENSION);
        $file_name = $config->shorten_file_name($file_path);
        $filetype_analyzers = $config->get_filetype_analyzers();
        if (isset($filetype_analyzers[$extension])) {
            $file_analyzer = new $filetype_analyzers[$extension](Project_Analyzer::get_instance(), $file_path, $file_name);
        } else {
            $file_analyzer = new File_Analyzer(Project_Analyzer::get_instance(), $file_path, $file_name);
        }
        $progress->debug('Analyzing ' . $file_analyzer->get_file_path() . "\n");
        $file_analyzer->analyze();
        $file_analyzer->context = null;
        $file_analyzer->clear_source_before_destruction();
        unset($file_analyzer);
        $has_error = false;
        $has_info = false;
        foreach (Issue_Buffer::get_issues_data_for_file($file_path) as $issue) {
            switch ($issue->severity) {
                case Issue_Data::SEVERITY_INFO:
                    $has_info = true;
                    break;
                default:
                    $has_error = true;
                    break;
            }
        }
        return $has_error ? 2 : ($has_info ? 1 : 0);
    }
}