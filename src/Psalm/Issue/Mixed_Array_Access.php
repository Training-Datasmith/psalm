<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Mixed_Array_Access extends Code_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 51;
    use Mixed_Issue_Trait;
}