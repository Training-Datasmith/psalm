<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Var_Like_Identifier;
/**
 * Represents a name that is written in source code with a leading dollar,
 * but is not a proper variable. The leading dollar is not stored as part of the name.
 *
 * Examples: Names in property declarations are formatted as variables. Names in static property
 * lookups are also formatted as variables.
 */
final class Virtual_Var_Like_Identifier extends Var_Like_Identifier implements Virtual_Node
{
}