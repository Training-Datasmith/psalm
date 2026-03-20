<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser\Node;
use Php_Parser\Node\Expr\Yield_From;
use Php_Parser\Node\Expr\Yield_;
use Php_Parser\Node\Function_Like;
use Php_Parser\Node_Visitor_Abstract;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Type;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Yield_Type_Collector extends Node_Visitor_Abstract
{
    /** @var list<Union> */
    private array $yield_types = [];
    public function __construct(private readonly Node_Data_Provider $nodes)
    {
    }
    #[Override]
    public function enter_node(Node $node): ?int
    {
        if ($node instanceof Yield_) {
            $key_type = null;
            if ($node->key && $node_key_type = $this->nodes->get_type($node->key)) {
                $key_type = $node_key_type;
            }
            if ($node->value && $value_type = $this->nodes->get_type($node->value)) {
                $generator_type = new T_Generic_Object('Generator', [$key_type ?: Type::get_int(), $value_type, Type::get_mixed(), Type::get_mixed()]);
                $this->yield_types[] = new Union([$generator_type]);
                return null;
            }
            $this->yield_types[] = Type::get_mixed();
        } elseif ($node instanceof Yield_From) {
            if ($node_expr_type = $this->nodes->get_type($node->expr)) {
                $this->yield_types[] = $node_expr_type;
                return null;
            }
            $this->yield_types[] = Type::get_mixed();
        } elseif ($node instanceof Function_Like) {
            return self::DONT_TRAVERSE_CHILDREN;
        }
        return null;
    }
    /**
     * @return list<Union>
     */
    public function get_yield_types(): array
    {
        return $this->yield_types;
    }
}