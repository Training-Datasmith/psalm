<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Shell_Exec;
use Psalm\Node\Virtual_Node;
final class Virtual_Shell_Exec extends Shell_Exec implements Virtual_Node
{
}