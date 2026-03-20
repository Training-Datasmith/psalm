<?php

declare (strict_types=1);
namespace Psalm\Node\Scalar;

use Php_Parser\Node\Interpolated_String_Part;
use Psalm\Node\Virtual_Node;
final class Virtual_Interpolated_String_Part extends Interpolated_String_Part implements Virtual_Node
{
}