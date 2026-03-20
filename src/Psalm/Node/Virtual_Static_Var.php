<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Static_Var;
use Psalm\Node\Virtual_Node;
final class Virtual_Static_Var extends Static_Var implements Virtual_Node
{
}