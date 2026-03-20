<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Boolean_Not;
use Psalm\Node\Virtual_Node;
final class Virtual_Boolean_Not extends Boolean_Not implements Virtual_Node
{
}