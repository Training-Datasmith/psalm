<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Assign_Op;

use Php_Parser\Node\Expr\Assign_Op\Div;
use Psalm\Node\Virtual_Node;
final class Virtual_Div extends Div implements Virtual_Node
{
}