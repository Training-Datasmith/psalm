<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Method_Call_Prohibition_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Invalid_Clone;
use Psalm\Issue\Mixed_Clone;
use Psalm\Issue\Possibly_Invalid_Clone;
use Psalm\Issue_Buffer;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Template_Param;
use function array_merge;
use function array_pop;
/**
 * @internal
 */
final class Clone_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Clone_ $stmt, Context $context): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $codebase_methods = $codebase->methods;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->expr, $context) === false) {
            return false;
        }
        $location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $stmt_expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
        if ($stmt_expr_type) {
            $clone_type = $stmt_expr_type;
            $immutable_cloned = false;
            $invalid_clones = [];
            $mixed_clone = false;
            $possibly_valid = false;
            $atomic_types = $clone_type->get_atomic_types();
            while ($atomic_types) {
                $clone_type_part = array_pop($atomic_types);
                if ($clone_type_part instanceof T_Mixed) {
                    $mixed_clone = true;
                } elseif ($clone_type_part instanceof T_Object) {
                    $possibly_valid = true;
                } elseif ($clone_type_part instanceof T_Named_Object) {
                    if (!$codebase->classlikes->class_or_interface_exists($clone_type_part->value)) {
                        $invalid_clones[] = $clone_type_part->get_id();
                    } else {
                        $clone_method_id = new Method_Identifier($clone_type_part->value, '__clone');
                        $does_method_exist = $codebase_methods->method_exists($clone_method_id, $context->calling_method_id, $location);
                        $is_method_visible = Method_Analyzer::is_method_visible($clone_method_id, $context, $statements_analyzer->get_source());
                        if ($does_method_exist && !$is_method_visible) {
                            $invalid_clones[] = $clone_type_part->get_id();
                        } else {
                            Method_Call_Prohibition_Analyzer::analyze($codebase, $context, $clone_method_id, $statements_analyzer->get_namespace(), $location, $statements_analyzer->get_suppressed_issues());
                            $possibly_valid = true;
                            $immutable_cloned = true;
                        }
                    }
                } elseif ($clone_type_part instanceof T_Template_Param) {
                    $atomic_types = array_merge($atomic_types, $clone_type_part->as->get_atomic_types());
                } else {
                    if ($clone_type_part instanceof T_False && $clone_type->ignore_falsable_issues) {
                        continue;
                    }
                    if ($clone_type_part instanceof T_Null && $clone_type->ignore_nullable_issues) {
                        continue;
                    }
                    $invalid_clones[] = $clone_type_part->get_id();
                }
            }
            if ($mixed_clone) {
                Issue_Buffer::maybe_add(new Mixed_Clone('Cannot clone mixed', $location), $statements_analyzer->get_suppressed_issues());
            }
            if ($invalid_clones) {
                if ($possibly_valid) {
                    Issue_Buffer::maybe_add(new Possibly_Invalid_Clone('Cannot clone ' . $invalid_clones[0], $location), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Invalid_Clone('Cannot clone ' . $invalid_clones[0], $location), $statements_analyzer->get_suppressed_issues());
                }
                return true;
            }
            if ($immutable_cloned) {
                $stmt_expr_type = $stmt_expr_type->set_properties(['reference_free' => true, 'allow_mutations' => true]);
            }
            $statements_analyzer->node_data->set_type($stmt, $stmt_expr_type);
        }
        return true;
    }
}