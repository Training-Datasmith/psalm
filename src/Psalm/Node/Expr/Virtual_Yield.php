<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Yield_;
use Psalm\Node\Virtual_Node;
final class Virtual_Yield extends Yield_ implements Virtual_Node
{
}