<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Unsupported_Property_Reference_Usage extends Code_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 321;
    public function __construct(Code_Location $code_location)
    {
        parent::__construct('This reference cannot be analyzed by Psalm.', $code_location);
    }
}