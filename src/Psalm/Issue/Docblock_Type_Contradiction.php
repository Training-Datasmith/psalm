<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Docblock_Type_Contradiction extends Code_Issue
{
    public const ERROR_LEVEL = 2;
    public const SHORTCODE = 155;
    public function __construct(string $message, Code_Location $code_location, ?string $dupe_key)
    {
        parent::__construct($message, $code_location);
        $this->dupe_key = $dupe_key;
    }
}