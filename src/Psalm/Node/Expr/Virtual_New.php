<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\New_;
use Psalm\Node\Virtual_Node;
final class Virtual_New extends New_ implements Virtual_Node
{
}