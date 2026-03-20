<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Deprecated_Method extends Method_Issue
{
    public const ERROR_LEVEL = 2;
    public const SHORTCODE = 1;
}