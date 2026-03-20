<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Class_;
use Psalm\Node\Virtual_Node;
final class Virtual_Class extends Class_ implements Virtual_Node
{
}