<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Property_Item;
use Psalm\Node\Virtual_Node;
final class Virtual_Property_Item extends Property_Item implements Virtual_Node
{
}