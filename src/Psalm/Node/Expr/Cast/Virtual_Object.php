<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Cast;

use Php_Parser\Node\Expr\Cast\Object_;
use Psalm\Node\Virtual_Node;
final class Virtual_Object extends Object_ implements Virtual_Node
{
}