<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar\Magic_Const;

use Php_Parser\Node\Scalar\Magic_Const\Class_;
use Psalm\Node\Virtual_Node;
final class Virtual_Class extends Class_ implements Virtual_Node
{
}