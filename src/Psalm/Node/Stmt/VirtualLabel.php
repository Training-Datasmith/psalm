<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Label;
use Psalm\Node\Virtual_Node;
final class Virtual_Label extends Label implements Virtual_Node
{
}