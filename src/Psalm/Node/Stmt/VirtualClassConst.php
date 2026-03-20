<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Class_Const;
use Psalm\Node\Virtual_Node;
final class Virtual_Class_Const extends Class_Const implements Virtual_Node
{
}