<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Closure_Use;
use Psalm\Node\Virtual_Node;
final class Virtual_Closure_Use extends Closure_Use implements Virtual_Node
{
}