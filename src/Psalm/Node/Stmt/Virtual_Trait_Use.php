<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Trait_Use;
use Psalm\Node\Virtual_Node;
final class Virtual_Trait_Use extends Trait_Use implements Virtual_Node
{
}