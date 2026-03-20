<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Php_Parser;
use Psalm\Internal\Method_Identifier;
/**
 * @internal
 */
final class Atomic_Call_Context
{
    /** @param list<PhpParser\Node\Arg> $args */
    public function __construct(public Method_Identifier $method_id, public array $args)
    {
    }
}