<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Error;
use Psalm\Node\Virtual_Node;
/**
 * Error node used during parsing with error recovery.
 *
 * An error node may be placed at a position where an expression is required, but an error occurred.
 * Error nodes will not be present if the parser is run in throwOnError mode (the default).
 */
final class Virtual_Error extends Error implements Virtual_Node
{
}