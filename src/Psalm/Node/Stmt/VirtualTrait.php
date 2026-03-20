<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Trait_;
use Psalm\Node\Virtual_Node;
final class Virtual_Trait extends Trait_ implements Virtual_Node
{
}