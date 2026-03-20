<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Assign;
use Psalm\Node\Virtual_Node;
final class Virtual_Assign extends Assign implements Virtual_Node
{
}