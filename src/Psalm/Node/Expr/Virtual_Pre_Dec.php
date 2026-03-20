<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Pre_Dec;
use Psalm\Node\Virtual_Node;
final class Virtual_Pre_Dec extends Pre_Dec implements Virtual_Node
{
}