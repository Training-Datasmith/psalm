<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Nullable_Return_Statement extends Code_Issue
{
    public const ERROR_LEVEL = 5;
    public const SHORTCODE = 139;
}