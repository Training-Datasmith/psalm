<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Error_Suppress;
use Psalm\Node\Virtual_Node;
final class Virtual_Error_Suppress extends Error_Suppress implements Virtual_Node
{
}