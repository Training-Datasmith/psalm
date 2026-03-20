<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Use_Item;
use Psalm\Node\Virtual_Node;
final class Virtual_Use_Item extends Use_Item implements Virtual_Node
{
}