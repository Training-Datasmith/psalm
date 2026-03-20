<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Arrow_Function;
use Psalm\Node\Virtual_Node;
final class Virtual_Arrow_Function extends Arrow_Function implements Virtual_Node
{
}