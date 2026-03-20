<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Assign_Op;

use Php_Parser\Node\Expr\Assign_Op\Bitwise_Xor;
use Psalm\Node\Virtual_Node;
final class Virtual_Bitwise_Xor extends Bitwise_Xor implements Virtual_Node
{
}