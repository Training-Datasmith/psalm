<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Reference_Constraint;
use Psalm\Issue\Invalid_Global;
use Psalm\Issue_Buffer;
use function is_string;
/**
 * @internal
 */
final class Global_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Global_ $stmt, Context $context, ?Context $global_context): void
    {
        if (!$context->collect_initializations && !$global_context) {
            Issue_Buffer::maybe_add(new Invalid_Global('Cannot use global scope here (unless this file is included from a non-global scope)', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_source()->get_suppressed_issues());
        }
        $codebase = $statements_analyzer->get_codebase();
        $source = $statements_analyzer->get_source();
        $function_storage = $source instanceof Function_Like_Analyzer ? $source->get_function_like_storage($statements_analyzer) : null;
        foreach ($stmt->vars as $var) {
            if (!$var instanceof Php_Parser\Node\Expr\Variable) {
                continue;
            }
            if (!is_string($var->name)) {
                continue;
            }
            $var_id = '$' . $var->name;
            $doc_comment = $stmt->get_doc_comment();
            $comment_type = null;
            if ($doc_comment) {
                $var_comments = Comment_Analyzer::get_var_comments($doc_comment, $statements_analyzer, $var);
                $comment_type = Comment_Analyzer::populate_var_types_from_docblock($var_comments, $var, $context, $statements_analyzer);
            }
            if ($comment_type) {
                $context->vars_in_scope[$var_id] = $comment_type;
                $context->vars_possibly_in_scope[$var_id] = true;
                $context->byref_constraints[$var_id] = new Reference_Constraint($comment_type);
            } else if ($var->name === 'argv' || $var->name === 'argc') {
                $context->vars_in_scope[$var_id] = Variable_Fetch_Analyzer::get_global_type($var_id, $codebase->analysis_php_version_id);
            } elseif (isset($function_storage->global_types[$var_id])) {
                $context->vars_in_scope[$var_id] = $function_storage->global_types[$var_id];
                $context->vars_possibly_in_scope[$var_id] = true;
            } else {
                $context->vars_in_scope[$var_id] = $global_context && $global_context->has_variable($var_id) ? $global_context->vars_in_scope[$var_id] : Variable_Fetch_Analyzer::get_global_type($var_id, $codebase->analysis_php_version_id);
                $context->vars_possibly_in_scope[$var_id] = true;
                $context->byref_constraints[$var_id] = new Reference_Constraint();
            }
            $assignment_node = Data_Flow_Node::get_for_assignment($var_id, new Code_Location($statements_analyzer, $var));
            $context->vars_in_scope[$var_id] = $context->vars_in_scope[$var_id]->set_parent_nodes([$assignment_node->id => $assignment_node]);
            $context->references_to_external_scope[$var_id] = true;
            if (isset($context->references_in_scope[$var_id])) {
                // Global shadows existing reference
                $context->decrement_reference_count($var_id);
                unset($context->references_in_scope[$var_id]);
            }
            $statements_analyzer->register_variable($var_id, new Code_Location($statements_analyzer, $var), $context->branch_point);
            $statements_analyzer->get_codebase()->analyzer->add_node_reference($statements_analyzer->get_file_path(), $var, $var_id);
            if ($global_context !== null && $global_context->has_variable($var_id)) {
                $global_context->referenced_globals[$var_id] = true;
            }
        }
    }
}