<?php

declare (strict_types=1);
namespace Psalm\Node;

use Php_Parser\Node\Identifier;
/**
 * Represents a non-namespaced name. Namespaced names are represented using Name nodes.
 */
final class Virtual_Identifier extends Identifier implements Virtual_Node
{
}