<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Class_Method;
use Psalm\Node\Virtual_Node;
final class Virtual_Class_Method extends Class_Method implements Virtual_Node
{
}