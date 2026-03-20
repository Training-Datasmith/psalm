<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Nullsafe_Method_Call;
use Psalm\Node\Virtual_Node;
final class Virtual_Nullsafe_Method_Call extends Nullsafe_Method_Call implements Virtual_Node
{
}