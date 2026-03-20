<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Break_;
use Psalm\Node\Virtual_Node;
final class Virtual_Break extends Break_ implements Virtual_Node
{
}