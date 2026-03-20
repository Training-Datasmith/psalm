<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Function_;
use Psalm\Node\Virtual_Node;
final class Virtual_Function extends Function_ implements Virtual_Node
{
}