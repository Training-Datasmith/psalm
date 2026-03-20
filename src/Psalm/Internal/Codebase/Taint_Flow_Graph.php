<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Override;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Issue\Tainted_Callable;
use Psalm\Issue\Tainted_Cookie;
use Psalm\Issue\Tainted_Custom;
use Psalm\Issue\Tainted_Eval;
use Psalm\Issue\Tainted_Extract;
use Psalm\Issue\Tainted_File;
use Psalm\Issue\Tainted_Header;
use Psalm\Issue\Tainted_Html;
use Psalm\Issue\Tainted_Include;
use Psalm\Issue\Tainted_Ldap;
use Psalm\Issue\Tainted_Ssrf;
use Psalm\Issue\Tainted_Shell;
use Psalm\Issue\Tainted_Sleep;
use Psalm\Issue\Tainted_Sql;
use Psalm\Issue\Tainted_System_Secret;
use Psalm\Issue\Tainted_Text_With_Quotes;
use Psalm\Issue\Tainted_Unserialize;
use Psalm\Issue\Tainted_User_Secret;
use Psalm\Issue\Tainted_Xpath;
use Psalm\Issue_Buffer;
use Psalm\Type\Taint_Kind;
use function array_diff;
use function array_filter;
use function array_intersect;
use function array_merge;
use function array_unique;
use function array_unshift;
use function count;
use function end;
use function implode;
use function json_encode;
use function ksort;
use function sort;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;
/**
 * @internal
 */
