<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Undefined_Global_Variable extends Variable_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 127;
}