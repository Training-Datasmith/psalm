<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Possibly_Undefined_Global_Variable extends Variable_Issue
{
    public const ERROR_LEVEL = 3;
    public const SHORTCODE = 126;
}