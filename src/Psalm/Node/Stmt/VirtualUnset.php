<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Unset_;
use Psalm\Node\Virtual_Node;
final class Virtual_Unset extends Unset_ implements Virtual_Node
{
}