final class Taint_Flow_Graph extends Data_Flow_Graph
{
    /** @var array<string, TaintSource> */
    private array $sources = [];
    /** @var array<string, DataFlowNode> */
    private array $nodes = [];
    /** @var array<string, TaintSink> */
    private array $sinks = [];
    /** @var array<string, array<string, true>> */
    private array $specialized_calls = [];
    /** @var array<string, array<string, true>> */
    private array $specializations = [];
    #[Override]
    public function add_node(Data_Flow_Node $node): void
    {
        $this->nodes[$node->id] = $node;
        if ($node->unspecialized_id && $node->specialization_key) {
            $this->specialized_calls[$node->specialization_key][$node->unspecialized_id] = true;
            $this->specializations[$node->unspecialized_id][$node->specialization_key] = true;
        }
    }
    public function add_source(Taint_Source $node): void
    {
        $this->sources[$node->id] = $node;
    }
    public function add_sink(Taint_Sink $node): void
    {
        $this->sinks[$node->id] = $node;
        // in the rare case the sink is the _next_ node, this is necessary
        $this->nodes[$node->id] = $node;
    }
    public function add_graph(self $taint): void
    {
        $this->sources += $taint->sources;
        $this->sinks += $taint->sinks;
        $this->nodes += $taint->nodes;
        $this->specialized_calls += $taint->specialized_calls;
        foreach ($taint->forward_edges as $key => $map) {
            if (!isset($this->forward_edges[$key])) {
                $this->forward_edges[$key] = $map;
            } else {
                $this->forward_edges[$key] += $map;
            }
        }
        foreach ($taint->specializations as $key => $map) {
            if (!isset($this->specializations[$key])) {
                $this->specializations[$key] = $map;
            } else {
                $this->specializations[$key] += $map;
            }
        }
    }
    public function get_predecessor_path(Data_Flow_Node $source): string
    {
        $location_summary = '';
        if ($source->code_location) {
            $location_summary = $source->code_location->get_short_summary();
        }
        $source_descriptor = $source->label . ($location_summary ? ' (' . $location_summary . ')' : '');
        $previous_source = $source->previous;
        if ($previous_source) {
            if ($previous_source === $source) {
                return '';
            }
            if ($source->code_location && $previous_source->code_location && $previous_source->code_location->get_hash() === $source->code_location->get_hash() && $previous_source->previous) {
                return $this->get_predecessor_path($previous_source->previous) . ' -> ' . $source_descriptor;
            }
            return $this->get_predecessor_path($previous_source) . ' -> ' . $source_descriptor;
        }
        return $source_descriptor;
    }
    public function get_successor_path(Data_Flow_Node $sink): string
    {
        $location_summary = '';
        if ($sink->code_location) {
            $location_summary = $sink->code_location->get_short_summary();
        }
        $sink_descriptor = $sink->label . ($location_summary ? ' (' . $location_summary . ')' : '');
        $next_sink = $sink->previous;
        if ($next_sink) {
            if ($next_sink === $sink) {
                return '';
            }
            if ($sink->code_location && $next_sink->code_location && $next_sink->code_location->get_hash() === $sink->code_location->get_hash() && $next_sink->previous) {
                return $sink_descriptor . ' -> ' . $this->get_successor_path($next_sink->previous);
            }
            return $sink_descriptor . ' -> ' . $this->get_successor_path($next_sink);
        }
        return $sink_descriptor;
    }
    /**
     * @return list<array{location: ?CodeLocation, label: string, entry_path_type: string}>
     */
    public function get_issue_trace(Data_Flow_Node $source): array
    {
        $out = [];
        do {
            /** @var DataFlowNode $source */
            $previous_source = $source->previous;
            if ($previous_source === $source) {
                break;
            }
            $path_types = $source->path_types;
            array_unshift($out, ['location' => $source->code_location, 'label' => $source->label, 'entry_path_type' => end($path_types) ?: '']);
            $source = $previous_source;
        } while ($previous_source);
        return $out;
    }
    public function connect_sinks_and_sources(): void
    {
        $visited_source_ids = [];
        $sources = $this->sources;
        $sinks = $this->sinks;
        ksort($this->specializations);
        ksort($this->forward_edges);
        // reprocess resolved descendants up to a maximum nesting level of 40
        for ($i = 0; count($sinks) && count($sources) && $i < 40; $i++) {
            $new_sources = [];
            ksort($sources);
            foreach ($sources as $source) {
                $source_taints = $source->taints;
                sort($source_taints);
                $visited_source_ids[$source->id][implode(',', $source_taints)] = true;
                $generated_sources = $this->get_specialized_sources($source);
                foreach ($generated_sources as $generated_source) {
                    $new_sources = [...$new_sources, ...$this->get_child_nodes($generated_source, $source_taints, $sinks, $visited_source_ids)];
                }
            }
            $sources = $new_sources;
        }
    }
    /**
     * @param array<string> $source_taints
     * @param array<DataFlowNode> $sinks
     * @return array<string, DataFlowNode>
     */
    private function get_child_nodes(Data_Flow_Node $generated_source, array $source_taints, array $sinks, array $visited_source_ids): array
    {
        $new_sources = [];
        $config = Config::get_instance();
        $project_analyzer = Project_Analyzer::get_instance();
        foreach ($this->forward_edges[$generated_source->id] as $to_id => $path) {
            $path_type = $path->type;
            $added_taints = $path->unescaped_taints ?: [];
            $removed_taints = $path->escaped_taints ?: [];
            if (!isset($this->nodes[$to_id])) {
                continue;
            }
            $destination_node = $this->nodes[$to_id];
            $new_taints = array_unique(array_diff(array_merge($source_taints, $added_taints), $removed_taints));
            sort($new_taints);
            if (isset($visited_source_ids[$to_id][implode(',', $new_taints)])) {
                continue;
            }
            if (self::should_ignore_fetch($path_type, 'arraykey', $generated_source->path_types)) {
                continue;
            }
            if (self::should_ignore_fetch($path_type, 'arrayvalue', $generated_source->path_types)) {
                continue;
            }
            if (self::should_ignore_fetch($path_type, 'property', $generated_source->path_types)) {
                continue;
            }
            if ($generated_source->code_location && $project_analyzer->can_report_issues($generated_source->code_location->file_path) && !$config->report_issue_in_file('TaintedInput', $generated_source->code_location->file_path)) {
                continue;
            }
            if (isset($sinks[$to_id])) {
                $matching_taints = array_intersect($sinks[$to_id]->taints, $new_taints);
                if ($matching_taints && $generated_source->code_location) {
                    if ($sinks[$to_id]->code_location && $config->report_issue_in_file('TaintedInput', $sinks[$to_id]->code_location->file_path)) {
                        $issue_location = $sinks[$to_id]->code_location;
                    } else {
                        $issue_location = $generated_source->code_location;
                    }
                    $issue_trace = $this->get_issue_trace($generated_source);
                    $path = $this->get_predecessor_path($generated_source) . ' -> ' . $this->get_successor_path($sinks[$to_id]);
                    foreach ($matching_taints as $matching_taint) {
                        $issue = match ($matching_taint) {
                            Taint_Kind::INPUT_CALLABLE => new Tainted_Callable('Detected tainted text', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_UNSERIALIZE => new Tainted_Unserialize('Detected tainted code passed to unserialize or similar', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_INCLUDE => new Tainted_Include('Detected tainted code passed to include or similar', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_EVAL => new Tainted_Eval('Detected tainted code passed to eval or similar', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_SQL => new Tainted_Sql('Detected tainted SQL', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_HTML => new Tainted_Html('Detected tainted HTML', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_HAS_QUOTES => new Tainted_Text_With_Quotes('Detected tainted text with possible quotes', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_SHELL => new Tainted_Shell('Detected tainted shell code', $issue_location, $issue_trace, $path),
                            Taint_Kind::USER_SECRET => new Tainted_User_Secret('Detected tainted user secret leaking', $issue_location, $issue_trace, $path),
                            Taint_Kind::SYSTEM_SECRET => new Tainted_System_Secret('Detected tainted system secret leaking', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_SSRF => new Tainted_Ssrf('Detected tainted network request', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_LDAP => new Tainted_Ldap('Detected tainted LDAP request', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_COOKIE => new Tainted_Cookie('Detected tainted cookie', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_FILE => new Tainted_File('Detected tainted file handling', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_HEADER => new Tainted_Header('Detected tainted header', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_XPATH => new Tainted_Xpath('Detected tainted xpath query', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_SLEEP => new Tainted_Sleep('Detected tainted sleep', $issue_location, $issue_trace, $path),
                            Taint_Kind::INPUT_EXTRACT => new Tainted_Extract('Detected tainted extract', $issue_location, $issue_trace, $path),
                            default => new Tainted_Custom('Detected tainted ' . $matching_taint, $issue_location, $issue_trace, $path),
                        };
                        Issue_Buffer::maybe_add($issue);
                    }
                }
            }
            $new_destination = clone $destination_node;
            $new_destination->previous = $generated_source;
            $new_destination->taints = $new_taints;
            $new_destination->specialized_calls = $generated_source->specialized_calls;
            $new_destination->path_types = $generated_source->path_types;
            $new_destination->path_types[] = $path_type;
            $key = $to_id . ' ' . json_encode($new_destination->specialized_calls, JSON_THROW_ON_ERROR) . ' ' . json_encode($new_destination->taints, JSON_THROW_ON_ERROR);
            $new_sources[$key] = $new_destination;
        }
        return $new_sources;
    }
    /** @return array<int, DataFlowNode> */
    private function get_specialized_sources(Data_Flow_Node $source): array
    {
        $generated_sources = [];
        if (isset($this->forward_edges[$source->id])) {
            return [$source];
        }
        if ($source->specialization_key && isset($this->specialized_calls[$source->specialization_key])) {
            $generated_source = clone $source;
            $generated_source->id = substr($source->id, 0, -strlen($source->specialization_key) - 1);
            $generated_source->specialized_calls[$source->specialization_key][$generated_source->id] = true;
            $generated_sources[] = $generated_source;
        } elseif (isset($this->specializations[$source->id])) {
            foreach ($this->specializations[$source->id] as $specialization => $_) {
                if (!$source->specialized_calls || isset($source->specialized_calls[$specialization])) {
                    $new_source = clone $source;
                    $new_source->id = $source->id . '-' . $specialization;
                    unset($new_source->specialized_calls[$specialization]);
                    $generated_sources[] = $new_source;
                }
            }
        } else {
            foreach ($source->specialized_calls as $key => $map) {
                if (isset($map[$source->id]) && isset($this->forward_edges[$source->id . '-' . $key])) {
                    $new_source = clone $source;
                    $new_source->id = $source->id . '-' . $key;
                    $generated_sources[] = $new_source;
                }
            }
        }
        return array_filter($generated_sources, $this->does_forward_edge_exist(...));
    }
    private function does_forward_edge_exist(Data_Flow_Node $new_source): bool
    {
        return isset($this->forward_edges[$new_source->id]);
    }
}