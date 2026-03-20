<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Uncaught_Throw_In_Global_Scope extends Code_Issue
{
    public const ERROR_LEVEL = -2;
    public const SHORTCODE = 191;
}