<?php

declare (strict_types=1);
// based on PhpParser's builtin one
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use Php_Parser\Error_Handler;
use Php_Parser\Name_Context;
use Php_Parser\Node;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Name;
use Php_Parser\Node\Nullable_Type;
use Php_Parser\Node\Stmt;
use Php_Parser\Node_Visitor_Abstract;
/**
 * @internal
 */
final class Simple_Name_Resolver extends Node_Visitor_Abstract
{
    private readonly Name_Context $name_context;
    private ?int $start_change = null;
    private ?int $end_change = null;
    /**
     * @param ErrorHandler $errorHandler Error handler
     * @param null|array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}> $offset_map
     */
    public function __construct(Error_Handler $error_handler, ?array $offset_map = null)
    {
        if ($offset_map) {
            foreach ($offset_map as [, , $b_s, $b_e]) {
                if ($this->start_change === null) {
                    $this->start_change = $b_s;
                }
                $this->end_change = $b_e;
            }
        }
        $this->name_context = new Name_Context($error_handler);
    }
    #[Override]
    public function before_traverse(array $nodes): ?array
    {
        $this->name_context->start_namespace();
        return null;
    }
    #[Override]
    public function enter_node(Node $node): ?int
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->name_context->start_namespace($node->name);
        } elseif ($node instanceof Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->add_alias($use, $node->type);
            }
        } elseif ($node instanceof Stmt\Group_Use) {
            foreach ($node->uses as $use) {
                $this->add_alias($use, $node->type, $node->prefix);
            }
        } elseif ($node instanceof Stmt\Class_) {
            if (null !== $node->extends) {
                $node->extends = $this->resolve_class_name($node->extends);
            }
            foreach ($node->implements as &$interface) {
                $interface = $this->resolve_class_name($interface);
            }
            unset($interface);
            $this->resolve_attr_groups($node);
            if (null !== $node->name) {
                $this->add_namespaced_name($node);
            }
        }
        if ($node instanceof Stmt\Class_Method && $this->start_change && $this->end_change) {
            /** @var array{startFilePos: int, endFilePos: int} */
            $attrs = $node->get_attributes();
            if ($cs = $node->get_comments()) {
                $attrs['startFilePos'] = $cs[0]->get_start_file_pos();
            }
            if ($attrs['endFilePos'] < $this->start_change || $attrs['startFilePos'] > $this->end_change) {
                return Php_Parser\Node_Visitor::DONT_TRAVERSE_CHILDREN;
            }
        }
        if ($node instanceof Stmt\Class_Method || $node instanceof Expr\Closure) {
            $this->resolve_signature($node);
        } elseif ($node instanceof Expr\Static_Call || $node instanceof Expr\Static_Property_Fetch || $node instanceof Expr\Class_Const_Fetch || $node instanceof Expr\New_ || $node instanceof Expr\Instanceof_) {
            if ($node->class instanceof Name) {
                $node->class = $this->resolve_class_name($node->class);
            }
        } elseif ($node instanceof Stmt\Catch_) {
            foreach ($node->types as &$type) {
                $type = $this->resolve_class_name($type);
            }
            unset($type);
        } elseif ($node instanceof Expr\Func_Call) {
            if ($node->name instanceof Name) {
                $node->name = $this->resolve_name($node->name, Stmt\Use_::TYPE_FUNCTION);
            }
        } elseif ($node instanceof Expr\Const_Fetch) {
            $node->name = $this->resolve_name($node->name, Stmt\Use_::TYPE_CONSTANT);
        } elseif ($node instanceof Stmt\Trait_) {
            $this->resolve_trait($node);
        } elseif ($node instanceof Stmt\Trait_Use) {
            foreach ($node->traits as &$trait) {
                $trait = $this->resolve_class_name($trait);
            }
            unset($trait);
            foreach ($node->adaptations as $adaptation) {
                if (null !== $adaptation->trait) {
                    $adaptation->trait = $this->resolve_class_name($adaptation->trait);
                }
                if ($adaptation instanceof Stmt\Trait_Use_Adaptation\Precedence) {
                    foreach ($adaptation->insteadof as &$insteadof) {
                        $insteadof = $this->resolve_class_name($insteadof);
                    }
                    unset($insteadof);
                }
            }
        }
        return null;
    }
    /**
     * @param Stmt\Use_::TYPE_* $type
     */
    private function add_alias(Node\Use_Item $use, int $type, ?Name $prefix = null): void
    {
        // Add prefix for group uses
        /** @var Name $name */
        $name = $prefix ? Name::concat($prefix, $use->name) : $use->name;
        // Type is determined either by individual element or whole use declaration
        $type |= $use->type;
        $this->name_context->add_alias($name, (string) $use->get_alias(), $type, $use->get_attributes());
    }
    /**
     * @param Stmt\Function_|Stmt\ClassMethod|Expr\Closure $node
     */
    private function resolve_signature(Php_Parser\Node_Abstract $node): void
    {
        foreach ($node->params as $param) {
            $param->type = $this->resolve_type($param->type);
        }
        $node->return_type = $this->resolve_type($node->return_type);
    }
    /**
     * @template T of Node|null
     * @param T $node
     * @return ($node is NullableType ? NullableType : ($node is Name ? Name : T))
     */
    private function resolve_type(?Node $node): ?Node
    {
        if ($node instanceof Nullable_Type) {
            $node->type = $this->resolve_type($node->type);
            return $node;
        }
        if ($node instanceof Name) {
            return $this->resolve_class_name($node);
        }
        return $node;
    }
    /**
     * Resolve name, according to name resolver options.
     *
     * CAVE: Attribute values are of type `string`, this is
     * different to PhpParser's `NameResolver` using objects.
     *
     * @param Name $name Function or constant name to resolve
     * @param Stmt\Use_::TYPE_*  $type One of Stmt\Use_::TYPE_*
     * @return Name Resolved name, or original name with attribute
     */
    private function resolve_name(Name $name, int $type): Name
    {
        $resolved_name = $this->name_context->get_resolved_name($name, $type);
        if (null !== $resolved_name) {
            $name->set_attribute('resolvedName', $resolved_name->to_string());
        } else {
            $namespace_name = Name\Fully_Qualified::concat($this->name_context->get_namespace(), $name, $name->get_attributes());
            if ($namespace_name instanceof Name) {
                $name->set_attribute('namespacedName', $namespace_name->to_string());
            }
        }
        return $name;
    }
    private function resolve_class_name(Name $name): Name
    {
        return $this->resolve_name($name, Stmt\Use_::TYPE_NORMAL);
    }
    private function add_namespaced_name(Stmt\Class_ $node): void
    {
        $node->set_attribute('namespacedName', Name::concat($this->name_context->get_namespace(), (string) $node->name));
    }
    private function resolve_attr_groups(Stmt\Class_ $node): void
    {
        foreach ($node->attr_groups as $attr_group) {
            foreach ($attr_group->attrs as $attr) {
                $attr->name = $this->resolve_class_name($attr->name);
            }
        }
    }
    private function resolve_trait(Stmt\Trait_ $node): void
    {
        $resolved_name = Name::concat($this->name_context->get_namespace(), (string) $node->name);
        if (null !== $resolved_name) {
            $node->set_attribute('resolvedName', $resolved_name->to_string());
        }
    }
}