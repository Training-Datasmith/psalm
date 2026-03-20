<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Class_Const_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Class_Const_Fetch extends Class_Const_Fetch implements Virtual_Node
{
}