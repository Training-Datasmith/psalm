<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Else_;
use Psalm\Node\Virtual_Node;
final class Virtual_Else extends Else_ implements Virtual_Node
{
}