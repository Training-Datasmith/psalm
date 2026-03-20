<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Use_;
use Psalm\Node\Virtual_Node;
final class Virtual_Use extends Use_ implements Virtual_Node
{
}