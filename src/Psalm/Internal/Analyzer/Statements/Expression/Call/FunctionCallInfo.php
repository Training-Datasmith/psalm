<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Function_Call_Info
{
    public ?string $function_id = null;
    public ?bool $function_exists = null;
    public bool $is_stubbed = false;
    public bool $in_call_map = false;
    /**
     * @var array<string, Union>
     */
    public array $defined_constants = [];
    /**
     * @var array<string, bool>
     */
    public array $global_variables = [];
    /**
     * @var ?array<int, FunctionLikeParameter>
     */
    public ?array $function_params = null;
    public ?Function_Like_Storage $function_storage = null;
    public ?Php_Parser\Node\Name $new_function_name = null;
    public bool $allow_named_args = true;
    public array $byref_uses = [];
    /**
     * @mutation-free
     */
    public function has_by_reference_parameters(): bool
    {
        if (null === $this->function_params) {
            return false;
        }
        foreach ($this->function_params as $value) {
            if ($value->by_ref) {
                return true;
            }
        }
        return false;
    }
}