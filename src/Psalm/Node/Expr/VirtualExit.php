<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Exit_;
use Psalm\Node\Virtual_Node;
final class Virtual_Exit extends Exit_ implements Virtual_Node
{
}