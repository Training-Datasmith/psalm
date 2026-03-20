<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\If_;
use Psalm\Node\Virtual_Node;
final class Virtual_If extends If_ implements Virtual_Node
{
}