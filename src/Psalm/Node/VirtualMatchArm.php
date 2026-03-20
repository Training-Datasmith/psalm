<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Match_Arm;
final class Virtual_Match_Arm extends Match_Arm implements Virtual_Node
{
}