<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Php_Parser;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Node_Type_Provider;
use function array_any;
use function array_diff;
use function array_filter;
use function array_intersect;
use function array_unique;
use function array_values;
use function count;
use function in_array;
/**
 * @internal
 */
final class Scope_Analyzer
{
    public const ACTION_END = 'END';
    public const ACTION_BREAK = 'BREAK';
    public const ACTION_CONTINUE = 'CONTINUE';
    public const ACTION_LEAVE_SWITCH = 'LEAVE_SWITCH';
    public const ACTION_LEAVE_LOOP = 'LEAVE_LOOP';
    public const ACTION_NONE = 'NONE';
    public const ACTION_RETURN = 'RETURN';
    /**
     * @param array<PhpParser\Node> $stmts
     * @param list<'loop'|'switch'> $break_types
     * @param bool $return_is_exit Exit and Throw statements are treated differently from return if this is false
     * @return list<self::ACTION_*>
     * @psalm-suppress ComplexMethod nothing much we can do
     */
    public static function get_control_actions(array $stmts, ?Node_Data_Provider $nodes, array $break_types, bool $return_is_exit = true): array
    {
        if (empty($stmts)) {
            return [self::ACTION_NONE];
        }
        $control_actions = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Return_ || $stmt instanceof Php_Parser\Node\Stmt\Expression && ($stmt->expr instanceof Php_Parser\Node\Expr\Exit_ || $stmt->expr instanceof Php_Parser\Node\Expr\Throw_)) {
                if (!$return_is_exit && $stmt instanceof Php_Parser\Node\Stmt\Return_) {
                    $stmt_return_type = null;
                    if ($nodes && $stmt->expr) {
                        $stmt_return_type = $nodes->get_type($stmt->expr);
                    }
                    // don't consider a return if the expression never returns (e.g. a throw inside a short closure)
                    if ($stmt_return_type && $stmt_return_type->is_never()) {
                        return array_values(array_unique([...$control_actions, self::ACTION_END]));
                    }
                    return array_values(array_unique([...$control_actions, self::ACTION_RETURN]));
                }
                return array_values(array_unique([...$control_actions, self::ACTION_END]));
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression) {
                // This allows calls to functions that always exit to act as exit statements themselves
                if ($nodes && ($stmt_expr_type = $nodes->get_type($stmt->expr)) && $stmt_expr_type->is_never()) {
                    return array_values(array_unique([...$control_actions, self::ACTION_END]));
                }
                continue;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Continue_) {
                $count = !$stmt->num ? 1 : ($stmt->num instanceof Php_Parser\Node\Scalar\Int_ ? $stmt->num->value : null);
                if ($break_types && $count !== null && count($break_types) >= $count) {
                    /** @psalm-suppress InvalidArrayOffset Some int-range improvements are needed */
                    if ($break_types[count($break_types) - $count] === 'switch') {
                        return [...$control_actions, self::ACTION_LEAVE_SWITCH];
                    }
                    return array_values($control_actions);
                }
                return array_values(array_unique([...$control_actions, self::ACTION_CONTINUE]));
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Break_) {
                $count = !$stmt->num ? 1 : ($stmt->num instanceof Php_Parser\Node\Scalar\Int_ ? $stmt->num->value : null);
                if ($break_types && $count !== null && count($break_types) >= $count) {
                    /** @psalm-suppress InvalidArrayOffset Some int-range improvements are needed */
                    if ($break_types[count($break_types) - $count] === 'switch') {
                        return [...$control_actions, self::ACTION_LEAVE_SWITCH];
                    }
                    /** @psalm-suppress InvalidArrayOffset Some int-range improvements are needed */
                    if ($break_types[count($break_types) - $count] === 'loop') {
                        return [...$control_actions, self::ACTION_LEAVE_LOOP];
                    }
                    return array_values($control_actions);
                }
                return array_values(array_unique([...$control_actions, self::ACTION_BREAK]));
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\If_) {
                $if_statement_actions = self::get_control_actions($stmt->stmts, $nodes, $break_types, $return_is_exit);
                $all_leave = !array_any($if_statement_actions, static fn(string $action): bool => $action === self::ACTION_NONE);
                $else_statement_actions = $stmt->else ? self::get_control_actions($stmt->else->stmts, $nodes, $break_types, $return_is_exit) : [];
                $all_leave = $all_leave && $else_statement_actions && !array_any($else_statement_actions, static fn(string $action): bool => $action === self::ACTION_NONE);
                $all_elseif_actions = [];
                foreach ($stmt->elseifs as $elseif) {
                    $elseif_control_actions = self::get_control_actions($elseif->stmts, $nodes, $break_types, $return_is_exit);
                    $all_leave = $all_leave && !array_any($elseif_control_actions, static fn(string $action): bool => $action === self::ACTION_NONE);
                    $all_elseif_actions = [...$elseif_control_actions, ...$all_elseif_actions];
                }
                if ($all_leave) {
                    return array_values(array_unique([...$control_actions, ...$if_statement_actions, ...$else_statement_actions, ...$all_elseif_actions]));
                }
                $control_actions = array_filter([...$control_actions, ...$if_statement_actions, ...$else_statement_actions, ...$all_elseif_actions], static fn(string $action): bool => $action !== self::ACTION_NONE);
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Switch_) {
                $has_ended = false;
                $has_non_breaking_default = false;
                $has_default_terminator = false;
                $all_case_actions = [];
                // iterate backwards in a case statement
                for ($d = count($stmt->cases) - 1; $d >= 0; --$d) {
                    $case = $stmt->cases[$d];
                    $case_actions = self::get_control_actions($case->stmts, $nodes, [...$break_types, 'switch'], $return_is_exit);
                    if (array_intersect([self::ACTION_LEAVE_SWITCH, self::ACTION_BREAK, self::ACTION_CONTINUE], $case_actions)) {
                        continue 2;
                    }
                    if (!$case->cond) {
                        $has_non_breaking_default = true;
                    }
                    $case_does_end = !array_diff($control_actions, [self::ACTION_END, self::ACTION_RETURN]);
                    if ($case_does_end) {
                        $has_ended = true;
                    }
                    $all_case_actions = [...$all_case_actions, ...$case_actions];
                    if (!$case_does_end && !$has_ended) {
                        continue 2;
                    }
                    if ($has_non_breaking_default && $case_does_end) {
                        $has_default_terminator = true;
                    }
                }
                $all_case_actions = array_filter($all_case_actions, static fn(string $action): bool => $action !== self::ACTION_NONE);
                if ($has_default_terminator || $stmt->get_attribute('allMatched', false)) {
                    return array_values(array_unique([...$control_actions, ...$all_case_actions]));
                }
                $control_actions = [...$control_actions, ...$all_case_actions];
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Do_ || $stmt instanceof Php_Parser\Node\Stmt\While_ || $stmt instanceof Php_Parser\Node\Stmt\Foreach_ || $stmt instanceof Php_Parser\Node\Stmt\For_) {
                $loop_actions = self::get_control_actions($stmt->stmts, $nodes, [...$break_types, 'loop'], $return_is_exit);
                $control_actions = array_filter([...$control_actions, ...$loop_actions], static fn(string $action): bool => $action !== self::ACTION_NONE);
                if (($stmt instanceof Php_Parser\Node\Stmt\While_ || $stmt instanceof Php_Parser\Node\Stmt\Do_) && $nodes && ($stmt_expr_type = $nodes->get_type($stmt->cond)) && $stmt_expr_type->is_always_truthy() && !in_array(self::ACTION_LEAVE_LOOP, $control_actions, true)) {
                    //infinite while loop that only return don't have an exit path
                    $have_exit_path = (bool) array_diff($control_actions, [self::ACTION_END, self::ACTION_RETURN]);
                    if (!$have_exit_path) {
                        return array_values(array_unique($control_actions));
                    }
                }
                if ($stmt instanceof Php_Parser\Node\Stmt\For_ && $nodes && !in_array(self::ACTION_LEAVE_LOOP, $control_actions, true)) {
                    $is_infinite_loop = true;
                    foreach ($stmt->cond as $cond) {
                        $stmt_expr_type = $nodes->get_type($cond);
                        if (!$stmt_expr_type || !$stmt_expr_type->is_always_truthy()) {
                            $is_infinite_loop = false;
                        }
                    }
                    if ($is_infinite_loop) {
                        //infinite while loop that only return don't have an exit path
                        $have_exit_path = (bool) array_diff($control_actions, [self::ACTION_END, self::ACTION_RETURN]);
                        if (!$have_exit_path) {
                            return array_values(array_unique($control_actions));
                        }
                    }
                }
                $control_actions = array_filter($control_actions, static fn(string $action): bool => $action !== self::ACTION_LEAVE_LOOP);
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Try_Catch) {
                $try_statement_actions = self::get_control_actions($stmt->stmts, $nodes, $break_types, $return_is_exit);
                $try_leaves = !array_any($try_statement_actions, static fn(string $action): bool => $action === self::ACTION_NONE);
                $all_catch_actions = [];
                if ($stmt->catches) {
                    $all_leave = $try_leaves;
                    foreach ($stmt->catches as $catch) {
                        $catch_actions = self::get_control_actions($catch->stmts, $nodes, $break_types, $return_is_exit);
                        $all_leave = $all_leave && !array_any($catch_actions, static fn(string $action): bool => $action === self::ACTION_NONE);
                        if (!$all_leave) {
                            $control_actions = [...$control_actions, ...$catch_actions];
                        } else {
                            $all_catch_actions = [...$all_catch_actions, ...$catch_actions];
                        }
                    }
                    if ($all_leave && $try_statement_actions !== [self::ACTION_NONE]) {
                        return array_values(array_unique([...$control_actions, ...$try_statement_actions, ...$all_catch_actions]));
                    }
                } elseif ($try_leaves) {
                    return array_values(array_unique([...$control_actions, ...$try_statement_actions]));
                }
                if ($stmt->finally && $stmt->finally->stmts) {
                    $finally_statement_actions = self::get_control_actions($stmt->finally->stmts, $nodes, $break_types, $return_is_exit);
                    if (!in_array(self::ACTION_NONE, $finally_statement_actions, true)) {
                        return [...array_filter($control_actions, static fn(string $action): bool => $action !== self::ACTION_NONE), ...$finally_statement_actions];
                    }
                }
                $control_actions = array_filter([...$control_actions, ...$try_statement_actions], static fn(string $action): bool => $action !== self::ACTION_NONE);
            }
        }
        $control_actions[] = self::ACTION_NONE;
        return array_values(array_unique($control_actions));
    }
    /**
     * @param   array<PhpParser\Node> $stmts
     */
    public static function only_throws_or_exits(Node_Type_Provider $type_provider, array $stmts): bool
    {
        if (empty($stmts)) {
            return false;
        }
        for ($i = count($stmts) - 1; $i >= 0; --$i) {
            $stmt = $stmts[$i];
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression && ($stmt->expr instanceof Php_Parser\Node\Expr\Exit_ || $stmt->expr instanceof Php_Parser\Node\Expr\Throw_)) {
                return true;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression) {
                $stmt_type = $type_provider->get_type($stmt->expr);
                if ($stmt_type && $stmt_type->is_never()) {
                    return true;
                }
            }
        }
        return false;
    }
    /**
     * @param   array<PhpParser\Node> $stmts
     */
    public static function only_throws(array $stmts): bool
    {
        $stmts_count = count($stmts);
        if ($stmts_count !== 1) {
            return false;
        }
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Expression && $stmt->expr instanceof Php_Parser\Node\Expr\Throw_) {
                return true;
            }
        }
        return false;
    }
}