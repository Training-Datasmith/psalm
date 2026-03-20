<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Const_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Const_Fetch extends Const_Fetch implements Virtual_Node
{
}