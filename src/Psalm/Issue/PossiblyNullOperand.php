<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Possibly_Null_Operand extends Code_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 80;
}