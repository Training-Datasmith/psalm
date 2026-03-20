<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Static_Property_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Static_Property_Fetch extends Static_Property_Fetch implements Virtual_Node
{
}