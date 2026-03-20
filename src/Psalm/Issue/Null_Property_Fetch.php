<?php

declare (strict_types=1);
namespace Psalm\Issue;

/**
 * This is different from NullReference, as PHP throws a notice (vs the possibility of a fatal error with a null
 * reference)
 */
final class Null_Property_Fetch extends Code_Issue
{
    public const ERROR_LEVEL = -1;
    public const SHORTCODE = 27;
}