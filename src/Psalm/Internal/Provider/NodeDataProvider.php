<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Override;
use Php_Parser\Node;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Func_Call;
use Php_Parser\Node\Expr\Method_Call;
use Php_Parser\Node\Expr\New_;
use Php_Parser\Node\Expr\Static_Call;
use Php_Parser\Node\Name;
use Php_Parser\Node\Stmt\Return_;
use Php_Parser\Node_Abstract;
use Psalm\Node_Type_Provider;
use Psalm\Storage\Assertion;
use Psalm\Storage\Possibilities;
use Psalm\Type\Union;
use Spl_Object_Storage;
/**
 * @internal
 */
final class Node_Data_Provider implements Node_Type_Provider
{
    /** @var SplObjectStorage<Node, Union> */
    private Spl_Object_Storage $node_types;
    /**
     * @var SplObjectStorage<Node,list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>|null>
     */
    private Spl_Object_Storage $node_assertions;
    /** @var SplObjectStorage<Node, array<int, Possibilities>> */
    private Spl_Object_Storage $node_if_true_assertions;
    /** @var SplObjectStorage<Node, array<int, Possibilities>> */
    private Spl_Object_Storage $node_if_false_assertions;
    public bool $cache_assertions = true;
    public function __construct()
    {
        $this->node_types = new Spl_Object_Storage();
        $this->node_assertions = new Spl_Object_Storage();
        $this->node_if_true_assertions = new Spl_Object_Storage();
        $this->node_if_false_assertions = new Spl_Object_Storage();
    }
    /**
     * @param Expr|Name|Return_ $node
     */
    #[Override]
    public function set_type(Node_Abstract $node, Union $type): void
    {
        $this->node_types[$node] = $type;
    }
    /**
     * @param Expr|Name|Return_ $node
     */
    #[Override]
    public function get_type(Node_Abstract $node): ?Union
    {
        return $this->node_types[$node] ?? null;
    }
    /**
     * @param list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>|null $assertions
     */
    public function set_assertions(Expr $node, ?array $assertions): void
    {
        if (!$this->cache_assertions) {
            return;
        }
        $this->node_assertions[$node] = $assertions;
    }
    /**
     * @return list<non-empty-array<string, non-empty-list<non-empty-list<Assertion>>>>|null
     */
    public function get_assertions(Expr $node): ?array
    {
        if (!$this->cache_assertions) {
            return null;
        }
        return $this->node_assertions[$node] ?? null;
    }
    /**
     * @param FuncCall|MethodCall|StaticCall|New_ $node
     * @param array<int, Possibilities>  $assertions
     */
    public function set_if_true_assertions(Expr $node, array $assertions): void
    {
        $this->node_if_true_assertions[$node] = $assertions;
    }
    /**
     * @param Expr\FuncCall|MethodCall|StaticCall|New_ $node
     * @return array<int, Possibilities>|null
     */
    public function get_if_true_assertions(Expr $node): ?array
    {
        return $this->node_if_true_assertions[$node] ?? null;
    }
    /**
     * @param FuncCall|MethodCall|StaticCall|New_ $node
     * @param array<int, Possibilities>  $assertions
     */
    public function set_if_false_assertions(Expr $node, array $assertions): void
    {
        $this->node_if_false_assertions[$node] = $assertions;
    }
    /**
     * @param FuncCall|MethodCall|StaticCall|New_ $node
     * @return array<int, Possibilities>|null
     */
    public function get_if_false_assertions(Expr $node): ?array
    {
        return $this->node_if_false_assertions[$node] ?? null;
    }
    public function is_pure_compatible(Expr $node): bool
    {
        $node_type = $this->get_type($node);
        return $node_type && $node_type->reference_free || $node->get_attribute('pure', false);
    }
    public function clear_node_of_type_and_assertions(Expr $node): void
    {
        unset($this->node_types[$node], $this->node_assertions[$node]);
    }
}