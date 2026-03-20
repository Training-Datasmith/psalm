<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Func_Call;
use Psalm\Node\Virtual_Node;
final class Virtual_Func_Call extends Func_Call implements Virtual_Node
{
}