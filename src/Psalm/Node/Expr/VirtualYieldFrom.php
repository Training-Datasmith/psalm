<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Yield_From;
use Psalm\Node\Virtual_Node;
final class Virtual_Yield_From extends Yield_From implements Virtual_Node
{
}