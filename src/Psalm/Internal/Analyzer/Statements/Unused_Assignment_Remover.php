<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\File_Manipulation;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Php_Visitor\Check_Trivial_Expr_Visitor;
use function array_key_exists;
use function array_slice;
use function count;
use function is_array;
use function is_string;
use function strlen;
use function substr;
use function token_get_all;
use function trim;
/**
 * @internal
 */
final class Unused_Assignment_Remover
{
    /**
     * @var array<string, CodeLocation>
     */
    private array $removed_unref_vars = [];
    /**
     * @param array<PhpParser\Node\Stmt>   $stmts
     * @param array<string, CodeLocation> $var_loc_map
     */
    public function find_unused_assignment(Codebase $codebase, array $stmts, array $var_loc_map, string $var_id, Code_Location $original_location): void
    {
        $search_result = $this->find_assign_stmt($stmts, $var_id, $original_location);
        [$assign_stmt, $assign_exp] = $search_result;
        $chain_assignment = false;
        if ($assign_stmt !== null && $assign_exp !== null) {
            // Check if we have to remove assignment statement as expression (i.e. just "$var = ")
            // Consider chain of assignments
            $rhs_exp = $assign_exp->expr;
            if ($rhs_exp instanceof Php_Parser\Node\Expr\Assign || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Op || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Ref) {
                $chain_assignment = true;
                $removable_stmt = $this->check_removable_chain_assignment($assign_exp, $var_loc_map);
            } else {
                $removable_stmt = true;
            }
            if ($removable_stmt) {
                $traverser = new Php_Parser\Node_Traverser();
                $visitor = new Check_Trivial_Expr_Visitor();
                $traverser->add_visitor($visitor);
                $traverser->traverse([$rhs_exp]);
                $rhs_exp_trivial = !$visitor->has_non_trivial_expr();
                if ($rhs_exp_trivial) {
                    $treat_as_expr = false;
                } else {
                    $treat_as_expr = true;
                }
            } else {
                $treat_as_expr = true;
            }
            if ($treat_as_expr) {
                $is_assign_ref = $assign_exp instanceof Php_Parser\Node\Expr\Assign_Ref;
                $new_file_manipulation = self::get_partial_removal_bounds($codebase, $original_location, $assign_stmt->get_end_file_pos(), $is_assign_ref);
                $this->removed_unref_vars[$var_id] = $original_location;
            } else {
                // Remove whole assignment statement
                $new_file_manipulation = new File_Manipulation($assign_stmt->get_start_file_pos(), $assign_stmt->get_end_file_pos() + 1, "", false, true);
                // If statement we are removing is a chain of assignments, mark other variables as removed
                if ($chain_assignment) {
                    $this->mark_removed_chain_assign_var($assign_exp, $var_loc_map);
                } else {
                    $this->removed_unref_vars[$var_id] = $original_location;
                }
            }
            File_Manipulation_Buffer::add($original_location->file_path, [$new_file_manipulation]);
        } elseif ($assign_exp !== null) {
            $is_assign_ref = $assign_exp instanceof Php_Parser\Node\Expr\Assign_Ref;
            $new_file_manipulation = self::get_partial_removal_bounds($codebase, $original_location, $assign_exp->get_end_file_pos(), $is_assign_ref);
            File_Manipulation_Buffer::add($original_location->file_path, [$new_file_manipulation]);
            $this->removed_unref_vars[$var_id] = $original_location;
        }
    }
    private static function get_partial_removal_bounds(Codebase $codebase, Code_Location $var_loc, int $end_bound, bool $assign_ref = false): File_Manipulation
    {
        $var_start_loc = $var_loc->raw_file_start;
        $stmt_content = $codebase->file_provider->get_contents($var_loc->file_path);
        $str_for_token = "<?php\n" . substr($stmt_content, $var_start_loc, $end_bound - $var_start_loc + 1);
        $token_list = array_slice(token_get_all($str_for_token), 1);
        //Ignore "<?php"
        $offset_count = strlen($token_list[0][1]);
        $iter = 1;
        // Check if second token is just whitespace
        if (is_array($token_list[$iter]) && trim($token_list[$iter][1]) === '') {
            $offset_count += strlen($token_list[1][1]);
            $iter++;
        }
        // Add offset for assignment operator
        if (is_string($token_list[$iter])) {
            $offset_count += 1;
        } else {
            $offset_count += strlen($token_list[$iter][1]);
        }
        $iter++;
        // Remove any whitespace following assignment operator token (e.g "=", "+=")
        if (is_array($token_list[$iter]) && trim($token_list[$iter][1]) === '') {
            $offset_count += strlen($token_list[$iter][1]);
            $iter++;
        }
        // If we are dealing with assignment by reference, we need to handle "&" and any whitespace after
        if ($assign_ref) {
            $offset_count += 1;
            $iter++;
            // Handle any whitespace after "&"
            if (is_array($token_list[$iter]) && trim($token_list[$iter][1]) === '') {
                $offset_count += strlen($token_list[$iter][1]);
            }
        }
        $file_man_start = $var_start_loc;
        $file_man_end = $var_start_loc + $offset_count;
        return new File_Manipulation($file_man_start, $file_man_end, "", false);
    }
    /**
     * @param  PhpParser\Node\Expr\Assign|PhpParser\Node\Expr\AssignOp|PhpParser\Node\Expr\AssignRef $cur_assign
     * @param  array<string, CodeLocation>    $var_loc_map
     */
    private function mark_removed_chain_assign_var(Php_Parser\Node\Expr $cur_assign, array $var_loc_map): void
    {
        $var = $cur_assign->var;
        if ($var instanceof Php_Parser\Node\Expr\Variable && is_string($var->name)) {
            $var_name = "\$" . $var->name;
            $var_loc = $var_loc_map[$var_name];
            $this->removed_unref_vars[$var_name] = $var_loc;
            $rhs_exp = $cur_assign->expr;
            if ($rhs_exp instanceof Php_Parser\Node\Expr\Assign || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Op || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Ref) {
                $this->mark_removed_chain_assign_var($rhs_exp, $var_loc_map);
            }
        }
    }
    /**
     * @param  PhpParser\Node\Expr\Assign|PhpParser\Node\Expr\AssignOp|PhpParser\Node\Expr\AssignRef $cur_assign
     * @param  array<string, CodeLocation> $var_loc_map
     */
    private function check_removable_chain_assignment(Php_Parser\Node\Expr $cur_assign, array $var_loc_map): bool
    {
        // Check if current assignment expr's variable is removable
        $var = $cur_assign->var;
        if ($var instanceof Php_Parser\Node\Expr\Variable && is_string($var->name)) {
            $var_loc = $cur_assign->var->get_start_file_pos();
            $var_name = "\$" . $var->name;
            if (array_key_exists($var_name, $var_loc_map) && $var_loc_map[$var_name]->raw_file_start === $var_loc) {
                $curr_removable = true;
            } else {
                $curr_removable = false;
            }
            if ($curr_removable) {
                $rhs_exp = $cur_assign->expr;
                if ($rhs_exp instanceof Php_Parser\Node\Expr\Assign || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Op || $rhs_exp instanceof Php_Parser\Node\Expr\Assign_Ref) {
                    return $this->check_removable_chain_assignment($rhs_exp, $var_loc_map);
                }
            }
            return $curr_removable;
        }
        return false;
    }
    /**
     * @param  array<PhpParser\Node\Stmt>   $stmts
     * @return array{
     *          0: PhpParser\Node\Stmt|null,
     *          1: PhpParser\Node\Expr\Assign|PhpParser\Node\Expr\AssignOp|PhpParser\Node\Expr\AssignRef|null
     *          }
     */
    private function find_assign_stmt(array $stmts, string $var_id, Code_Location $original_location): array
    {
        $assign_stmt = null;
        $assign_exp = null;
        $assign_exp_found = false;
        $i = 0;
        while ($i < count($stmts) && !$assign_exp_found) {
            $stmt = $stmts[$i];
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression) {
                $search_result = $this->find_assign_exp($stmt->expr, $var_id, $original_location->raw_file_start);
                [$target_exp, $levels_taken] = $search_result;
                if ($target_exp !== null) {
                    $assign_exp_found = true;
                    $assign_exp = $target_exp;
                    $assign_stmt = $levels_taken === 1 ? $stmt : null;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Try_Catch) {
                $search_result = $this->find_assign_stmt($stmt->stmts, $var_id, $original_location);
                if ($search_result[0] && $search_result[1]) {
                    return $search_result;
                }
                foreach ($stmt->catches as $catch_stmt) {
                    $search_result = $this->find_assign_stmt($catch_stmt->stmts, $var_id, $original_location);
                    if ($search_result[0] && $search_result[1]) {
                        return $search_result;
                    }
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Do_ || $stmt instanceof Php_Parser\Node\Stmt\While_) {
                $search_result = $this->find_assign_stmt($stmt->stmts, $var_id, $original_location);
                if ($search_result[0] && $search_result[1]) {
                    return $search_result;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Foreach_) {
                $search_result = $this->find_assign_stmt($stmt->stmts, $var_id, $original_location);
                if ($search_result[0] && $search_result[1]) {
                    return $search_result;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\For_) {
                $search_result = $this->find_assign_stmt($stmt->stmts, $var_id, $original_location);
                if ($search_result[0] && $search_result[1]) {
                    return $search_result;
                }
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\If_) {
                $search_result = $this->find_assign_stmt($stmt->stmts, $var_id, $original_location);
                if ($search_result[0] && $search_result[1]) {
                    return $search_result;
                }
                foreach ($stmt->elseifs as $elseif_stmt) {
                    $search_result = $this->find_assign_stmt($elseif_stmt->stmts, $var_id, $original_location);
                    if ($search_result[0] && $search_result[1]) {
                        return $search_result;
                    }
                }
                if ($stmt->else) {
                    $search_result = $this->find_assign_stmt($stmt->else->stmts, $var_id, $original_location);
                    if ($search_result[0] && $search_result[1]) {
                        return $search_result;
                    }
                }
            }
            $i++;
        }
        return [$assign_stmt, $assign_exp];
    }
    /**
     * @return array{
     *          0: PhpParser\Node\Expr\Assign|PhpParser\Node\Expr\AssignOp|PhpParser\Node\Expr\AssignRef|null,
     *          1: int
     *          }
     */
    private function find_assign_exp(Php_Parser\Node\Expr $current_node, string $var_id, int $var_start_loc, int $search_level = 1): array
    {
        if ($current_node instanceof Php_Parser\Node\Expr\Assign || $current_node instanceof Php_Parser\Node\Expr\Assign_Op || $current_node instanceof Php_Parser\Node\Expr\Assign_Ref) {
            $var = $current_node->var;
            if ($var instanceof Php_Parser\Node\Expr\Variable && $var->name === substr($var_id, 1) && $var->get_start_file_pos() === $var_start_loc) {
                return [$current_node, $search_level];
            }
            $rhs_exp = $current_node->expr;
            $rhs_search_result = $this->find_assign_exp($rhs_exp, $var_id, $var_start_loc, $search_level + 1);
            return [$rhs_search_result[0], $rhs_search_result[1]];
        }
        return [null, $search_level];
    }
    public function check_if_var_removed(string $var_id, Code_Location $var_loc): bool
    {
        return array_key_exists($var_id, $this->removed_unref_vars) && $this->removed_unref_vars[$var_id] === $var_loc;
    }
}