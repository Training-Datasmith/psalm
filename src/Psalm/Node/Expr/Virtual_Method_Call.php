<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Method_Call;
use Psalm\Node\Virtual_Node;
final class Virtual_Method_Call extends Method_Call implements Virtual_Node
{
}