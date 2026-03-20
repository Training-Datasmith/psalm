<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use function strtolower;
final class Mixed_Argument_Type_Coercion extends Argument_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 194;
    use Mixed_Issue_Trait;
    public function __construct(string $message, Code_Location $code_location, ?string $function_id = null, ?Code_Location $origin_location = null)
    {
        parent::__construct($message, $code_location);
        $this->function_id = $function_id ? strtolower($function_id) : null;
        $this->origin_location = $origin_location;
    }
}