<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Property_Not_Set_In_Constructor extends Property_Issue
{
    public const ERROR_LEVEL = 2;
    public const SHORTCODE = 74;
    public function __construct(string $message, Code_Location $code_location, string $property_id)
    {
        parent::__construct($message, $code_location, $property_id);
        $this->dupe_key = $property_id;
    }
}