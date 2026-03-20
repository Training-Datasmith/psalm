<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Inline_Html;
use Psalm\Node\Virtual_Node;
final class Virtual_Inline_Html extends Inline_Html implements Virtual_Node
{
}