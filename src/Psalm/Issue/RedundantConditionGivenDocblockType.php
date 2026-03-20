<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Redundant_Condition_Given_Docblock_Type extends Code_Issue
{
    public const ERROR_LEVEL = 2;
    public const SHORTCODE = 156;
    public function __construct(string $message, Code_Location $code_location, ?string $dupe_key)
    {
        parent::__construct($message, $code_location);
        $this->dupe_key = $dupe_key;
    }
}