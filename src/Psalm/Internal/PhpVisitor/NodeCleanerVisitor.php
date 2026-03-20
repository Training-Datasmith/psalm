<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use Psalm\Internal\Provider\Node_Data_Provider;
/**
 * @internal
 */
final class Node_Cleaner_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    public function __construct(private readonly Node_Data_Provider $type_provider)
    {
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        if ($node instanceof Php_Parser\Node\Expr) {
            $this->type_provider->clear_node_of_type_and_assertions($node);
        }
        return null;
    }
}