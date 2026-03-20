<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Array_;
use Psalm\Node\Virtual_Node;
final class Virtual_Array extends Array_ implements Virtual_Node
{
}