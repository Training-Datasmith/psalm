<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Shift_Right;
use Psalm\Node\Virtual_Node;
final class Virtual_Shift_Right extends Shift_Right implements Virtual_Node
{
}