<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Match_;
use Psalm\Node\Virtual_Node;
final class Virtual_Match extends Match_ implements Virtual_Node
{
}