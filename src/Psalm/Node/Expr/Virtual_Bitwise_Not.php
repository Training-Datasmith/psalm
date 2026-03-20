<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Bitwise_Not;
use Psalm\Node\Virtual_Node;
final class Virtual_Bitwise_Not extends Bitwise_Not implements Virtual_Node
{
}