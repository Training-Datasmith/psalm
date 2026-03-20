<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Unimplemented_Abstract_Method extends Code_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 101;
}