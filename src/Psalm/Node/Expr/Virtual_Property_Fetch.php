<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Property_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Property_Fetch extends Property_Fetch implements Virtual_Node
{
}