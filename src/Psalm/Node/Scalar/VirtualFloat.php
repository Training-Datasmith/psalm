<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar;

use Php_Parser\Node\Scalar\Float_;
use Psalm\Node\Virtual_Node;
final class Virtual_Float extends Float_ implements Virtual_Node
{
}