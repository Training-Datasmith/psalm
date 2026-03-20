<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Undefined_Property_Assignment extends Property_Issue
{
    public const ERROR_LEVEL = 6;
    public const SHORTCODE = 38;
}