<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Closure;
use Psalm\Node\Virtual_Node;
final class Virtual_Closure extends Closure implements Virtual_Node
{
}