<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Goto_;
use Psalm\Node\Virtual_Node;
final class Virtual_Goto extends Goto_ implements Virtual_Node
{
}