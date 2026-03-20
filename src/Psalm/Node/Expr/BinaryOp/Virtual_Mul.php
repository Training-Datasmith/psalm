<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Mul;
use Psalm\Node\Virtual_Node;
final class Virtual_Mul extends Mul implements Virtual_Node
{
}