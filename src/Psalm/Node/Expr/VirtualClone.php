<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Clone_;
use Psalm\Node\Virtual_Node;
final class Virtual_Clone extends Clone_ implements Virtual_Node
{
}