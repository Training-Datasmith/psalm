<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Unused_Foreach_Value extends Code_Issue
{
    public const ERROR_LEVEL = -2;
    public const SHORTCODE = 275;
}