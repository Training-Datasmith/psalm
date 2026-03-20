<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Else_If_;
use Psalm\Node\Virtual_Node;
final class Virtual_Else_If extends Else_If_ implements Virtual_Node
{
}