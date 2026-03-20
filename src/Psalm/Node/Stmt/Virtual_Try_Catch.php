<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Try_Catch;
use Psalm\Node\Virtual_Node;
final class Virtual_Try_Catch extends Try_Catch implements Virtual_Node
{
}