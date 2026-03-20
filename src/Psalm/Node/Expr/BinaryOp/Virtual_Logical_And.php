<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Logical_And;
use Psalm\Node\Virtual_Node;
final class Virtual_Logical_And extends Logical_And implements Virtual_Node
{
}