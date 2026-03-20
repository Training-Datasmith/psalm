<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Expression;
use Psalm\Node\Virtual_Node;
/**
 * Represents statements of type "expr;"
 */
final class Virtual_Expression extends Expression implements Virtual_Node
{
}