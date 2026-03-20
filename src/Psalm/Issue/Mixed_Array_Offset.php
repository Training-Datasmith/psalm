<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Mixed_Array_Offset extends Code_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 31;
    use Mixed_Issue_Trait;
}