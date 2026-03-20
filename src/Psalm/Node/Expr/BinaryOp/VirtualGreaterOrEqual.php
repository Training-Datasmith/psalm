<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Greater_Or_Equal;
use Psalm\Node\Virtual_Node;
final class Virtual_Greater_Or_Equal extends Greater_Or_Equal implements Virtual_Node
{
}