<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Eval_;
use Psalm\Node\Virtual_Node;
final class Virtual_Eval extends Eval_ implements Virtual_Node
{
}