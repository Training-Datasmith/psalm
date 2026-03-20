<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Deprecated_Function extends Function_Issue
{
    public const ERROR_LEVEL = 2;
    public const SHORTCODE = 201;
}