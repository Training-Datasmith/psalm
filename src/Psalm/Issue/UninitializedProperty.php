<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Uninitialized_Property extends Property_Issue
{
    public const ERROR_LEVEL = 7;
    public const SHORTCODE = 186;
}