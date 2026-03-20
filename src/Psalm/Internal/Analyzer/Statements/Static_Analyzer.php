<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Reference_Constraint;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Impure_Static_Variable;
use Psalm\Issue\Reference_Constraint_Violation;
use Psalm\Issue_Buffer;
use Psalm\Type;
use function is_string;
/**
 * @internal
 */
final class Static_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Static_ $stmt, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if ($context->mutation_free) {
            Issue_Buffer::maybe_add(new Impure_Static_Variable('Cannot use a static variable in a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        foreach ($stmt->vars as $var) {
            if (!is_string($var->var->name)) {
                continue;
            }
            $var_id = '$' . $var->var->name;
            $doc_comment = $stmt->get_doc_comment();
            $comment_type = null;
            if ($doc_comment) {
                $var_comments = Comment_Analyzer::get_var_comments($doc_comment, $statements_analyzer, $var->var);
                $comment_type = Comment_Analyzer::populate_var_types_from_docblock($var_comments, $var->var, $context, $statements_analyzer);
            }
            if ($comment_type) {
                $context->byref_constraints[$var_id] = new Reference_Constraint($comment_type);
            }
            if ($var->default) {
                if (Expression_Analyzer::analyze($statements_analyzer, $var->default, $context) === false) {
                    return;
                }
                if ($comment_type && ($var_default_type = $statements_analyzer->node_data->get_type($var->default)) && !Union_Type_Comparator::is_contained_by($codebase, $var_default_type, $comment_type)) {
                    Issue_Buffer::maybe_add(new Reference_Constraint_Violation($var_id . ' of type ' . $comment_type->get_id() . ' cannot be assigned type ' . $var_default_type->get_id(), new Code_Location($statements_analyzer, $var)));
                }
            }
            if ($context->check_variables) {
                $context->vars_in_scope[$var_id] = $comment_type ?: Type::get_mixed();
                $context->vars_possibly_in_scope[$var_id] = true;
                $context->assigned_var_ids[$var_id] = (int) $stmt->get_attribute('startFilePos');
                $statements_analyzer->byref_uses[$var_id] = true;
                $location = new Code_Location($statements_analyzer, $var);
                $statements_analyzer->register_variable($var_id, $location, $context->branch_point);
            }
        }
    }
}