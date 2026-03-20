<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\List_;
use Psalm\Node\Virtual_Node;
final class Virtual_List extends List_ implements Virtual_Node
{
}