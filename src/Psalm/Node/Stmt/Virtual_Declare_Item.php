<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Declare_Item;
use Psalm\Node\Virtual_Node;
final class Virtual_Declare_Item extends Declare_Item implements Virtual_Node
{
}