<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use Psalm\Internal\Analyzer\Issue_Data;
use function array_pop;
use function explode;
abstract class Code_Issue
{
    /** @var int */
    public const ERROR_LEVEL = -1;
    /** @var int<0, max> */
    public const SHORTCODE = 0;
    public ?string $dupe_key = null;
    public function __construct(public readonly string $message, public readonly Code_Location $code_location)
    {
    }
    public function get_short_location_with_previous(): string
    {
        $previous_text = '';
        if ($this->code_location->previous_location) {
            $previous_location = $this->code_location->previous_location;
            $previous_text = ' from ' . $previous_location->file_name . ':' . $previous_location->get_line_number();
        }
        return $this->code_location->file_name . ':' . $this->code_location->get_line_number() . $previous_text;
    }
    public function get_short_location(): string
    {
        return $this->code_location->file_name . ':' . $this->code_location->get_line_number();
    }
    public function get_file_path(): string
    {
        return $this->code_location->file_path;
    }
    public static function get_issue_type(): string
    {
        $fqcn_parts = explode('\\', static::class);
        return array_pop($fqcn_parts);
    }
    /**
     * @param IssueData::SEVERITY_* $severity
     */
    public function to_issue_data(string $severity): Issue_Data
    {
        $location = $this->code_location;
        $selection_bounds = $location->get_selection_bounds();
        $snippet_bounds = $location->get_snippet_bounds();
        return new Issue_Data($severity, $location->get_line_number(), $location->get_end_line_number(), static::get_issue_type(), $this->message, $location->file_name, $location->file_path, $location->get_snippet(), $location->get_selected_text(), $selection_bounds[0], $selection_bounds[1], $snippet_bounds[0], $snippet_bounds[1], $location->get_column(), $location->get_end_column(), static::SHORTCODE, static::ERROR_LEVEL, $this instanceof Tainted_Input ? $this->get_taint_trace() : null, $this instanceof Mixed_Issue && ($origin_location = $this->get_original_location()) ? [Tainted_Input::node_to_data_flow_node_data($origin_location, 'The type of ' . $location->get_selected_text() . ' is sourced from here')] : null, $this->dupe_key);
    }
}