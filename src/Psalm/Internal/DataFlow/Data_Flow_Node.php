<?php

declare (strict_types=1);
namespace Psalm\Internal\Data_Flow;

use Override;
use Psalm\Code_Location;
use Stringable;
use function strtolower;
/**
 * @psalm-consistent-constructor
 * @internal
 */
class Data_Flow_Node implements Stringable
{
    public ?string $unspecialized_id = null;
    public ?string $specialization_key = null;
    public ?Data_Flow_Node $previous = null;
    /** @var list<string> */
    public array $path_types = [];
    /**
     * @var array<string, array<string, true>>
     */
    public array $specialized_calls = [];
    /**
     * @param array<string> $taints
     */
    public function __construct(public string $id, public string $label, public ?Code_Location $code_location, ?string $specialization_key = null, public array $taints = [])
    {
        if ($specialization_key) {
            $this->unspecialized_id = $id;
            $this->id .= '-' . $specialization_key;
        }
        $this->specialization_key = $specialization_key;
    }
    /**
     * @return static
     */
    final public static function get_for_method_argument(string $method_id, string $cased_method_id, int $argument_offset, ?Code_Location $arg_location, ?Code_Location $code_location = null): self
    {
        $arg_id = strtolower($method_id) . '#' . ($argument_offset + 1);
        $label = $cased_method_id . '#' . ($argument_offset + 1);
        $specialization_key = null;
        if ($code_location) {
            $specialization_key = strtolower($code_location->file_name) . ':' . $code_location->raw_file_start;
        }
        return new static($arg_id, $label, $arg_location, $specialization_key);
    }
    /**
     * @return static
     */
    final public static function get_for_assignment(string $var_id, Code_Location $assignment_location, ?string $specialization_key = null): self
    {
        $id = $var_id . '-' . $assignment_location->file_name . ':' . $assignment_location->raw_file_start . '-' . $assignment_location->raw_file_end;
        return new static($id, $var_id, $assignment_location, $specialization_key);
    }
    /**
     * @return static
     */
    final public static function get_for_method_return(string $method_id, string $cased_method_id, ?Code_Location $code_location, ?Code_Location $function_location = null): self
    {
        $specialization_key = null;
        if ($function_location) {
            $specialization_key = strtolower($function_location->file_name) . ':' . $function_location->raw_file_start;
        }
        return new static(strtolower($method_id), $cased_method_id, $code_location, $specialization_key);
    }
    #[Override]
    public function __toString(): string
    {
        return $this->id;
    }
}