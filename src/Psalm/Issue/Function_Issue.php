<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use function strtolower;
abstract class Function_Issue extends Code_Issue
{
    public string $function_id;
    public function __construct(string $message, Code_Location $code_location, string $function_id)
    {
        parent::__construct($message, $code_location);
        $this->function_id = strtolower($function_id);
    }
}