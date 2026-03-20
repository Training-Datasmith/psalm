<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser\Node;
use Php_Parser\Node_Visitor_Abstract;
use Psalm\Internal\Provider\Node_Data_Provider;
/**
 * @internal
 */
final class Type_Mapping_Visitor extends Node_Visitor_Abstract
{
    public function __construct(private readonly Node_Data_Provider $fake_type_provider, private readonly Node_Data_Provider $real_type_provider)
    {
    }
    /**
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint.MissingAnyTypeHint
     */
    #[Override]
    public function enter_node(Node $node)
    {
        $orig_node = $node;
        /** @psalm-suppress ArgumentTypeCoercion */
        $node_type = $this->fake_type_provider->get_type($orig_node);
        if ($node_type) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $this->real_type_provider->set_type($orig_node, $node_type);
        }
        return null;
    }
}