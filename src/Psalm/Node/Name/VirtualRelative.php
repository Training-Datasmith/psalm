<?php

declare (strict_types=1);
namespace Psalm\Node\Name;

use Php_Parser\Node\Name\Relative;
use Psalm\Node\Virtual_Node;
final class Virtual_Relative extends Relative implements Virtual_Node
{
}