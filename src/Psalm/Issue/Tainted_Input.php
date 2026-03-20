<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use Psalm\Internal\Analyzer\Data_Flow_Node_Data;
abstract class Tainted_Input extends Code_Issue
{
    public const ERROR_LEVEL = -2;
    /** @var int<0, max> */
    public const SHORTCODE = 205;
    /**
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     */
    public function __construct(string $message, Code_Location $code_location, public readonly array $journey, public readonly string $journey_text)
    {
        parent::__construct($message, $code_location);
    }
    /**
     * @return list<DataFlowNodeData|array{label: string, entry_path_type: string}>
     */
    public function get_taint_trace(): array
    {
        $nodes = [];
        foreach ($this->journey as ['location' => $location, 'label' => $label, 'entry_path_type' => $path_type]) {
            if ($location) {
                $nodes[] = self::node_to_data_flow_node_data($location, $label);
            } else {
                $nodes[] = ['label' => $label, 'entry_path_type' => $path_type];
            }
        }
        return $nodes;
    }
    public static function node_to_data_flow_node_data(Code_Location $location, string $label): Data_Flow_Node_Data
    {
        $selection_bounds = $location->get_selection_bounds();
        $snippet_bounds = $location->get_snippet_bounds();
        return new Data_Flow_Node_Data($label, $location->get_line_number(), $location->get_end_line_number(), $location->file_name, $location->file_path, $location->get_snippet(), $selection_bounds[0], $selection_bounds[1], $snippet_bounds[0], $location->get_column(), $location->get_end_column());
    }
    public function get_journey_message(): string
    {
        return $this->message . ' in path: ' . $this->journey_text;
    }
}