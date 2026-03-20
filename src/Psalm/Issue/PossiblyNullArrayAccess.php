<?php

declare (strict_types=1);
namespace Psalm\Issue;

/**
 * This is different from PossiblyNullReference, as PHP throws a notice (vs the possibility of a fatal error with a null
 * reference)
 */
final class Possibly_Null_Array_Access extends Code_Issue
{
    public const ERROR_LEVEL = 3;
    public const SHORTCODE = 79;
}