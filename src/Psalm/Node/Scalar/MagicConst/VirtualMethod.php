<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Method;
use Psalm\Node\Virtual_Node;
final class Virtual_Method extends Method implements Virtual_Node
{
}