<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Unary_Minus;
use Psalm\Node\Virtual_Node;
final class Virtual_Unary_Minus extends Unary_Minus implements Virtual_Node
{
}