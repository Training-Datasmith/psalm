<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\For_;
use Psalm\Node\Virtual_Node;
final class Virtual_For extends For_ implements Virtual_Node
{
}