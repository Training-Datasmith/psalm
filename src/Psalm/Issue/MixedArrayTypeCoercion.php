<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Mixed_Array_Type_Coercion extends Code_Issue implements Mixed_Issue
{
    public const ERROR_LEVEL = 1;
    public const SHORTCODE = 195;
    use Mixed_Issue_Trait;
}