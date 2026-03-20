<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Cast;

use Php_Parser\Node\Expr\Cast\Bool_;
use Psalm\Node\Virtual_Node;
final class Virtual_Bool extends Bool_ implements Virtual_Node
{
}