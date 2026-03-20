<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Echo_;
use Psalm\Node\Virtual_Node;
final class Virtual_Echo extends Echo_ implements Virtual_Node
{
}