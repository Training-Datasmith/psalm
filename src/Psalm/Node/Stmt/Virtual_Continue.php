<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Continue_;
use Psalm\Node\Virtual_Node;
final class Virtual_Continue extends Continue_ implements Virtual_Node
{
}