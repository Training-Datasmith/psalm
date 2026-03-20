<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Possibly_Unused_Property extends Property_Issue
{
    public const ERROR_LEVEL = -2;
    public const SHORTCODE = 149;
    public function __construct(string $message, Code_Location $code_location, string $property_id)
    {
        parent::__construct($message, $code_location, $property_id);
        $this->dupe_key = $property_id;
    }
}