<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Post_Dec;
use Psalm\Node\Virtual_Node;
final class Virtual_Post_Dec extends Post_Dec implements Virtual_Node
{
}