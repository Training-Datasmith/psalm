<?php

declare (strict_types=1);
namespace Psalm\Node\Name;

use Php_Parser\Node\Name\Fully_Qualified;
use Psalm\Node\Virtual_Node;
final class Virtual_Fully_Qualified extends Fully_Qualified implements Virtual_Node
{
}