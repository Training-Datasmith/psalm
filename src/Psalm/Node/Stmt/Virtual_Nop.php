<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Nop;
use Psalm\Node\Virtual_Node;
/** Nop/empty statement (;). */
final class Virtual_Nop extends Nop implements Virtual_Node
{
}