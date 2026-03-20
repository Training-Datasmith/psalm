<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt\Trait_Use_Adaptation;

use Php_Parser\Node\Stmt\Trait_Use_Adaptation\Alias;
use Psalm\Node\Virtual_Node;
final class Virtual_Alias extends Alias implements Virtual_Node
{
}