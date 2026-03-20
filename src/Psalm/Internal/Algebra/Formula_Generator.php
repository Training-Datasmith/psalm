<?php

declare (strict_types=1);
namespace Psalm\Internal\Algebra;

use Php_Parser;
use Psalm\Codebase;
use Psalm\File_Source;
use Psalm\Internal\Algebra;
use Psalm\Internal\Analyzer\Statements\Expression\Assertion_Finder;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Node\Expr\Binary_Op\Virtual_Boolean_And;
use Psalm\Node\Expr\Binary_Op\Virtual_Boolean_Or;
use Psalm\Node\Expr\Virtual_Boolean_Not;
use Psalm\Storage\Assertion\Truthy;
use function count;
use function spl_object_id;
use function substr;
/**
 * @internal
 */
final class Formula_Generator
{
    /**
     * @return list<Clause>
     */
    public static function get_formula(int $conditional_object_id, int $creating_object_id, Php_Parser\Node\Expr $conditional, ?string $this_class_name, File_Source $source, ?Codebase $codebase = null, bool $inside_negation = false, bool $cache = true): array
    {
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Logical_And) {
            $left_assertions = self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, $inside_negation, $cache);
            $right_assertions = self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, $inside_negation, $cache);
            return [...$left_assertions, ...$right_assertions];
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Logical_Or) {
            $left_clauses = self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, $inside_negation, $cache);
            $right_clauses = self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, $inside_negation, $cache);
            return Algebra::combine_ored_clauses($left_clauses, $right_clauses, $conditional_object_id);
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Boolean_Not) {
            if ($conditional->expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or) {
                $and_expr = new Virtual_Boolean_And(new Virtual_Boolean_Not($conditional->expr->left, $conditional->get_attributes()), new Virtual_Boolean_Not($conditional->expr->right, $conditional->get_attributes()), $conditional->expr->get_attributes());
                return self::get_formula($conditional_object_id, $conditional_object_id, $and_expr, $this_class_name, $source, $codebase, $inside_negation, false);
            }
            if ($conditional->expr instanceof Php_Parser\Node\Expr\Isset_ && count($conditional->expr->vars) > 1) {
                $anded_assertions = null;
                if ($cache && $source instanceof Statements_Analyzer) {
                    $anded_assertions = $source->node_data->get_assertions($conditional->expr);
                }
                if ($anded_assertions === null) {
                    $anded_assertions = Assertion_Finder::scrape_assertions($conditional->expr, $this_class_name, $source, $codebase, $inside_negation, $cache);
                    if ($cache && $source instanceof Statements_Analyzer) {
                        $source->node_data->set_assertions($conditional->expr, $anded_assertions);
                    }
                }
                $clauses = [];
                foreach ($anded_assertions as $assertions) {
                    foreach ($assertions as $var => $anded_types) {
                        $redefined = false;
                        if ($var[0] === '=') {
                            $var = substr($var, 1);
                            $redefined = true;
                        }
                        foreach ($anded_types as $orred_types) {
                            $mapped_orred_types = [];
                            foreach ($orred_types as $orred_type) {
                                $mapped_orred_types[(string) $orred_type] = $orred_type;
                            }
                            $clauses[] = new Clause([$var => $mapped_orred_types], $conditional_object_id, spl_object_id($conditional->expr), false, true, $orred_types[0]->has_equality(), $redefined ? [$var => true] : []);
                        }
                    }
                }
                return Algebra::negate_formula($clauses);
            }
            if ($conditional->expr instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And) {
                $and_expr = new Virtual_Boolean_Or(new Virtual_Boolean_Not($conditional->expr->left, $conditional->get_attributes()), new Virtual_Boolean_Not($conditional->expr->right, $conditional->get_attributes()), $conditional->expr->get_attributes());
                return self::get_formula($conditional_object_id, spl_object_id($conditional->expr), $and_expr, $this_class_name, $source, $codebase, $inside_negation, false);
            }
            return Algebra::negate_formula(self::get_formula($conditional_object_id, spl_object_id($conditional->expr), $conditional->expr, $this_class_name, $source, $codebase, !$inside_negation));
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Identical || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal) {
            $false_pos = Assertion_Finder::has_false_variable($conditional);
            $true_pos = Assertion_Finder::has_true_variable($conditional);
            if ($false_pos === Assertion_Finder::ASSIGNMENT_TO_RIGHT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->left instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return Algebra::negate_formula(self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, !$inside_negation, $cache));
            }
            if ($false_pos === Assertion_Finder::ASSIGNMENT_TO_LEFT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->right instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return Algebra::negate_formula(self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, !$inside_negation, $cache));
            }
            if ($true_pos === Assertion_Finder::ASSIGNMENT_TO_RIGHT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->left instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, $inside_negation, $cache);
            }
            if ($true_pos === Assertion_Finder::ASSIGNMENT_TO_LEFT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Equal || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->right instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, $inside_negation, $cache);
            }
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Identical || $conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal) {
            $false_pos = Assertion_Finder::has_false_variable($conditional);
            $true_pos = Assertion_Finder::has_true_variable($conditional);
            if ($true_pos === Assertion_Finder::ASSIGNMENT_TO_RIGHT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->left instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return Algebra::negate_formula(self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, !$inside_negation, $cache));
            }
            if ($true_pos === Assertion_Finder::ASSIGNMENT_TO_LEFT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->right instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return Algebra::negate_formula(self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, !$inside_negation, $cache));
            }
            if ($false_pos === Assertion_Finder::ASSIGNMENT_TO_RIGHT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->left instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->left instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return self::get_formula($conditional_object_id, spl_object_id($conditional->left), $conditional->left, $this_class_name, $source, $codebase, $inside_negation, $cache);
            }
            if ($false_pos === Assertion_Finder::ASSIGNMENT_TO_LEFT && ($conditional instanceof Php_Parser\Node\Expr\Binary_Op\Not_Equal || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_And || $conditional->right instanceof Php_Parser\Node\Expr\Binary_Op\Boolean_Or || $conditional->right instanceof Php_Parser\Node\Expr\Boolean_Not)) {
                return self::get_formula($conditional_object_id, spl_object_id($conditional->right), $conditional->right, $this_class_name, $source, $codebase, $inside_negation, $cache);
            }
        }
        if ($conditional instanceof Php_Parser\Node\Expr\Cast\Bool_) {
            return self::get_formula($conditional_object_id, spl_object_id($conditional->expr), $conditional->expr, $this_class_name, $source, $codebase, $inside_negation, $cache);
        }
        $anded_assertions = null;
        if ($cache && $source instanceof Statements_Analyzer) {
            $anded_assertions = $source->node_data->get_assertions($conditional);
        }
        if ($anded_assertions === null) {
            $anded_assertions = Assertion_Finder::scrape_assertions($conditional, $this_class_name, $source, $codebase, $inside_negation, $cache);
            if ($cache && $source instanceof Statements_Analyzer) {
                $source->node_data->set_assertions($conditional, $anded_assertions);
            }
        }
        $clauses = [];
        foreach ($anded_assertions as $assertions) {
            foreach ($assertions as $var => $anded_types) {
                $redefined = false;
                if ($var[0] === '=') {
                    $var = substr($var, 1);
                    $redefined = true;
                }
                foreach ($anded_types as $orred_types) {
                    $mapped_orred_types = [];
                    foreach ($orred_types as $orred_type) {
                        $mapped_orred_types[(string) $orred_type] = $orred_type;
                    }
                    $clauses[] = new Clause([$var => $mapped_orred_types], $conditional_object_id, $creating_object_id, false, true, $orred_types[0]->has_equality(), $redefined ? [$var => true] : []);
                }
            }
        }
        if ($clauses) {
            return $clauses;
        }
        /** @psalm-suppress MixedOperand */
        $conditional_ref = '*' . $conditional->get_attribute('startFilePos') . ':' . $conditional->get_attribute('endFilePos');
        return [new Clause([$conditional_ref => ['truthy' => new Truthy()]], $conditional_object_id, $creating_object_id)];
    }
}