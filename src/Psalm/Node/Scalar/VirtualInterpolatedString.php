<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar;

use Php_Parser\Node\Scalar\Interpolated_String;
use Psalm\Node\Virtual_Node;
final class Virtual_Interpolated_String extends Interpolated_String implements Virtual_Node
{
}