<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Possibly_Null_Reference extends Code_Issue
{
    public const ERROR_LEVEL = 3;
    public const SHORTCODE = 83;
}