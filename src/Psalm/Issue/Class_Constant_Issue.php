<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
abstract class Class_Constant_Issue extends Code_Issue
{
    public function __construct(string $message, Code_Location $code_location, public string $const_id)
    {
        parent::__construct($message, $code_location);
    }
}