<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use function strtolower;
abstract class Method_Issue extends Code_Issue
{
    public string $method_id;
    public function __construct(string $message, Code_Location $code_location, string $method_id)
    {
        parent::__construct($message, $code_location);
        $this->method_id = strtolower($method_id);
    }
}