<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Nullable_Type;
final class Virtual_Nullable_Type extends Nullable_Type implements Virtual_Node
{
}