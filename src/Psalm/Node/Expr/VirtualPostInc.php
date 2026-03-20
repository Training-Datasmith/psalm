<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Post_Inc;
use Psalm\Node\Virtual_Node;
final class Virtual_Post_Inc extends Post_Inc implements Virtual_Node
{
}