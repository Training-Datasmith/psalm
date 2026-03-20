<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Group_Use;
use Psalm\Node\Virtual_Node;
final class Virtual_Group_Use extends Group_Use implements Virtual_Node
{
}