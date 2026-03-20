<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Declare_;
use Psalm\Node\Virtual_Node;
final class Virtual_Declare extends Declare_ implements Virtual_Node
{
}