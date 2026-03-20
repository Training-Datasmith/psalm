<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Assign_Op;

use Php_Parser\Node\Expr\Assign_Op\Shift_Left;
use Psalm\Node\Virtual_Node;
final class Virtual_Shift_Left extends Shift_Left implements Virtual_Node
{
}