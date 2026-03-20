<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Foreach_;
use Psalm\Node\Virtual_Node;
final class Virtual_Foreach extends Foreach_ implements Virtual_Node
{
}