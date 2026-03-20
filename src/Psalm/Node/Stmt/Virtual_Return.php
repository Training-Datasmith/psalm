<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Return_;
use Psalm\Node\Virtual_Node;
final class Virtual_Return extends Return_ implements Virtual_Node
{
}