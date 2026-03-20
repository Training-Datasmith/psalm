<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Instance_Property_Assignment_Analyzer as AssignmentAnalyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Impure_Method_Call;
use Psalm\Issue\Unused_Method_Call;
use Psalm\Issue_Buffer;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
/**
 * @internal
 */
final class Method_Call_Purity_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Method_Call $stmt, ?string $lhs_var_id, string $cased_method_id, Method_Identifier $method_id, Method_Storage $method_storage, Class_Like_Storage $class_storage, Context $context, Config $config, Atomic_Method_Call_Analysis_Result $result): void
    {
        $method_pure_compatible = $method_storage->external_mutation_free && $statements_analyzer->node_data->is_pure_compatible($stmt->var);
        if ($context->pure && !$method_storage->mutation_free && !$method_pure_compatible) {
            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a non-mutation-free method ' . $cased_method_id . ' from a pure context', new Code_Location($statements_analyzer, $stmt->name)), $statements_analyzer->get_suppressed_issues());
        } elseif ($context->mutation_free && !$method_storage->mutation_free && !$method_pure_compatible) {
            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method ' . $cased_method_id . ' from a mutation-free context', new Code_Location($statements_analyzer, $stmt->name)), $statements_analyzer->get_suppressed_issues());
        } elseif ($context->external_mutation_free && !$method_storage->mutation_free && $method_id->fq_class_name !== $context->self && !$method_pure_compatible) {
            Issue_Buffer::maybe_add(new Impure_Method_Call('Cannot call a possibly-mutating method ' . $cased_method_id . ' from a mutation-free context', new Code_Location($statements_analyzer, $stmt->name)), $statements_analyzer->get_suppressed_issues());
        } elseif (($method_storage->mutation_free || $method_storage->external_mutation_free && ($stmt->var->get_attribute('external_mutation_free', false) || $stmt->var->get_attribute('pure', false))) && !$context->inside_unset) {
            if ($method_storage->mutation_free && (!$method_storage->mutation_free_inferred || $method_storage->final || $method_storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) && ($method_storage->immutable || $config->remember_property_assignments_after_call)) {
                if ($context->inside_conditional && !$method_storage->assertions && !$method_storage->if_true_assertions) {
                    $stmt->set_attribute('memoizable', true);
                    if ($method_storage->immutable) {
                        $stmt->set_attribute('pure', true);
                    }
                }
                $result->can_memoize = true;
            }
            if ($codebase->find_unused_variables && !$context->inside_conditional && !$context->inside_general_use && !$context->inside_throw) {
                if (!$context->inside_assignment && !$context->inside_call && !$context->inside_return && !$method_storage->assertions && !$method_storage->if_true_assertions && !$method_storage->if_false_assertions && !$method_storage->throws) {
                    Issue_Buffer::maybe_add(new Unused_Method_Call('The call to ' . $cased_method_id . ' is not used', new Code_Location($statements_analyzer, $stmt->name), (string) $method_id), $statements_analyzer->get_suppressed_issues());
                } elseif (!$method_storage->mutation_free_inferred) {
                    $stmt->set_attribute('pure', true);
                }
            }
        }
        if ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations && !$method_storage->mutation_free && !$method_pure_compatible) {
            $statements_analyzer->get_source()->inferred_has_mutation = true;
            $statements_analyzer->get_source()->inferred_impure = true;
        }
        if (!$config->remember_property_assignments_after_call && !$method_storage->mutation_free && !$method_pure_compatible) {
            $context->remove_mutable_object_vars();
        } elseif ($method_storage->this_property_mutations) {
            if (!$method_pure_compatible) {
                $context->remove_mutable_object_vars(true);
            }
            foreach ($method_storage->this_property_mutations as $name => $_) {
                $mutation_var_id = $lhs_var_id . '->' . $name;
                $this_property_didnt_exist = $lhs_var_id === '$this' && isset($context->vars_in_scope[$mutation_var_id]) && !isset($class_storage->declaring_property_ids[$name]);
                if ($this_property_didnt_exist) {
                    unset($context->vars_in_scope[$mutation_var_id]);
                } else {
                    $new_type = Assignment_Analyzer::get_expanded_property_type($codebase, $class_storage->name, $name, $class_storage) ?? Type::get_mixed();
                    $context->vars_in_scope[$mutation_var_id] = $new_type;
                    $context->possibly_assigned_var_ids[$mutation_var_id] = true;
                }
            }
        }
    }
}