<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Dir;
use Psalm\Node\Virtual_Node;
final class Virtual_Dir extends Dir implements Virtual_Node
{
}