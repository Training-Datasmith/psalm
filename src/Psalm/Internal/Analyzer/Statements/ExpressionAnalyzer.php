<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Closure_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Array_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assertion_Finder;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Binary_Op_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Bitwise_Not_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Boolean_Not_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Function_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\New_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Cast_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Class_Const_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Clone_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Empty_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Encapsulated_String_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Eval_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Exit_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Array_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Instance_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Static_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Inc_Dec_Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Include_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Instanceof_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Isset_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Magic_Const_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Match_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Nullsafe_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Print_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Ternary_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Throw_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Unary_Plus_Minus_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Yield_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Yield_From_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Issue\Risky_Truthy_Falsy_Comparison;
use Psalm\Issue\Unrecognized_Expression;
use Psalm\Issue\Unsupported_Reference_Usage;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Func_Call;
use Psalm\Node\Scalar\Virtual_Interpolated_String;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Name;
use Psalm\Plugin\Event_Handler\Event\After_Expression_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Expression_Analysis_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use function count;
use function in_array;
use function strtolower;
/**
 * @internal
 */
final class Expression_Analyzer
{
    /**
     * @param bool $assigned_to_reference This is set to true when the expression being analyzed
     *                                    here is being assigned to another variable by reference.
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context, bool $array_assignment = false, ?Context $global_context = null, ?Php_Parser\Node\Stmt $from_stmt = null, ?Template_Result $template_result = null, bool $assigned_to_reference = false): bool
    {
        if (self::dispatch_before_expression_analysis($stmt, $context, $statements_analyzer) === false) {
            return false;
        }
        $codebase = $statements_analyzer->get_codebase();
        if (self::handle_expression($statements_analyzer, $stmt, $context, $array_assignment, $global_context, $from_stmt, $template_result, $assigned_to_reference) === false) {
            return false;
        }
        if (!$context->inside_conditional && ($stmt instanceof Php_Parser\Node\Expr\Binary_Op || $stmt instanceof Php_Parser\Node\Expr\Instanceof_ || $stmt instanceof Php_Parser\Node\Expr\Assign || $stmt instanceof Php_Parser\Node\Expr\Boolean_Not || $stmt instanceof Php_Parser\Node\Expr\Empty_ || $stmt instanceof Php_Parser\Node\Expr\Isset_ || $stmt instanceof Php_Parser\Node\Expr\Func_Call)) {
            $assertions = $statements_analyzer->node_data->get_assertions($stmt);
            if ($assertions === null) {
                $negate = $context->inside_negation;
                while ($stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
                    $stmt = $stmt->expr;
                    $negate = !$negate;
                }
                Assertion_Finder::scrape_assertions($stmt, $context->self, $statements_analyzer, $codebase, $negate, true, false);
            }
        }
        if (self::dispatch_after_expression_analysis($stmt, $context, $statements_analyzer) === false) {
            return false;
        }
        return true;
    }
    public static function check_risky_truthy_falsy_comparison(Type\Union $type, Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt): void
    {
        if (count($type->get_atomic_types()) > 1) {
            $has_truthy_or_falsy_exclusive_type = false;
            $both_types = $type->get_builder();
            foreach ($both_types->get_atomic_types() as $key => $atomic_type) {
                if ($atomic_type->is_truthy() || $atomic_type->is_falsy() || $atomic_type instanceof T_Bool) {
                    $both_types->remove_type($key);
                    $has_truthy_or_falsy_exclusive_type = true;
                }
            }
            if ($has_truthy_or_falsy_exclusive_type) {
                $both_types = $both_types->freeze();
                Issue_Buffer::maybe_add(new Risky_Truthy_Falsy_Comparison('Operand of type ' . $type->get_id() . ' contains ' . 'type' . (count($both_types->get_atomic_types()) > 1 ? 's' : '') . ' ' . $both_types->get_id() . ', which can be falsy and truthy. ' . 'This can cause possibly unexpected behavior. Use strict comparison instead.', new Code_Location($statements_analyzer, $stmt), $type->get_id()), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    /**
     * @param bool $assigned_to_reference This is set to true when the expression being analyzed
     *                                    here is being assigned to another variable by reference.
     */
    private static function handle_expression(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context, bool $array_assignment, ?Context $global_context, ?Php_Parser\Node\Stmt $from_stmt, ?Template_Result $template_result = null, bool $assigned_to_reference = false): bool
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Variable) {
            return Variable_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context, false, null, $array_assignment, false, $assigned_to_reference);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Assign) {
            return self::analyze_assignment($statements_analyzer, $stmt, $context, $from_stmt);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Assign_Op) {
            return Assignment_Analyzer::analyze_assignment_operation($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Method_Call) {
            return Method_Call_Analyzer::analyze($statements_analyzer, $stmt, $context, true, $template_result);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Static_Call) {
            return Static_Call_Analyzer::analyze($statements_analyzer, $stmt, $context, $template_result);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Const_Fetch) {
            Const_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\String_) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_string($stmt->value));
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const) {
            Magic_Const_Analyzer::analyze($statements_analyzer, $stmt, $context);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Int_) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_int(false, $stmt->value));
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Float_) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_float($stmt->value));
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Unary_Minus || $stmt instanceof Php_Parser\Node\Expr\Unary_Plus) {
            return Unary_Plus_Minus_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Isset_) {
            Isset_Analyzer::analyze($statements_analyzer, $stmt, $context);
            $statements_analyzer->node_data->set_type($stmt, Type::get_bool());
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Class_Const_Fetch) {
            return Class_Const_Analyzer::analyze_fetch($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch) {
            return Instance_Property_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context, $array_assignment);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Static_Property_Fetch) {
            return Static_Property_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Bitwise_Not) {
            return Bitwise_Not_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Binary_Op) {
            return Binary_Op_Analyzer::analyze($statements_analyzer, $stmt, $context, 0, $from_stmt !== null);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Post_Inc || $stmt instanceof Php_Parser\Node\Expr\Post_Dec || $stmt instanceof Php_Parser\Node\Expr\Pre_Inc || $stmt instanceof Php_Parser\Node\Expr\Pre_Dec) {
            return Inc_Dec_Expression_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\New_) {
            return New_Analyzer::analyze($statements_analyzer, $stmt, $context, $template_result);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_) {
            return Array_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Scalar\Interpolated_String) {
            return Encapsulated_String_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Func_Call) {
            return Function_Call_Analyzer::analyze($statements_analyzer, $stmt, $context, $template_result);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Ternary) {
            return Ternary_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Boolean_Not) {
            return Boolean_Not_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Empty_) {
            Empty_Analyzer::analyze($statements_analyzer, $stmt, $context);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Closure || $stmt instanceof Php_Parser\Node\Expr\Arrow_Function) {
            return Closure_Analyzer::analyze_expression($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Array_Dim_Fetch) {
            return Array_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Cast) {
            return Cast_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Clone_) {
            return Clone_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Instanceof_) {
            return Instanceof_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Exit_) {
            return Exit_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Include_) {
            return Include_Analyzer::analyze($statements_analyzer, $stmt, $context, $global_context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Eval_) {
            Eval_Analyzer::analyze($statements_analyzer, $stmt, $context);
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Assign_Ref) {
            if (!Assignment_Analyzer::analyze_assignment_ref($statements_analyzer, $stmt, $context, $from_stmt)) {
                Issue_Buffer::maybe_add(new Unsupported_Reference_Usage("This reference cannot be analyzed by Psalm", new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                // Analyze as if it were a normal assignment and just pretend the reference doesn't exist
                return self::analyze_assignment($statements_analyzer, $stmt, $context, $from_stmt);
            }
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Error_Suppress) {
            $context->error_suppressing = true;
            if (self::analyze($statements_analyzer, $stmt->expr, $context) === false) {
                return false;
            }
            $context->error_suppressing = false;
            $expr_type = $statements_analyzer->node_data->get_type($stmt->expr);
            if ($expr_type) {
                $statements_analyzer->node_data->set_type($stmt, $expr_type);
            }
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Shell_Exec) {
            $concat = new Virtual_Interpolated_String($stmt->parts, $stmt->get_attributes());
            $virtual_call = new Virtual_Func_Call(new Virtual_Name(['shell_exec']), [new Virtual_Arg($concat)], $stmt->get_attributes());
            return self::handle_expression($statements_analyzer, $virtual_call, $context, $array_assignment, $global_context, $from_stmt, $template_result, $assigned_to_reference);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Print_) {
            $was_inside_call = $context->inside_call;
            $context->inside_call = true;
            if (Print_Analyzer::analyze($statements_analyzer, $stmt, $context) === false) {
                $context->inside_call = $was_inside_call;
                return false;
            }
            $context->inside_call = $was_inside_call;
            return true;
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Yield_) {
            return Yield_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Yield_From) {
            return Yield_From_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        $codebase = $statements_analyzer->get_codebase();
        $analysis_php_version_id = $codebase->analysis_php_version_id;
        if ($stmt instanceof Php_Parser\Node\Expr\Match_ && $analysis_php_version_id >= 80000) {
            return Match_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Throw_) {
            return Throw_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if (($stmt instanceof Php_Parser\Node\Expr\Nullsafe_Property_Fetch || $stmt instanceof Php_Parser\Node\Expr\Nullsafe_Method_Call) && $analysis_php_version_id >= 80000) {
            return Nullsafe_Analyzer::analyze($statements_analyzer, $stmt, $context);
        }
        if ($stmt instanceof Php_Parser\Node\Expr\Error) {
            // do nothing
            return true;
        }
        Issue_Buffer::maybe_add(new Unrecognized_Expression('Psalm does not understand ' . $stmt::class . ' for PHP ' . $codebase->get_major_analysis_php_version() . '.' . $codebase->get_minor_analysis_php_version(), new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        return false;
    }
    public static function is_mock(string $fq_class_name): bool
    {
        return in_array(strtolower($fq_class_name), Config::get_instance()->get_mock_classes(), true);
    }
    /**
     * @param PhpParser\Node\Expr\Assign|PhpParser\Node\Expr\AssignRef $stmt
     */
    private static function analyze_assignment(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Context $context, ?Php_Parser\Node\Stmt $from_stmt): bool
    {
        $assignment_type = Assignment_Analyzer::analyze($statements_analyzer, $stmt->var, $stmt->expr, null, $context, $stmt->get_doc_comment() ?? $from_stmt?->get_doc_comment(), [], !$from_stmt ? $stmt : null);
        if ($assignment_type === null) {
            return false;
        }
        if (!$from_stmt) {
            $statements_analyzer->node_data->set_type($stmt, $assignment_type);
        }
        return true;
    }
    private static function dispatch_before_expression_analysis(Php_Parser\Node\Expr $expr, Context $context, Statements_Analyzer $statements_analyzer): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $event = new Before_Expression_Analysis_Event($expr, $context, $statements_analyzer, $codebase, []);
        if ($codebase->config->event_dispatcher->dispatch_before_expression_analysis($event) === false) {
            return false;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations !== []) {
            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
        }
        return null;
    }
    private static function dispatch_after_expression_analysis(Php_Parser\Node\Expr $expr, Context $context, Statements_Analyzer $statements_analyzer): ?bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $event = new After_Expression_Analysis_Event($expr, $context, $statements_analyzer, $codebase, []);
        if ($codebase->config->event_dispatcher->dispatch_after_expression_analysis($event) === false) {
            return false;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations !== []) {
            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
        }
        return null;
    }
}