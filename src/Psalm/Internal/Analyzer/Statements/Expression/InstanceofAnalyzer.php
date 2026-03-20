<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use function implode;
use function in_array;
use function strtolower;
/**
 * @internal
 */
final class Instanceof_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Instanceof_ $stmt, Context $context): bool
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            $context->inside_general_use = $was_inside_general_use;
            return false;
        }
        $context->inside_general_use = $was_inside_general_use;
        if ($stmt->class instanceof Php_Parser\Node\Expr) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->class, $context) === false) {
                return false;
            }
        } elseif (!in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
            if ($context->check_classes) {
                $codebase = $statements_analyzer->get_codebase();
                $fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $statements_analyzer->get_aliases());
                if ($codebase->store_node_types && $fq_class_name && !$context->collect_initializations && !$context->collect_mutations) {
                    $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $codebase->classlikes->class_or_interface_or_enum_exists($fq_class_name) ? $fq_class_name : '*' . ($stmt->class instanceof Php_Parser\Node\Name\Fully_Qualified ? '\\' : $statements_analyzer->get_namespace() . '-') . implode('\\', $stmt->class->get_parts()));
                }
                if (!isset($context->phantom_classes[strtolower($fq_class_name)])) {
                    if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer->get_source(), $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues()) === false) {
                        return false;
                    }
                }
                if ($codebase->alter_code) {
                    $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id);
                }
            }
        }
        $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
        return true;
    }
}