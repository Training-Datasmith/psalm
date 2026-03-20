<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Cast;

use Php_Parser\Node\Expr\Cast\Array_;
use Psalm\Node\Virtual_Node;
final class Virtual_Array extends Array_ implements Virtual_Node
{
}