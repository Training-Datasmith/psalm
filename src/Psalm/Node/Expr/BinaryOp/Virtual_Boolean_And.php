<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Boolean_And;
use Psalm\Node\Virtual_Node;
final class Virtual_Boolean_And extends Boolean_And implements Virtual_Node
{
}