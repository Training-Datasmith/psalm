<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Halt_Compiler;
use Psalm\Node\Virtual_Node;
final class Virtual_Halt_Compiler extends Halt_Compiler implements Virtual_Node
{
}