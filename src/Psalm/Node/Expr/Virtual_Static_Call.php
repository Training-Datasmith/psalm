<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Static_Call;
use Psalm\Node\Virtual_Node;
final class Virtual_Static_Call extends Static_Call implements Virtual_Node
{
}