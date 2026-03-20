<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Namespace_;
use Psalm\Node\Virtual_Node;
final class Virtual_Namespace extends Namespace_ implements Virtual_Node
{
}