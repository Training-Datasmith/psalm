<?php

declare (strict_types=1);
namespace Psalm\Node\Stmt;

use Php_Parser\Node\Stmt\Static_;
use Psalm\Node\Virtual_Node;
final class Virtual_Static extends Static_ implements Virtual_Node
{
}