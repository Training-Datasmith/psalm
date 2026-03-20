<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Interface_;
use Psalm\Node\Virtual_Node;
final class Virtual_Interface extends Interface_ implements Virtual_Node
{
}