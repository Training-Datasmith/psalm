<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Nullsafe_Property_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Nullsafe_Property_Fetch extends Nullsafe_Property_Fetch implements Virtual_Node
{
}