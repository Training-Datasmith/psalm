<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar;

use Php_Parser\Node\Scalar\String_;
use Psalm\Node\Virtual_Node;
final class Virtual_String extends String_ implements Virtual_Node
{
}