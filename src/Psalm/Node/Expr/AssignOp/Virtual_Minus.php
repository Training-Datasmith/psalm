<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Assign_Op;

use Php_Parser\Node\Expr\Assign_Op\Minus;
use Psalm\Node\Virtual_Node;
final class Virtual_Minus extends Minus implements Virtual_Node
{
}