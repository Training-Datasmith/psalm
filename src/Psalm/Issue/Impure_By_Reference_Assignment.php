<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Impure_By_Reference_Assignment extends Code_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 220;
}