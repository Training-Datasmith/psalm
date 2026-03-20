<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Smaller_Or_Equal;
use Psalm\Node\Virtual_Node;
final class Virtual_Smaller_Or_Equal extends Smaller_Or_Equal implements Virtual_Node
{
}