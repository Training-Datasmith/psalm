<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Include_;
use Psalm\Node\Virtual_Node;
final class Virtual_Include extends Include_ implements Virtual_Node
{
}