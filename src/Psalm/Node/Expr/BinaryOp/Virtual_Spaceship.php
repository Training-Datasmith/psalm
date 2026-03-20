<?php

declare (strict_types=1);
namespace Psalm\Node\Expr\Binary_Op;

use Php_Parser\Node\Expr\Binary_Op\Spaceship;
use Psalm\Node\Virtual_Node;
final class Virtual_Spaceship extends Spaceship implements Virtual_Node
{
}