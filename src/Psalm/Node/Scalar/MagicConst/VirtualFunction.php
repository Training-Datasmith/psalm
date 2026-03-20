<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Function_;
use Psalm\Node\Virtual_Node;
final class Virtual_Function extends Function_ implements Virtual_Node
{
}