<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Implicit_To_String_Cast extends Code_Issue
{
    public const ERROR_LEVEL = 4;
    public const SHORTCODE = 60;
}