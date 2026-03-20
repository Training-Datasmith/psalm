<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Finally_;
use Psalm\Node\Virtual_Node;
final class Virtual_Finally extends Finally_ implements Virtual_Node
{
}