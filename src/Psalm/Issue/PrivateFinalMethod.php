<?php

declare (strict_types=1);
namespace Psalm\Issue;

final class Private_Final_Method extends Method_Issue
{
    final public const ERROR_LEVEL = 2;
    final public const SHORTCODE = 320;
}