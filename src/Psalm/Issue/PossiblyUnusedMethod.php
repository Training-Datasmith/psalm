<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
use function strtolower;
final class Possibly_Unused_Method extends Method_Issue
{
    public const ERROR_LEVEL = -2;
    public const SHORTCODE = 87;
    public function __construct(string $message, Code_Location $code_location, string $method_id)
    {
        parent::__construct($message, $code_location, $method_id);
        $this->dupe_key = strtolower($method_id);
    }
}