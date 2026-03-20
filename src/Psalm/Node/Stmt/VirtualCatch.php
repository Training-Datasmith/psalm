<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Catch_;
use Psalm\Node\Virtual_Node;
final class Virtual_Catch extends Catch_ implements Virtual_Node
{
}