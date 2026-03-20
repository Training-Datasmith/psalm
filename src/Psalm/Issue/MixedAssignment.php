<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Mixed_Assignment extends Code_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 32;
    use Mixed_Issue_Trait;
}