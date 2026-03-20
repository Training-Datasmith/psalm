<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Boolean_Or;
use Psalm\Node\Virtual_Node;
final class Virtual_Boolean_Or extends Boolean_Or implements Virtual_Node
{
}