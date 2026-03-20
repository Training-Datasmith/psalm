<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Switch_;
use Psalm\Node\Virtual_Node;
final class Virtual_Switch extends Switch_ implements Virtual_Node
{
}