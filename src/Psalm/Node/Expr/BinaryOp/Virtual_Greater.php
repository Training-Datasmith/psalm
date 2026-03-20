<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Greater;
use Psalm\Node\Virtual_Node;
final class Virtual_Greater extends Greater implements Virtual_Node
{
}