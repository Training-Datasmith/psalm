<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Variable;
use Psalm\Node\Virtual_Node;
final class Virtual_Variable extends Variable implements Virtual_Node
{
}