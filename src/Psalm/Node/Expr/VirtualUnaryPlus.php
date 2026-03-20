<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Unary_Plus;
use Psalm\Node\Virtual_Node;
final class Virtual_Unary_Plus extends Unary_Plus implements Virtual_Node
{
}