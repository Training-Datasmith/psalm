<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Namespace_;
use Psalm\Node\Virtual_Node;
final class Virtual_Namespace extends Namespace_ implements Virtual_Node
{
}