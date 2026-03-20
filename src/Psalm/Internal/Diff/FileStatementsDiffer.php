<?php

declare (strict_types=1);
namespace Psalm\Internal\Diff;

use Php_Parser;
use function assert;
use function end;
use function substr;
/**
 * @internal
 */
final class File_Statements_Differ extends Ast_Differ
{
    /**
     * Calculate diff (edit script) from $a to $b.
     *
     * @param list<PhpParser\Node\Stmt> $a
     * @param list<PhpParser\Node\Stmt> $b
     * @return array{
     *      0: list<string>,
     *      1: list<string>,
     *      2: list<string>,
     *      3: list<array{int, int, int, int}>,
     *      4: list<array{int, int}>
     * }
     */
    public static function diff(array $a, array $b, string $a_code, string $b_code): array
    {
        [$trace, $x, $y, $bc] = self::calculate_trace(static function (Php_Parser\Node\Stmt $a, Php_Parser\Node\Stmt $b, string $a_code, string $b_code): bool {
            if ($a::class !== $b::class) {
                return false;
            }
            if ($a instanceof Php_Parser\Node\Stmt\Namespace_ && $b instanceof Php_Parser\Node\Stmt\Namespace_ || $a instanceof Php_Parser\Node\Stmt\Class_ && $b instanceof Php_Parser\Node\Stmt\Class_ || $a instanceof Php_Parser\Node\Stmt\Interface_ && $b instanceof Php_Parser\Node\Stmt\Interface_ || $a instanceof Php_Parser\Node\Stmt\Trait_ && $b instanceof Php_Parser\Node\Stmt\Trait_) {
                return (string) $a->name === (string) $b->name;
            }
            if ($a instanceof Php_Parser\Node\Stmt\Use_ && $b instanceof Php_Parser\Node\Stmt\Use_ || $a instanceof Php_Parser\Node\Stmt\Group_Use && $b instanceof Php_Parser\Node\Stmt\Group_Use) {
                $a_start = (int) $a->get_attribute('startFilePos');
                $a_end = (int) $a->get_attribute('endFilePos');
                $b_start = (int) $b->get_attribute('startFilePos');
                $b_end = (int) $b->get_attribute('endFilePos');
                $a_size = $a_end - $a_start;
                $b_size = $b_end - $b_start;
                if (substr($a_code, $a_start, $a_size) === substr($b_code, $b_start, $b_size)) {
                    return true;
                }
            }
            return false;
        }, $a, $b, $a_code, $b_code);
        $diff = self::extract_diff($trace, $x, $y, $a, $b, $bc);
        $keep = [];
        $keep_signature = [];
        $add_or_delete = [];
        $diff_map = [];
        $deletion_ranges = [];
        foreach ($diff as $diff_elem) {
            if ($diff_elem->type === Diff_Elem::TYPE_KEEP) {
                if ($diff_elem->old instanceof Php_Parser\Node\Stmt\Namespace_ && $diff_elem->new instanceof Php_Parser\Node\Stmt\Namespace_) {
                    $namespace_keep = Namespace_Statements_Differ::diff((string) $diff_elem->old->name, $diff_elem->old->stmts, $diff_elem->new->stmts, $a_code, $b_code);
                    $keep = [...$keep, ...$namespace_keep[0]];
                    $keep_signature = [...$keep_signature, ...$namespace_keep[1]];
                    $add_or_delete = [...$add_or_delete, ...$namespace_keep[2]];
                    $diff_map = [...$diff_map, ...$namespace_keep[3]];
                    $deletion_ranges = [...$deletion_ranges, ...$namespace_keep[4]];
                } elseif ($diff_elem->old instanceof Php_Parser\Node\Stmt\Class_ && $diff_elem->new instanceof Php_Parser\Node\Stmt\Class_ || $diff_elem->old instanceof Php_Parser\Node\Stmt\Interface_ && $diff_elem->new instanceof Php_Parser\Node\Stmt\Interface_ || $diff_elem->old instanceof Php_Parser\Node\Stmt\Trait_ && $diff_elem->new instanceof Php_Parser\Node\Stmt\Trait_) {
                    $class_keep = Class_Statements_Differ::diff((string) $diff_elem->old->name, $diff_elem->old->stmts, $diff_elem->new->stmts, $a_code, $b_code);
                    if ($diff_elem->old->get_doc_comment() === $diff_elem->new->get_doc_comment()) {
                        $keep = [...$keep, ...$class_keep[0]];
                    } else {
                        $add_or_delete = [...$add_or_delete, ...$class_keep[0]];
                    }
                    $keep_signature = [...$keep_signature, ...$class_keep[1]];
                    $add_or_delete = [...$add_or_delete, ...$class_keep[2]];
                    $diff_map = [...$diff_map, ...$class_keep[3]];
                    $deletion_ranges = [...$deletion_ranges, ...$class_keep[4]];
                }
            } elseif ($diff_elem->type === Diff_Elem::TYPE_REMOVE) {
                if ($diff_elem->old instanceof Php_Parser\Node\Stmt\Use_ || $diff_elem->old instanceof Php_Parser\Node\Stmt\Group_Use) {
                    foreach ($diff_elem->old->uses as $use) {
                        if ($use->alias) {
                            $add_or_delete[] = 'use:' . $use->alias;
                        } else {
                            $name_parts = $use->name->get_parts();
                            assert(!empty($name_parts));
                            $add_or_delete[] = 'use:' . end($name_parts);
                        }
                    }
                } elseif ($diff_elem->old instanceof Php_Parser\Node && !$diff_elem->old instanceof Php_Parser\Node\Stmt\Namespace_) {
                    if ($doc = $diff_elem->old->get_doc_comment()) {
                        $start = $doc->get_start_file_pos();
                    } else {
                        $start = (int) $diff_elem->old->get_attribute('startFilePos');
                    }
                    $deletion_ranges[] = [$start, (int) $diff_elem->old->get_attribute('endFilePos')];
                }
            } elseif ($diff_elem->type === Diff_Elem::TYPE_ADD) {
                if ($diff_elem->new instanceof Php_Parser\Node\Stmt\Use_ || $diff_elem->new instanceof Php_Parser\Node\Stmt\Group_Use) {
                    foreach ($diff_elem->new->uses as $use) {
                        if ($use->alias) {
                            $add_or_delete[] = 'use:' . $use->alias;
                        } else {
                            $name_parts = $use->name->get_parts();
                            assert(!empty($name_parts));
                            $add_or_delete[] = 'use:' . end($name_parts);
                        }
                    }
                }
            }
        }
        return [$keep, $keep_signature, $add_or_delete, $diff_map, $deletion_ranges];
    }
}