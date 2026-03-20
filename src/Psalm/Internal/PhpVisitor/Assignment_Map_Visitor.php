<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor;

use Override;
use Php_Parser;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
/**
 * @internal
 *
 * This produces a graph of probably assignments inside a loop
 *
 * With this map we can calculate how many times the loop analysis must
 * be run before all variables have the correct types
 */
final class Assignment_Map_Visitor extends Php_Parser\Node_Visitor_Abstract
{
    /**
     * @var array<string, array<string, bool>>
     */
    private array $assignment_map = [];
    public function __construct(protected ?string $this_class_name)
    {
    }
    #[Override]
    public function enter_node(Php_Parser\Node $node): ?int
    {
        if ($node instanceof Php_Parser\Node\Expr\Assign) {
            $right_var_id = Expression_Identifier::get_root_var_id($node->expr, $this->this_class_name);
            if ($node->var instanceof Php_Parser\Node\Expr\List_ || $node->var instanceof Php_Parser\Node\Expr\Array_) {
                foreach ($node->var->items as $assign_item) {
                    if ($assign_item) {
                        $left_var_id = Expression_Identifier::get_root_var_id($assign_item->value, $this->this_class_name);
                        if ($left_var_id) {
                            $this->assignment_map[$left_var_id][$right_var_id ?: 'isset'] = true;
                        }
                    }
                }
            } else {
                $left_var_id = Expression_Identifier::get_root_var_id($node->var, $this->this_class_name);
                if ($left_var_id) {
                    $this->assignment_map[$left_var_id][$right_var_id ?: 'isset'] = true;
                }
            }
            return Php_Parser\Node_Visitor::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Php_Parser\Node\Expr\Post_Inc || $node instanceof Php_Parser\Node\Expr\Post_Dec || $node instanceof Php_Parser\Node\Expr\Pre_Inc || $node instanceof Php_Parser\Node\Expr\Pre_Dec || $node instanceof Php_Parser\Node\Expr\Assign_Op) {
            $var_id = Expression_Identifier::get_root_var_id($node->var, $this->this_class_name);
            if ($var_id) {
                $this->assignment_map[$var_id][$var_id] = true;
            }
            return Php_Parser\Node_Visitor::DONT_TRAVERSE_CHILDREN;
        }
        if ($node instanceof Php_Parser\Node\Expr\Func_Call || $node instanceof Php_Parser\Node\Expr\Method_Call || $node instanceof Php_Parser\Node\Expr\Static_Call) {
            if (!$node->is_first_class_callable()) {
                foreach ($node->get_args() as $arg) {
                    $arg_var_id = Expression_Identifier::get_root_var_id($arg->value, $this->this_class_name);
                    if ($arg_var_id) {
                        $this->assignment_map[$arg_var_id][$arg_var_id] = true;
                    }
                }
            }
            if ($node instanceof Php_Parser\Node\Expr\Method_Call) {
                $var_id = Expression_Identifier::get_root_var_id($node->var, $this->this_class_name);
                if ($var_id) {
                    $this->assignment_map[$var_id]['isset'] = true;
                }
            }
        } elseif ($node instanceof Php_Parser\Node\Stmt\Unset_) {
            foreach ($node->vars as $arg) {
                $arg_var_id = Expression_Identifier::get_root_var_id($arg, $this->this_class_name);
                if ($arg_var_id) {
                    $this->assignment_map[$arg_var_id][$arg_var_id] = true;
                }
            }
        }
        return null;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_assignment_map(): array
    {
        return $this->assignment_map;
    }
}