<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Undefined_Magic_Method extends Method_Issue
{
    public const ERROR_LEVEL = 4;
    public const SHORTCODE = 219;
}