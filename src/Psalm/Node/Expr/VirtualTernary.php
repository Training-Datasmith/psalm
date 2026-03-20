<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Ternary;
use Psalm\Node\Virtual_Node;
final class Virtual_Ternary extends Ternary implements Virtual_Node
{
}