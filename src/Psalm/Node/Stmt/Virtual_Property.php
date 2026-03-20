<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Property;
use Psalm\Node\Virtual_Node;
final class Virtual_Property extends Property implements Virtual_Node
{
}