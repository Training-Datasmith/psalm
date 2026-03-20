<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Cast;

use Php_Parser\Node\Expr\Cast\String_;
use Psalm\Node\Virtual_Node;
final class Virtual_String extends String_ implements Virtual_Node
{
}