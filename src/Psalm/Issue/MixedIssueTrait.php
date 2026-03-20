<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Override;
use Psalm\Code_Location;
trait Mixed_Issue_Trait
{
    /**
     * @readonly
     */
    public ?Code_Location $origin_location = null;
    public function __construct(string $message, Code_Location $code_location, ?Code_Location $origin_location = null)
    {
        parent::__construct($message, $code_location);
        $this->origin_location = $origin_location;
    }
    #[Override]
    public function get_mixed_origin_message(): string
    {
        return $this->message . ($this->origin_location ? '. Consider improving the type at ' . $this->origin_location->get_short_summary() : '');
    }
    #[Override]
    public function get_original_location(): ?Code_Location
    {
        return $this->origin_location;
    }
}