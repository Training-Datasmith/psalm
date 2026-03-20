<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Logical_Xor;
use Psalm\Node\Virtual_Node;
final class Virtual_Logical_Xor extends Logical_Xor implements Virtual_Node
{
}