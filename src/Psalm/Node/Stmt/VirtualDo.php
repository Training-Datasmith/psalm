<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Do_;
use Psalm\Node\Virtual_Node;
final class Virtual_Do extends Do_ implements Virtual_Node
{
}