<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use function strtolower;
abstract class Variable_Issue extends Code_Issue
{
    public string $var_name;
    public function __construct(string $message, Code_Location $code_location, string $var_name)
    {
        parent::__construct($message, $code_location);
        $this->var_name = strtolower($var_name);
    }
}