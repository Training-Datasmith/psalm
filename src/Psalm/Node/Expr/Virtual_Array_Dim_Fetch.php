<?php

declare (strict_types=1);
namespace Psalm\Node\Expr;

use Php_Parser\Node\Expr\Array_Dim_Fetch;
use Psalm\Node\Virtual_Node;
final class Virtual_Array_Dim_Fetch extends Array_Dim_Fetch implements Virtual_Node
{
}