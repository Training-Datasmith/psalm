<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use AssertionError;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Atomic_Method_Call_Analysis_Result;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method\Atomic_Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Issue\Direct_Constructor_Call;
use Psalm\Issue\Invalid_Method_Call;
use Psalm\Issue\Invalid_Scope;
use Psalm\Issue\Null_Reference;
use Psalm\Issue\Possibly_False_Reference;
use Psalm\Issue\Possibly_Invalid_Method_Call;
use Psalm\Issue\Possibly_Null_Reference;
use Psalm\Issue\Possibly_Undefined_Method;
use Psalm\Issue\Too_Few_Arguments;
use Psalm\Issue\Too_Many_Arguments;
use Psalm\Issue\Undefined_Interface_Method;
use Psalm\Issue\Undefined_Magic_Method;
use Psalm\Issue\Undefined_Method;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_merge;
use function array_reduce;
use function count;
use function is_string;
use function strtolower;
/**
 * @internal
 */
final class Method_Call_Analyzer extends Call_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Method_Call $stmt, Context $context, bool $real_method_call = true, ?Template_Result $template_result = null): bool
    {
        $was_inside_call = $context->inside_call;
        $context->inside_call = true;
        $existing_stmt_var_type = null;
        if (!$real_method_call) {
            $existing_stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var);
        }
        if ($existing_stmt_var_type) {
            $statements_analyzer->node_data->set_type($stmt->var, $existing_stmt_var_type);
        } elseif (Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context) === false) {
            $context->inside_call = $was_inside_call;
            return false;
        }
        if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
            $context->inside_call = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context) === false) {
                $context->inside_call = $was_inside_call;
                return false;
            }
        }
        $context->inside_call = $was_inside_call;
        if ($stmt->var instanceof Php_Parser\Node\Expr\Variable) {
            if (is_string($stmt->var->name) && $stmt->var->name === 'this' && !$statements_analyzer->get_fqcln()) {
                if (Issue_Buffer::accepts(new Invalid_Scope('Use of $this in non-class context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues())) {
                    return false;
                }
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier && strtolower($stmt->name->name) === '__construct') {
                Issue_Buffer::maybe_add(new Direct_Constructor_Call('Constructors should not be called directly', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
        $lhs_var_id = Expression_Identifier::get_extended_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $class_type = $lhs_var_id && $context->has_variable($lhs_var_id) ? $context->vars_in_scope[$lhs_var_id] : null;
        if ($stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var)) {
            $class_type = $stmt_var_type;
        } elseif (!$class_type) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        }
        if ($class_type && $stmt->name instanceof Php_Parser\Node\Identifier && ($class_type->is_null() || $class_type->is_void())) {
            return !Issue_Buffer::accepts(new Null_Reference('Cannot call method ' . $stmt->name->name . ' on null value', new Code_Location($statements_analyzer->get_source(), $stmt->name)), $statements_analyzer->get_suppressed_issues());
        }
        if ($class_type && $stmt->name instanceof Php_Parser\Node\Identifier && $class_type->is_nullable() && !$class_type->ignore_nullable_issues && !($stmt->name->name === 'offsetGet' && $context->inside_isset) && !self::has_nullsafe($stmt->var)) {
            Issue_Buffer::maybe_add(new Possibly_Null_Reference('Cannot call method ' . $stmt->name->name . ' on possibly null value', new Code_Location($statements_analyzer->get_source(), $stmt->name)), $statements_analyzer->get_suppressed_issues());
        }
        if ($class_type && $stmt->name instanceof Php_Parser\Node\Identifier && $class_type->is_falsable() && !$class_type->ignore_falsable_issues) {
            Issue_Buffer::maybe_add(new Possibly_False_Reference('Cannot call method ' . $stmt->name->name . ' on possibly false value', new Code_Location($statements_analyzer->get_source(), $stmt->name)), $statements_analyzer->get_suppressed_issues());
        }
        $codebase = $statements_analyzer->get_codebase();
        $source = $statements_analyzer->get_source();
        if (!$class_type) {
            $class_type = Type::get_mixed();
        }
        $lhs_types = $class_type->get_atomic_types();
        foreach ($lhs_types as $k => $lhs_type_part) {
            if ($lhs_type_part instanceof T_Conditional) {
                $lhs_types = array_merge($lhs_types, $lhs_type_part->if_type->get_atomic_types(), $lhs_type_part->else_type->get_atomic_types());
                unset($lhs_types[$k]);
            }
        }
        $result = new Atomic_Method_Call_Analysis_Result();
        $possible_new_class_types = [];
        foreach ($lhs_types as $lhs_type_part) {
            Atomic_Method_Call_Analyzer::analyze($statements_analyzer, $stmt, $codebase, $context, $class_type, $lhs_type_part, $lhs_type_part instanceof T_Named_Object || $lhs_type_part instanceof T_Template_Param ? $lhs_type_part : null, false, $lhs_var_id, $result, $template_result);
            if ($lhs_var_id !== null && isset($context->vars_in_scope[$lhs_var_id]) && ($possible_new_class_type = $context->vars_in_scope[$lhs_var_id]) instanceof Union && !$possible_new_class_type->equals($class_type)) {
                $possible_new_class_types[] = $context->vars_in_scope[$lhs_var_id];
            }
        }
        if (!$stmt->is_first_class_callable() && !$stmt->get_args() && $lhs_var_id && $stmt->name instanceof Php_Parser\Node\Identifier) {
            if ($codebase->config->memoize_method_calls || $result->can_memoize) {
                $method_var_id = $lhs_var_id . '->' . strtolower($stmt->name->name) . '()';
                if (isset($context->vars_in_scope[$method_var_id])) {
                    $result->return_type = $context->vars_in_scope[$method_var_id];
                } elseif ($result->return_type !== null) {
                    $context->vars_in_scope[$method_var_id] = $result->return_type->set_properties(['has_mutations' => false]);
                }
                if ($result->can_memoize) {
                    $stmt->set_attribute('memoizable', true);
                }
            }
        }
        if (count($possible_new_class_types) > 0) {
            $class_type = array_reduce($possible_new_class_types, static fn(?Union $type_1, Union $type_2): Union => Type::combine_union_types($type_1, $type_2, $codebase));
        }
        if ($result->invalid_method_call_types) {
            $invalid_class_type = $result->invalid_method_call_types[0];
            if ($result->has_valid_method_call_type || $result->has_mixed_method_call) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Method_Call('Cannot call method on possible ' . $invalid_class_type . ' variable ' . $lhs_var_id, new Code_Location($source, $stmt->name)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Method_Call('Cannot call method on ' . $invalid_class_type . ' variable ' . $lhs_var_id, new Code_Location($source, $stmt->name)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($result->non_existent_magic_method_ids) {
            if ($context->check_methods) {
                Issue_Buffer::maybe_add(new Undefined_Magic_Method('Magic method ' . $result->non_existent_magic_method_ids[0] . ' does not exist', new Code_Location($source, $stmt->name), $result->non_existent_magic_method_ids[0]), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($result->non_existent_class_method_ids) {
            if ($context->check_methods) {
                if ($result->existent_method_ids || $result->has_mixed_method_call) {
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Method('Method ' . $result->non_existent_class_method_ids[0] . ' does not exist', new Code_Location($source, $stmt->name), $result->non_existent_class_method_ids[0]), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Undefined_Method('Method ' . $result->non_existent_class_method_ids[0] . ' does not exist', new Code_Location($source, $stmt->name), $result->non_existent_class_method_ids[0]), $statements_analyzer->get_suppressed_issues());
                }
            }
            return true;
        }
        if ($result->non_existent_interface_method_ids) {
            if ($context->check_methods) {
                if ($result->existent_method_ids || $result->has_mixed_method_call) {
                    Issue_Buffer::maybe_add(new Possibly_Undefined_Method('Method ' . $result->non_existent_interface_method_ids[0] . ' does not exist', new Code_Location($source, $stmt->name), $result->non_existent_interface_method_ids[0]), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Undefined_Interface_Method('Method ' . $result->non_existent_interface_method_ids[0] . ' does not exist', new Code_Location($source, $stmt->name), $result->non_existent_interface_method_ids[0]), $statements_analyzer->get_suppressed_issues());
                }
            }
            return true;
        }
        if ($result->too_many_arguments && $result->too_many_arguments_method_ids) {
            $error_method_id = $result->too_many_arguments_method_ids[0];
            Issue_Buffer::maybe_add(new Too_Many_Arguments('Too many arguments for method ' . $error_method_id . ' - saw ' . count($stmt->get_args()), new Code_Location($source, $stmt->name), (string) $error_method_id), $statements_analyzer->get_suppressed_issues());
        }
        if ($result->too_few_arguments && $result->too_few_arguments_method_ids) {
            $error_method_id = $result->too_few_arguments_method_ids[0];
            Issue_Buffer::maybe_add(new Too_Few_Arguments('Too few arguments for method ' . $error_method_id . ' saw ' . count($stmt->get_args()), new Code_Location($source, $stmt->name), (string) $error_method_id), $statements_analyzer->get_suppressed_issues());
        }
        $stmt_type = $result->return_type;
        if ($stmt_type) {
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            if ($stmt_type->is_never()) {
                $context->has_returned = true;
            }
        }
        if ($result->returns_by_ref) {
            if (!$stmt_type) {
                $stmt_type = Type::get_mixed();
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            }
            $stmt_type = $stmt_type->set_by_ref($result->returns_by_ref);
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations && $stmt_type) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id(), $stmt);
        }
        if (!$result->existent_method_ids) {
            if ($stmt->is_first_class_callable()) {
                return true;
            }
            return self::check_method_args(null, $stmt->get_args(), new Template_Result([], []), $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer);
        }
        // if we called a method on this nullable variable, remove the nullable status here
        // because any further calls must have worked
        if ($lhs_var_id && !$class_type->is_mixed() && $result->has_valid_method_call_type && !$result->has_mixed_method_call && !$result->invalid_method_call_types && ($class_type->from_docblock || $class_type->is_nullable()) && $real_method_call) {
            $types = $class_type->get_atomic_types();
            foreach ($types as $key => &$type) {
                if (!$type instanceof T_Named_Object && !$type instanceof T_Object && !$type instanceof T_Conditional) {
                    unset($types[$key]);
                } else {
                    $type = $type->set_from_docblock(false);
                }
            }
            if (!$types) {
                throw new AssertionError("We must have some types here!");
            }
            $context->remove_var_from_conflicting_clauses($lhs_var_id, null, $statements_analyzer);
            $class_type = $class_type->get_builder()->set_types($types);
            $class_type->from_docblock = false;
            $context->vars_in_scope[$lhs_var_id] = $class_type->freeze();
        }
        return true;
    }
    public static function has_nullsafe(Php_Parser\Node\Expr $expr): bool
    {
        if ($expr instanceof Php_Parser\Node\Expr\Method_Call || $expr instanceof Php_Parser\Node\Expr\Property_Fetch) {
            return self::has_nullsafe($expr->var);
        }
        return $expr instanceof Php_Parser\Node\Expr\Nullsafe_Method_Call || $expr instanceof Php_Parser\Node\Expr\Nullsafe_Property_Fetch;
    }
}