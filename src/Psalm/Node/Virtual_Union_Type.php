<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Union_Type;
final class Virtual_Union_Type extends Union_Type implements Virtual_Node
{
}