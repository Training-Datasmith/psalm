<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Pre_Inc;
use Psalm\Node\Virtual_Node;
final class Virtual_Pre_Inc extends Pre_Inc implements Virtual_Node
{
}