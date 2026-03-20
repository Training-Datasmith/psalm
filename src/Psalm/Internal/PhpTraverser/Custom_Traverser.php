<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Traverser;

use LogicException;
use Php_Parser\Node;
use Php_Parser\Node_Traverser;
use function array_pop;
use function array_splice;
use function gettype;
use function is_array;
/**
 * @internal
 */
final class Custom_Traverser extends Node_Traverser
{
    public function __construct()
    {
        $this->stop_traversal = false;
    }
    /**
     * Recursively traverse a node.
     *
     * @param Node $node node to traverse
     */
    protected function traverse_node(Node $node): void
    {
        foreach ($node->get_sub_node_names() as $name) {
            $sub_node =& $node->{$name};
            if (is_array($sub_node)) {
                $sub_node = $this->traverse_array($sub_node);
                if ($this->stop_traversal) {
                    break;
                }
            } elseif ($sub_node instanceof Node) {
                $traverse_children = true;
                foreach ($this->visitors as $visitor) {
                    $return = $visitor->enter_node($sub_node, $traverse_children);
                    if (null !== $return) {
                        if ($return instanceof Node) {
                            $sub_node = $return;
                        } elseif (self::DONT_TRAVERSE_CHILDREN === $return) {
                            $traverse_children = false;
                        } elseif (self::STOP_TRAVERSAL === $return) {
                            $this->stop_traversal = true;
                            break 2;
                        } else {
                            throw new LogicException('enterNode() returned invalid value of type ' . gettype($return));
                        }
                    }
                }
                if ($traverse_children) {
                    $this->traverse_node($sub_node);
                    if ($this->stop_traversal) {
                        break;
                    }
                }
                foreach ($this->visitors as $visitor) {
                    $return = $visitor->leave_node($sub_node);
                    if (null !== $return) {
                        if ($return instanceof Node) {
                            $sub_node = $return;
                        } elseif (self::STOP_TRAVERSAL === $return) {
                            $this->stop_traversal = true;
                            break 2;
                        } elseif (is_array($return)) {
                            throw new LogicException('leaveNode() may only return an array ' . 'if the parent structure is an array');
                        } else {
                            throw new LogicException('leaveNode() returned invalid value of type ' . gettype($return));
                        }
                    }
                }
            }
        }
    }
    /**
     * Recursively traverse array (usually of nodes).
     *
     * @param array $nodes Array to traverse
     * @return array Result of traversal (may be original array or changed one)
     */
    protected function traverse_array(array $nodes): array
    {
        $do_nodes = [];
        foreach ($nodes as $i => &$node) {
            if ($node instanceof Node) {
                $traverse_children = true;
                foreach ($this->visitors as $visitor) {
                    $return = $visitor->enter_node($node, $traverse_children);
                    if (null !== $return) {
                        if ($return instanceof Node) {
                            $node = $return;
                        } elseif (self::DONT_TRAVERSE_CHILDREN === $return) {
                            $traverse_children = false;
                        } elseif (self::STOP_TRAVERSAL === $return) {
                            $this->stop_traversal = true;
                            break 2;
                        } else {
                            throw new LogicException('enterNode() returned invalid value of type ' . gettype($return));
                        }
                    }
                }
                if ($traverse_children) {
                    $this->traverse_node($node);
                    if ($this->stop_traversal) {
                        break;
                    }
                }
                foreach ($this->visitors as $visitor) {
                    $return = $visitor->leave_node($node);
                    if (null !== $return) {
                        if ($return instanceof Node) {
                            $node = $return;
                        } elseif (is_array($return)) {
                            $do_nodes[] = [$i, $return];
                            break;
                        } elseif (self::REMOVE_NODE === $return) {
                            $do_nodes[] = [$i, []];
                            break;
                        } elseif (self::STOP_TRAVERSAL === $return) {
                            $this->stop_traversal = true;
                            break 2;
                        } elseif (false === $return) {
                            throw new LogicException('bool(false) return from leaveNode() no longer supported. ' . 'Return NodeVisitor::REMOVE_NODE instead');
                        } else {
                            throw new LogicException('leaveNode() returned invalid value of type ' . gettype($return));
                        }
                    }
                }
            } elseif (is_array($node)) {
                throw new LogicException('Invalid node structure: Contains nested arrays');
            }
        }
        if (!empty($do_nodes)) {
            while ([$i, $replace] = array_pop($do_nodes)) {
                array_splice($nodes, $i, 1, $replace);
            }
        }
        return $nodes;
    }
}