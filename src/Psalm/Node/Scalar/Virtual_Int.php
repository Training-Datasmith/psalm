<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar;

use Php_Parser\Node\Scalar\Int_;
use Psalm\Node\Virtual_Node;
final class Virtual_Int extends Int_ implements Virtual_Node
{
}