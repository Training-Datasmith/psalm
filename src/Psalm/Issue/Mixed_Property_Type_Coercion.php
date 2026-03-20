<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
final class Mixed_Property_Type_Coercion extends Property_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 196;
    use Mixed_Issue_Trait;
    public function __construct(string $message, Code_Location $code_location, string $property_id, ?Code_Location $origin_location = null)
    {
        parent::__construct($message, $code_location, $property_id);
        $this->origin_location = $origin_location;
    }
}