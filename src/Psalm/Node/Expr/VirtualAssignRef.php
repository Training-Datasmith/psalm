<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Assign_Ref;
use Psalm\Node\Virtual_Node;
final class Virtual_Assign_Ref extends Assign_Ref implements Virtual_Node
{
}