<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Empty_;
use Psalm\Node\Virtual_Node;
final class Virtual_Empty extends Empty_ implements Virtual_Node
{
}