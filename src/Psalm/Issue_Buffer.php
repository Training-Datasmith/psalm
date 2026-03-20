<?php

declare (strict_types=1);
namespace Psalm;

use Psalm\Code_Location\Raw;
use Psalm\Exception\Code_Exception;
use Psalm\Internal\Analyzer\File_Analyzer;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Execution_Environment\Build_Info_Collector;
use Psalm\Internal\Execution_Environment\Git_Info_Collector;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Issue\Code_Issue;
use Psalm\Issue\Config_Issue;
use Psalm\Issue\Mixed_Issue;
use Psalm\Issue\Tainted_Input;
use Psalm\Issue\Unused_Baseline_Entry;
use Psalm\Issue\Unused_Issue_Handler_Suppression;
use Psalm\Issue\Unused_Psalm_Suppress;
use Psalm\Plugin\Event_Handler\Event\After_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Add_Issue_Event;
use Psalm\Report\By_Issue_Level_And_Type_Report;
use Psalm\Report\Checkstyle_Report;
use Psalm\Report\Code_Climate_Report;
use Psalm\Report\Compact_Report;
use Psalm\Report\Console_Report;
use Psalm\Report\Count_Report;
use Psalm\Report\Emacs_Report;
use Psalm\Report\Github_Actions_Report;
use Psalm\Report\Json_Report;
use Psalm\Report\Json_Summary_Report;
use Psalm\Report\Junit_Report;
use Psalm\Report\Php_Storm_Report;
use Psalm\Report\Pylint_Report;
use Psalm\Report\Report_Options;
use Psalm\Report\Sarif_Report;
use Psalm\Report\Sonarqube_Report;
use Psalm\Report\Text_Report;
use Psalm\Report\Xml_Report;
use RuntimeException;
use UnexpectedValueException;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_search;
use function array_splice;
use function array_sum;
use function array_values;
use function arsort;
use function count;
use function debug_print_backtrace;
use function dirname;
use function explode;
use function file_put_contents;
use function fwrite;
use function implode;
use function in_array;
use function is_dir;
use function is_int;
use function ksort;
use function memory_get_peak_usage;
use function microtime;
use function mkdir;
use function number_format;
use function ob_get_clean;
use function ob_start;
use function preg_match;
use function round;
use function sha1;
use function sprintf;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function trim;
use function usort;
use const DEBUG_BACKTRACE_IGNORE_ARGS;
use const PHP_EOL;
use const PSALM_VERSION;
use const STDERR;
final class Issue_Buffer
{
    /**
     * @var array<string, list<IssueData>>
     */
    private static array $issues_data = [];
    /**
     * @var array<int, array>
     */
    private static array $console_issues = [];
    /**
     * @var array<string, int>
     */
    private static array $fixable_issue_counts = [];
    private static int $error_count = 0;
    /**
     * @var array<string, bool>
     */
    private static array $emitted = [];
    private static int $recording_level = 0;
    /** @var array<int, array<int, CodeIssue>> */
    private static array $recorded_issues = [];
    /**
     * @var array<string, array<int, int>>
     */
    private static array $unused_suppressions = [];
    /**
     * @var array<string, array<int, bool>>
     */
    private static array $used_suppressions = [];
    /** @var array<array-key,mixed> */
    private static array $server = [];
    /**
     * This will add an issue to be emitted if it's not suppressed and return if it has been added
     *
     * @param string[]  $suppressed_issues
     */
    public static function accepts(Code_Issue $e, array $suppressed_issues = [], bool $is_fixable = false): bool
    {
        $config = Config::get_instance();
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $event = new Before_Add_Issue_Event($e, $is_fixable, $codebase);
        if ($config->event_dispatcher->dispatch_before_add_issue($event) === false) {
            return false;
        }
        if (self::is_suppressed($e, $suppressed_issues)) {
            return false;
        }
        return self::add($e, $is_fixable);
    }
    /**
     * This will add an issue to be emitted if it's not suppressed
     *
     * @param string[]  $suppressed_issues
     */
    public static function maybe_add(Code_Issue $e, array $suppressed_issues = [], bool $is_fixable = false): void
    {
        self::accepts($e, $suppressed_issues, $is_fixable);
    }
    /**
     * This is part of the findUnusedPsalmSuppress feature
     */
    public static function add_unused_suppression(string $file_path, int $offset, string $issue_type): void
    {
        if (str_starts_with($issue_type, 'Tainted')) {
            return;
        }
        if (isset(self::$used_suppressions[$file_path][$offset])) {
            return;
        }
        if (!isset(self::$unused_suppressions[$file_path])) {
            self::$unused_suppressions[$file_path] = [];
        }
        self::$unused_suppressions[$file_path][$offset] = $offset + strlen($issue_type) - 1;
    }
    /**
     * This will return false if an issue is ready to be added for emission. Reasons for not returning false include:
     * - The issue is suppressed in config
     * - We're in a recording state
     * - The issue is included in the list of issues to be suppressed in param
     *
     * @param string[] $suppressed_issues
     */
    public static function is_suppressed(Code_Issue $e, array $suppressed_issues = []): bool
    {
        $config = Config::get_instance();
        $fqcn_parts = explode('\\', $e::class);
        $issue_type = array_pop($fqcn_parts);
        $file_path = $e->get_file_path();
        if (!$e instanceof Config_Issue && !$config->report_issue_in_file($issue_type, $file_path)) {
            return true;
        }
        $suppressed_issue_position = array_search($issue_type, $suppressed_issues, true);
        if ($suppressed_issue_position !== false) {
            if (is_int($suppressed_issue_position)) {
                self::$used_suppressions[$file_path][$suppressed_issue_position] = true;
            }
            return true;
        }
        $parent_issue_type = Config::get_parent_issue_type($issue_type);
        if ($parent_issue_type) {
            $suppressed_issue_position = array_search($parent_issue_type, $suppressed_issues, true);
            if ($suppressed_issue_position !== false) {
                if (is_int($suppressed_issue_position)) {
                    self::$used_suppressions[$file_path][$suppressed_issue_position] = true;
                }
                return true;
            }
        }
        $suppress_all_position = $config->disable_suppress_all ? false : array_search('all', $suppressed_issues, true);
        if ($suppress_all_position !== false) {
            if (is_int($suppress_all_position)) {
                self::$used_suppressions[$file_path][$suppress_all_position] = true;
            }
            return true;
        }
        $reporting_level = $config->get_reporting_level_for_issue($e);
        if ($reporting_level === Config::REPORT_SUPPRESS) {
            return true;
        }
        if ($e->code_location->get_line_number() === -1) {
            return true;
        }
        if (self::$recording_level > 0) {
            self::$recorded_issues[self::$recording_level][] = $e;
            return true;
        }
        return false;
    }
    /**
     * Add an issue to be emitted. This method should normally not be used! Use IssueBuffer::maybeAdd instead.
     *
     * @psalm-internal Psalm\IssueBuffer
     * @psalm-internal Psalm\Type\Reconciler::getValueForKey
     * @throws  CodeException
     */
    public static function add(Code_Issue $e, bool $is_fixable = false): bool
    {
        $config = Config::get_instance();
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $fqcn_parts = explode('\\', $e::class);
        $issue_type = array_pop($fqcn_parts);
        if (!$project_analyzer->show_issues) {
            return false;
        }
        $is_tainted = str_starts_with($issue_type, 'Tainted');
        if ($codebase->taint_flow_graph && !$is_tainted) {
            return false;
        }
        $reporting_level = $config->get_reporting_level_for_issue($e);
        if ($reporting_level === Config::REPORT_SUPPRESS) {
            return false;
        }
        if ($config->debug_emitted_issues) {
            ob_start();
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $trace = ob_get_clean();
            fwrite(STDERR, "\nEmitting {$e->get_short_location()} {$issue_type} {$e->message}\n{$trace}\n");
        }
        // Make issue type for trace variable specific ("Trace" => "Trace~$var").
        $trace_var = $issue_type === 'Trace' && preg_match('/^(\$.+?):/', $e->message, $m) === 1 && isset($m[1]) ? '~' . $m[1] : '';
        $emitted_key = $issue_type . $trace_var . '-' . $e->get_short_location() . ':' . $e->code_location->get_column() . ' ' . ($e->dupe_key ?? $e->message);
        if ($reporting_level === Config::REPORT_INFO) {
            if ($is_tainted || !self::already_emitted($emitted_key)) {
                self::$issues_data[$e->get_file_path()][] = $e->to_issue_data(Issue_Data::SEVERITY_INFO);
                if ($is_fixable) {
                    self::add_fixable_issue($issue_type);
                }
            }
            return false;
        }
        if ($config->throw_exception) {
            File_Analyzer::clear_cache();
            $message = $e instanceof Tainted_Input ? $e->get_journey_message() : ($e instanceof Mixed_Issue ? $e->get_mixed_origin_message() : $e->message);
            throw new Code_Exception($issue_type . ' - ' . $e->get_short_location_with_previous() . ':' . $e->code_location->get_column() . ' - ' . $message);
        }
        if ($is_tainted || !self::already_emitted($emitted_key)) {
            ++self::$error_count;
            self::$issues_data[$e->get_file_path()][] = $e->to_issue_data(Issue_Data::SEVERITY_ERROR);
            if ($is_fixable) {
                self::add_fixable_issue($issue_type);
            }
        }
        return true;
    }
    private static function remove_recorded_issue(string $issue_type, int $file_offset): void
    {
        $recorded_issues = self::$recorded_issues[self::$recording_level];
        $filtered_issues = [];
        foreach ($recorded_issues as $issue) {
            [$from] = $issue->code_location->get_selection_bounds();
            if ($issue::get_issue_type() !== $issue_type || $from !== $file_offset) {
                $filtered_issues[] = $issue;
            }
        }
        self::$recorded_issues[self::$recording_level] = $filtered_issues;
    }
    /**
     * This will try to remove an issue that has been added for emission
     */
    public static function remove(string $file_path, string $issue_type, int $file_offset): void
    {
        if (self::$recording_level > 0) {
            self::remove_recorded_issue($issue_type, $file_offset);
        }
        if (!isset(self::$issues_data[$file_path])) {
            return;
        }
        $filtered_issues = [];
        foreach (self::$issues_data[$file_path] as $issue) {
            if ($issue->type !== $issue_type || $issue->from !== $file_offset) {
                $filtered_issues[] = $issue;
            }
        }
        if (empty($filtered_issues)) {
            unset(self::$issues_data[$file_path]);
        } else {
            self::$issues_data[$file_path] = $filtered_issues;
        }
    }
    public static function add_fixable_issue(string $issue_type): void
    {
        if (isset(self::$fixable_issue_counts[$issue_type])) {
            self::$fixable_issue_counts[$issue_type]++;
        } else {
            self::$fixable_issue_counts[$issue_type] = 1;
        }
    }
    /**
     * @return array<string, list<IssueData>>
     */
    public static function get_issues_data(): array
    {
        return self::$issues_data;
    }
    /**
     * @return list<IssueData>
     */
    public static function get_issues_data_for_file(string $file_path): array
    {
        return self::$issues_data[$file_path] ?? [];
    }
    /**
     * @return array<string, int>
     */
    public static function get_fixable_issues(): array
    {
        return self::$fixable_issue_counts;
    }
    /**
     * @param array<string, int> $fixable_issue_counts
     */
    public static function add_fixable_issues(array $fixable_issue_counts): void
    {
        foreach ($fixable_issue_counts as $issue_type => $count) {
            if (isset(self::$fixable_issue_counts[$issue_type])) {
                self::$fixable_issue_counts[$issue_type] += $count;
            } else {
                self::$fixable_issue_counts[$issue_type] = $count;
            }
        }
    }
    /**
     * @return array<string, array<int, int>>
     */
    public static function get_unused_suppressions(): array
    {
        return self::$unused_suppressions;
    }
    /**
     * @return array<string, array<int, bool>>
     */
    public static function get_used_suppressions(): array
    {
        return self::$used_suppressions;
    }
    /**
     * @param array<string, array<int, int>> $unused_suppressions
     */
    public static function add_unused_suppressions(array $unused_suppressions): void
    {
        self::$unused_suppressions += $unused_suppressions;
    }
    /**
     * @param array<string, array<int, bool>> $used_suppressions
     */
    public static function add_used_suppressions(array $used_suppressions): void
    {
        foreach ($used_suppressions as $file => $offsets) {
            if (!isset(self::$used_suppressions[$file])) {
                self::$used_suppressions[$file] = $offsets;
            } else {
                self::$used_suppressions[$file] += $offsets;
            }
        }
    }
    public static function process_unused_suppressions(File_Provider $file_provider): void
    {
        $config = Config::get_instance();
        foreach (self::$unused_suppressions as $file_path => $offsets) {
            if (!$offsets) {
                continue;
            }
            if (!$config->is_in_project_dirs($file_path)) {
                continue;
            }
            $file_contents = $file_provider->get_contents($file_path);
            foreach ($offsets as $start => $end) {
                if (isset(self::$used_suppressions[$file_path][$start])) {
                    continue;
                }
                self::add(new Unused_Psalm_Suppress('This suppression is never used', new Raw($file_contents, $file_path, $config->shorten_file_name($file_path), $start, $end)));
            }
        }
    }
    public static function get_error_count(): int
    {
        return self::$error_count;
    }
    /**
     * @param array<string, list<IssueData>> $issues_data
     */
    public static function add_issues(array $issues_data): void
    {
        foreach ($issues_data as $file_path => $file_issues) {
            foreach ($file_issues as $issue) {
                $emitted_key = $issue->type . '-' . $issue->file_name . ':' . $issue->line_from . ':' . $issue->column_from . ' ' . ($issue->dupe_key ?? $issue->message);
                if (!self::already_emitted($emitted_key)) {
                    self::$issues_data[$file_path][] = $issue;
                }
            }
        }
    }
    /**
     * @param  array<string,array<string,array{o:int, s:array<int, string>}>>  $issue_baseline
     */
    public static function finish(Project_Analyzer $project_analyzer, bool $is_full, float $start_time, bool $add_stats = false, array $issue_baseline = []): void
    {
        if (!$project_analyzer->stdout_report_options) {
            throw new UnexpectedValueException('Cannot finish without stdout report options');
        }
        $codebase = $project_analyzer->get_codebase();
        foreach ($codebase->config->config_issues as $issue) {
            self::maybe_add($issue);
        }
        $error_count = 0;
        $info_count = 0;
        $issues_data = [];
        if (self::$issues_data) {
            if (in_array($project_analyzer->stdout_report_options->format, [Report::TYPE_CONSOLE, Report::TYPE_PHP_STORM])) {
                echo "\n";
            }
            ksort(self::$issues_data);
            foreach (self::$issues_data as $file_path => $file_issues) {
                usort($file_issues, static fn(Issue_Data $d1, Issue_Data $d2): int => [$d1->file_path, $d1->line_from, $d1->column_from] <=> [$d2->file_path, $d2->line_from, $d2->column_from]);
                self::$issues_data[$file_path] = $file_issues;
            }
            // make a copy so what gets saved in cache is unaffected by baseline
            $issues_data = self::$issues_data;
        }
        if (!empty($issue_baseline)) {
            // Set severity for issues in baseline to INFO
            foreach ($issues_data as $file_path => $file_issues) {
                foreach ($file_issues as $key => $issue_data) {
                    $file = $issue_data->file_name;
                    $file = str_replace('\\', '/', $file);
                    $type = $issue_data->type;
                    if (isset($issue_baseline[$file][$type]) && $issue_baseline[$file][$type]['o'] > 0) {
                        if ($issue_baseline[$file][$type]['o'] === count($issue_baseline[$file][$type]['s'])) {
                            $position = array_search(str_replace("\r\n", "\n", trim($issue_data->selected_text)), $issue_baseline[$file][$type]['s'], true);
                            if ($position !== false) {
                                $issue_data->severity = Issue_Data::SEVERITY_INFO;
                                array_splice($issue_baseline[$file][$type]['s'], $position, 1);
                                $issue_baseline[$file][$type]['o']--;
                            }
                        } else {
                            $issue_baseline[$file][$type]['s'] = [];
                            $issue_data->severity = Issue_Data::SEVERITY_INFO;
                            $issue_baseline[$file][$type]['o']--;
                        }
                    }
                    $issues_data[$file_path][$key] = $issue_data;
                }
            }
            if ($codebase->config->find_unused_baseline_entry) {
                foreach ($issue_baseline as $file_path => $issues) {
                    foreach ($issues as $issue_name => $issue) {
                        if ($issue['o'] !== 0) {
                            $issues_data[$file_path][] = new Issue_Data(Issue_Data::SEVERITY_ERROR, 0, 0, Unused_Baseline_Entry::get_issue_type(), sprintf('Baseline for issue "%s" has %d extra %s.', $issue_name, $issue['o'], $issue['o'] === 1 ? 'entry' : 'entries'), $file_path, '', '', '', 0, 0, 0, 0, 0, 0, Unused_Baseline_Entry::SHORTCODE, Unused_Baseline_Entry::ERROR_LEVEL);
                        }
                    }
                }
            }
        }
        if ($codebase->config->find_unused_issue_handler_suppression) {
            if ($is_full && !$codebase->diff_run) {
                foreach ($codebase->config->get_issue_handlers() as $type => $handler) {
                    foreach ($handler->get_filters() as $filter) {
                        if ($filter->suppressions > 0) {
                            continue;
                        }
                        if ($filter->get_error_level() != Config::REPORT_SUPPRESS) {
                            continue;
                        }
                        $issues_data['config'][] = new Issue_Data(Issue_Data::SEVERITY_ERROR, 0, 0, Unused_Issue_Handler_Suppression::get_issue_type(), sprintf('Suppressed issue type "%s" for %s was not thrown.', $type, str_replace($codebase->config->base_dir, '', implode(', ', [...$filter->get_files(), ...$filter->get_directories()]))), $codebase->config->source_filename ?? '', '', '', '', 0, 0, 0, 0, 0, 0, Unused_Issue_Handler_Suppression::SHORTCODE, Unused_Issue_Handler_Suppression::ERROR_LEVEL);
                    }
                }
            }
        }
        echo self::get_output($issues_data, $project_analyzer->stdout_report_options, $codebase->analyzer->get_total_type_coverage($codebase));
        foreach ($issues_data as $file_issues) {
            foreach ($file_issues as $issue_data) {
                if ($issue_data->severity === Config::REPORT_ERROR) {
                    ++$error_count;
                } else {
                    ++$info_count;
                }
            }
        }
        if ($codebase->config->event_dispatcher->after_analysis) {
            $source_control_info = null;
            $build_info = (new Build_Info_Collector(self::$server))->collect();
            try {
                $source_control_info = (new Git_Info_Collector())->collect();
            } catch (RuntimeException) {
                // do nothing
            }
            /** @psalm-suppress ArgumentTypeCoercion due to Psalm bug */
            $event = new After_Analysis_Event($codebase, $issues_data, $build_info, $source_control_info);
            $codebase->config->event_dispatcher->dispatch_after_analysis($event);
        }
        foreach ($project_analyzer->generated_report_options as $report_options) {
            if (!$report_options->output_path) {
                throw new UnexpectedValueException('Output path should not be null here');
            }
            $folder = dirname((string) $report_options->output_path);
            if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $folder));
            }
            file_put_contents($report_options->output_path, self::get_output($issues_data, $report_options, $codebase->analyzer->get_total_type_coverage($codebase)));
        }
        if (in_array($project_analyzer->stdout_report_options->format, [Report::TYPE_CONSOLE, Report::TYPE_PHP_STORM, Report::TYPE_GITHUB_ACTIONS])) {
            echo str_repeat('-', 30) . "\n";
            if ($error_count) {
                echo ($project_analyzer->stdout_report_options->use_color ? "\x1b[0;31m" . $error_count . " errors\x1b[0m" : $error_count . ' errors') . ' found' . "\n";
            } else {
                self::print_success_message($project_analyzer);
            }
            $show_info = $project_analyzer->stdout_report_options->show_info;
            $show_suggestions = $project_analyzer->stdout_report_options->show_suggestions;
            if ($info_count && ($show_info || $show_suggestions)) {
                echo str_repeat('-', 30) . "\n";
                echo $info_count . ' other issues found.' . "\n";
                if (!$show_info) {
                    echo 'You can display them with ' . ($project_analyzer->stdout_report_options->use_color ? "\x1b[30;48;5;195m--show-info=true\x1b[0m" : '--show-info=true') . "\n";
                }
            }
            if (self::$fixable_issue_counts && $show_suggestions && !$codebase->taint_flow_graph) {
                echo str_repeat('-', 30) . "\n";
                $total_count = array_sum(self::$fixable_issue_counts);
                $command = '--alter --issues=' . implode(',', array_keys(self::$fixable_issue_counts));
                $command .= ' --dry-run';
                echo 'Psalm can automatically fix ' . $total_count . ($show_info ? ' issues' : ' of these issues') . ".\n" . 'Run Psalm again with ' . "\n" . ($project_analyzer->stdout_report_options->use_color ? "\x1b[30;48;5;195m" . $command . "\x1b[0m" : $command) . "\n" . 'to see what it can fix.' . "\n";
            }
            echo str_repeat('-', 30) . "\n" . "\n";
            if ($start_time) {
                echo 'Checks took ' . number_format(microtime(true) - $start_time, 2) . ' seconds';
                echo ' and used ' . number_format(memory_get_peak_usage() / (1024 * 1024), 3) . 'MB of memory' . "\n";
                $analysis_summary = $codebase->analyzer->get_type_inference_summary($codebase);
                echo $analysis_summary . "\n";
                if ($add_stats) {
                    echo '-----------------' . "\n";
                    echo $codebase->analyzer->get_non_mixed_stats();
                    echo "\n";
                }
                if ($project_analyzer->debug_performance) {
                    echo '-----------------' . "\n";
                    echo 'Slow-to-analyze functions' . "\n";
                    echo '-----------------' . "\n\n";
                    $function_timings = $codebase->analyzer->get_function_timings();
                    arsort($function_timings);
                    $i = 0;
                    foreach ($function_timings as $function_id => $time) {
                        if (++$i > 10) {
                            break;
                        }
                        echo $function_id . ': ' . round(1000 * $time, 2) . 'ms per node' . "\n";
                    }
                    echo "\n";
                }
            }
            if ($codebase->config->find_unused_issue_handler_suppression && (!$is_full || $codebase->diff_run)) {
                fwrite(STDERR, PHP_EOL . 'To whom it may concern: Psalm cannot detect unused issue handler suppressions when' . PHP_EOL . 'analyzing individual files and folders or running in diff mode. Run on the full' . PHP_EOL . 'project with diff mode off to enable unused issue handler detection.' . PHP_EOL);
            }
        }
        if ($is_full && $start_time) {
            $project_analyzer->finish($start_time, PSALM_VERSION);
        }
        if ($error_count && !($codebase->taint_flow_graph && $project_analyzer->generated_report_options && isset($_SERVER['GITHUB_WORKFLOW']))) {
            exit(2);
        }
    }
    public static function print_success_message(Project_Analyzer $project_analyzer): void
    {
        if (!$project_analyzer->stdout_report_options) {
            throw new UnexpectedValueException('Cannot print success message without stdout report options');
        }
        // this message will be printed
        $message = "No errors found!";
        // color block will contain this amount of characters
        $block_size = 30;
        // message with prepended and appended whitespace to be same as $blockSize
        $message_with_padding = str_repeat(' ', 7) . $message . str_repeat(' ', 7);
        // top side of the color block
        $padding_top = str_repeat(' ', $block_size);
        // bottom side of the color block
        $padding_bottom = str_repeat(' ', $block_size);
        // background color, 42 = green
        $background = "42";
        // foreground/text color, 30 = black
        $foreground = "30";
        // text style, 1 = bold
        $style = "2";
        if ($project_analyzer->stdout_report_options->use_color) {
            echo "\x1b[{$background};{$style}m{$padding_top}\x1b[0m" . "\n";
            echo "\x1b[{$background};{$foreground};{$style}m{$message_with_padding}\x1b[0m" . "\n";
            echo "\x1b[{$background};{$style}m{$padding_bottom}\x1b[0m" . "\n";
        } else {
            echo "\n";
            echo "{$message_with_padding}\n";
            echo "\n";
        }
    }
    /**
     * @param array<string, array<int, IssueData>> $issues_data
     * @param array{int, int} $mixed_counts
     */
    public static function get_output(array $issues_data, Report_Options $report_options, array $mixed_counts = [0, 0]): string
    {
        $total_expression_count = $mixed_counts[0] + $mixed_counts[1];
        $mixed_expression_count = $mixed_counts[0];
        $normalized_data = $issues_data === [] ? [] : array_merge(...array_values($issues_data));
        $format = $report_options->format;
        $output = match ($format) {
            Report::TYPE_COMPACT => new Compact_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_EMACS => new Emacs_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_TEXT => new Text_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_JSON => new Json_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_BY_ISSUE_LEVEL => new By_Issue_Level_And_Type_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_JSON_SUMMARY => new Json_Summary_Report($normalized_data, self::$fixable_issue_counts, $report_options, $mixed_expression_count, $total_expression_count),
            Report::TYPE_SONARQUBE => new Sonarqube_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_PYLINT => new Pylint_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_CHECKSTYLE => new Checkstyle_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_XML => new Xml_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_JUNIT => new Junit_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_CONSOLE => new Console_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_GITHUB_ACTIONS => new Github_Actions_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_PHP_STORM => new Php_Storm_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_SARIF => new Sarif_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_CODECLIMATE => new Code_Climate_Report($normalized_data, self::$fixable_issue_counts, $report_options),
            Report::TYPE_COUNT => new Count_Report($normalized_data, self::$fixable_issue_counts, $report_options),
        };
        return $output->create();
    }
    public static function already_emitted(string $message): bool
    {
        $sham = sha1($message);
        if (isset(self::$emitted[$sham])) {
            return true;
        }
        self::$emitted[$sham] = true;
        return false;
    }
    public static function clear_cache(): void
    {
        self::$issues_data = [];
        self::$emitted = [];
        self::$error_count = 0;
        self::$recording_level = 0;
        self::$recorded_issues = [];
        self::$console_issues = [];
        self::$unused_suppressions = [];
        self::$used_suppressions = [];
    }
    /**
     * @return array<string, list<IssueData>>
     */
    public static function clear(): array
    {
        $current_data = self::$issues_data;
        self::$issues_data = [];
        self::$emitted = [];
        return $current_data;
    }
    /**
     * Return whether or not we're in a recording state regarding startRecording/stopRecording status
     */
    public static function is_recording(): bool
    {
        return self::$recording_level > 0;
    }
    /**
     * Increase the recording level in order to start recording issues instead of adding them while in a loop
     */
    public static function start_recording(): void
    {
        ++self::$recording_level;
        self::$recorded_issues[self::$recording_level] = [];
    }
    /**
     * Decrease the recording level after leaving a loop
     *
     * @see startRecording
     */
    public static function stop_recording(): void
    {
        if (self::$recording_level === 0) {
            throw new UnexpectedValueException('Cannot stop recording - already at base level');
        }
        --self::$recording_level;
    }
    /**
     * This will return the recorded issues for the current recording level
     *
     * @return array<int, CodeIssue>
     */
    public static function clear_recording_level(): array
    {
        if (self::$recording_level === 0) {
            throw new UnexpectedValueException('Not currently recording');
        }
        $recorded_issues = self::$recorded_issues[self::$recording_level];
        self::$recorded_issues[self::$recording_level] = [];
        return $recorded_issues;
    }
    /**
     * This will try to add issues that has been retrieved through clearRecordingLevel or record them at a lower level
     */
    public static function bubble_up(Code_Issue $e): void
    {
        if (self::$recording_level === 0) {
            self::add($e);
            return;
        }
        self::$recorded_issues[self::$recording_level][] = $e;
    }
    /**
     * @internal
     * @param array<array-key,mixed> $server
     */
    final public static function capture_server(array $server): void
    {
        self::$server = $server;
    }
    /**
     * @internal
     * @return array<array-key,mixed>
     */
    final public static function get_server(): array
    {
        return self::$server;
    }
}