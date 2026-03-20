<?php

declare (strict_types=1);
namespace Psalm\Issue;

use Psalm\Code_Location;
interface Mixed_Issue
{
    public function get_mixed_origin_message(): string;
    public function get_original_location(): ?Code_Location;
}