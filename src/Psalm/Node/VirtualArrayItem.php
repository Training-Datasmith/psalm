<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Array_Item;
use Psalm\Node\Virtual_Node;
final class Virtual_Array_Item extends Array_Item implements Virtual_Node
{
}