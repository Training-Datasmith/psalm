<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Line;
use Psalm\Node\Virtual_Node;
final class Virtual_Line extends Line implements Virtual_Node
{
}