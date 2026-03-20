<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Instanceof_;
use Psalm\Node\Virtual_Node;
final class Virtual_Instanceof extends Instanceof_ implements Virtual_Node
{
}