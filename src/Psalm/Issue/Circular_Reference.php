<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Circular_Reference extends Code_Issue
{
    public const ERROR_LEVEL = 7;
    public const SHORTCODE = 131;
}