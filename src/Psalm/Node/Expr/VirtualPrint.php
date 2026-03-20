<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Print_;
use Psalm\Node\Virtual_Node;
final class Virtual_Print extends Print_ implements Virtual_Node
{
}