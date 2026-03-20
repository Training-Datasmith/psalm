<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Undefined_Interface_Method extends Method_Issue
{
    public const ERROR_LEVEL = 5;
    public const SHORTCODE = 181;
}