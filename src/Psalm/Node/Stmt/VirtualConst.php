<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Const_;
use Psalm\Node\Virtual_Node;
final class Virtual_Const extends Const_ implements Virtual_Node
{
}