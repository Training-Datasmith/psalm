<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt\Trait_Use_Adaptation;

use Php_Parser\Node\Stmt\Trait_Use_Adaptation\Precedence;
use Psalm\Node\Virtual_Node;
final class Virtual_Precedence extends Precedence implements Virtual_Node
{
}