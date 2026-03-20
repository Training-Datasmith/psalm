<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Global_;
use Psalm\Node\Virtual_Node;
final class Virtual_Global extends Global_ implements Virtual_Node
{
}