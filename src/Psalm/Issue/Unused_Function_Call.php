<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Unused_Function_Call extends Function_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 206;
}