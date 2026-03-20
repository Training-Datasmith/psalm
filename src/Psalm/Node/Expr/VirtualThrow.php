<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Throw_;
use Psalm\Node\Virtual_Node;
final class Virtual_Throw extends Throw_ implements Virtual_Node
{
}