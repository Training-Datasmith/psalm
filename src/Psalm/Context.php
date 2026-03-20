<?php

declare (strict_types=1);
namespace Psalm;

use InvalidArgumentException;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Clause;
use Psalm\Internal\Reference_Constraint;
use Psalm\Internal\Scope\Case_Scope;
use Psalm\Internal\Scope\Finally_Scope;
use Psalm\Internal\Scope\Loop_Scope;
use Psalm\Internal\Type\Assertion_Reconciler;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type\Atomic\Dependent_Type;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use RuntimeException;
use function array_keys;
use function array_search;
use function array_shift;
use function assert;
use function count;
use function in_array;
use function is_int;
use function json_encode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_contains;
use function strpos;
use function strtolower;
use const JSON_THROW_ON_ERROR;
final class Context
{
    /**
     * @var array<string, Union>
     */
    public array $vars_in_scope = [];
    /**
     * @var array<string, bool>
     */
    public array $vars_possibly_in_scope = [];
    /**
     * Keeps track of how many times a var_in_scope has been referenced. May not be set for all $vars_in_scope.
     *
     * @var array<string, int<0, max>>
     */
    public array $referenced_counts = [];
    /**
     * Maps references to referenced variables for the current scope.
     * With `$b = &$a`, this will contain `['$b' => '$a']`.
     *
     * All keys and values in this array are guaranteed to be set in $vars_in_scope.
     *
     * To check if a variable was passed or returned by reference, or
     * references an object property or array item, see Union::$by_ref.
     *
     * @var array<string, string>
     */
    public array $references_in_scope = [];
    /**
     * Set of references to variables in another scope. These references will be marked as used if they are assigned to.
     *
     * @var array<string, true>
     */
    public array $references_to_external_scope = [];
    /**
     * A set of globals that are referenced somewhere.
     *
     * @var array<string, true>
     */
    public array $referenced_globals = [];
    /**
     * A set of references that might still be in scope from a scope likely to cause confusion. This applies
     * to references set inside a loop or if statement, since it's easy to forget about PHP's weird scope
     * rules, and assigning to a reference will change the referenced variable rather than shadowing it.
     *
     * @var array<string, CodeLocation>
     */
    public array $references_possibly_from_confusing_scope = [];
    /**
     * Whether or not we're inside the conditional of an if/where etc.
     *
     * This changes whether or not the context is cloned
     */
    public bool $inside_conditional = false;
    /**
     * Whether or not we're inside an isset call
     *
     * Inside issets Psalm is more lenient about certain things
     */
    public bool $inside_isset = false;
    /**
     * Whether or not we're inside an unset call, where
     * we don't care about possibly undefined variables
     */
    public bool $inside_unset = false;
    /**
     * Whether or not we're inside a class_exists call, where
     * we don't care about possibly undefined classes
     */
    public bool $inside_class_exists = false;
    /**
     * Whether or not we're inside a function/method call
     */
    public bool $inside_call = false;
    /**
     * Whether or not we're inside any other situation that treats a variable as used
     */
    public bool $inside_general_use = false;
    /**
     * Whether or not we're inside a return expression
     */
    public bool $inside_return = false;
    /**
     * Whether or not we're inside a throw
     */
    public bool $inside_throw = false;
    /**
     * Whether or not we're inside an assignment
     */
    public bool $inside_assignment = false;
    /**
     * Whether or not we're inside a try block.
     */
    public bool $inside_try = false;
    public ?Code_Location $include_location = null;
    public ?string $parent = null;
    public bool $check_classes = true;
    public bool $check_variables = true;
    public bool $check_methods = true;
    public bool $check_consts = true;
    public bool $check_functions = true;
    /**
     * A list of classes checked with class_exists
     *
     * @var array<lowercase-string,true>
     */
    public array $phantom_classes = [];
    /**
     * A list of files checked with file_exists
     *
     * @var array<string,bool>
     */
    public array $phantom_files = [];
    /**
     * A list of clauses in Conjunctive Normal Form
     *
     * @var list<Clause>
     */
    public array $clauses = [];
    /**
     * A list of hashed clauses that have already been factored in
     *
     * @var list<string|int>
     */
    public array $reconciled_expression_clauses = [];
    /**
     * Whether or not to do a deep analysis and collect mutations to this context
     */
    public bool $collect_mutations = false;
    /**
     * Whether or not to do a deep analysis and collect initializations from private or final methods
     */
    public bool $collect_initializations = false;
    /**
     * Whether or not to do a deep analysis and collect initializations from public non-final methods
     */
    public bool $collect_nonprivate_initializations = false;
    /**
     * Stored to prevent re-analysing methods when checking for initialised properties
     *
     * @var array<string, bool>
     */
    public array $initialized_methods = [];
    /**
     * @var array<string, Union>
     */
    public array $constants = [];
    /**
     * Whether or not to track exceptions
     */
    public bool $collect_exceptions = false;
    /**
     * A list of variables that have been referenced in conditionals
     *
     * @var array<string, bool>
     */
    public array $cond_referenced_var_ids = [];
    /**
     * A list of variables that have been passed by reference (where we know their type)
     *
     * @var array<string, ReferenceConstraint>
     */
    public array $byref_constraints = [];
    /**
     * A list of vars that have been assigned to
     *
     * @var array<string, int>
     */
    public array $assigned_var_ids = [];
    /**
     * A list of vars that have been may have been assigned to
     *
     * @var array<string, bool>
     */
    public array $possibly_assigned_var_ids = [];
    /**
     * A list of classes or interfaces that may have been thrown
     *
     * @var array<string, array<array-key, CodeLocation>>
     */
    public array $possibly_thrown_exceptions = [];
    public bool $is_global = false;
    /**
     * @var array<string, bool|int>
     */
    public array $protected_var_ids = [];
    /**
     * If we've branched from the main scope, a byte offset for where that branch happened
     */
    public ?int $branch_point = null;
    /**
     * What does break mean in this context?
     *
     * 'loop' means we're breaking out of a loop,
     * 'switch' means we're breaking out of a switch
     *
     * @var list<'loop'|'switch'>
     */
    public array $break_types = [];
    public bool $inside_loop = false;
    public ?Loop_Scope $loop_scope = null;
    public ?Case_Scope $case_scope = null;
    public ?Finally_Scope $finally_scope = null;
    public ?Context $if_body_context = null;
    public bool $strict_types = false;
    public ?string $calling_function_id = null;
    /**
     * @var lowercase-string|null
     */
    public ?string $calling_method_id = null;
    public bool $inside_negation = false;
    public bool $ignore_variable_property = false;
    public bool $ignore_variable_method = false;
    public bool $pure = false;
    /**
     * @var bool
     * Set by @psalm-immutable
     */
    public bool $mutation_free = false;
    /**
     * @var bool
     * Set by @psalm-external-mutation-free
     */
    public bool $external_mutation_free = false;
    public bool $error_suppressing = false;
    public bool $has_returned = false;
    /**
     * @var array<string, true>
     */
    public array $parent_remove_vars = [];
    /** @internal */
    public function __construct(
        /**
         * @var string|null
         * The name of the current class. Null if outside a class.
         */
        public ?string $self = null
    )
    {
    }
    public function __destruct()
    {
        $this->case_scope = null;
    }
    /**
     * Updates the parent context, looking at the changes within a block and then applying those changes, where
     * necessary, to the parent context
     *
     * @param  bool        $has_leaving_statements   whether or not the parent scope is abandoned between
     *                                               $start_context and $end_context
     * @param  array<string, bool>  $updated_vars
     */
    public function update(Context $start_context, Context $end_context, bool $has_leaving_statements, array $vars_to_update, array &$updated_vars): void
    {
        foreach ($start_context->vars_in_scope as $var_id => $old_type) {
            // this is only true if there was some sort of type negation
            if (in_array($var_id, $vars_to_update, true)) {
                // if we're leaving, we're effectively deleting the possibility of the if types
                $new_type = !$has_leaving_statements && $end_context->has_variable($var_id) ? $end_context->vars_in_scope[$var_id] : null;
                $existing_type = $this->vars_in_scope[$var_id] ?? null;
                if (!$existing_type) {
                    if ($new_type) {
                        $this->vars_in_scope[$var_id] = $new_type;
                        $updated_vars[$var_id] = true;
                    }
                    continue;
                }
                // if the type changed within the block of statements, process the replacement
                // also never allow ourselves to remove all types from a union
                if ((!$new_type || !$old_type->equals($new_type)) && ($new_type || count($existing_type->get_atomic_types()) > 1)) {
                    $existing_type = $existing_type->get_builder()->substitute($old_type, $new_type);
                    if ($new_type && $new_type->from_docblock) {
                        $existing_type = $existing_type->set_from_docblock();
                    }
                    $existing_type = $existing_type->freeze();
                    $updated_vars[$var_id] = true;
                }
                $this->vars_in_scope[$var_id] = $existing_type;
            }
        }
    }
    /**
     * Updates the list of possible references from a confusing scope,
     * such as a reference created in an if that might later be reused.
     */
    public function update_references_possibly_from_confusing_scope(Context $confusing_scope_context, Statements_Analyzer $statements_analyzer): void
    {
        $references = $confusing_scope_context->references_in_scope + $confusing_scope_context->references_to_external_scope;
        foreach ($references as $reference_id => $_) {
            if (!isset($this->references_in_scope[$reference_id]) && !isset($this->references_to_external_scope[$reference_id]) && $reference_location = $statements_analyzer->get_first_appearance($reference_id)) {
                $this->references_possibly_from_confusing_scope[$reference_id] = $reference_location;
            }
        }
        $this->references_possibly_from_confusing_scope += $confusing_scope_context->references_possibly_from_confusing_scope;
    }
    /**
     * @param  array<string, Union> $new_vars_in_scope
     * @return array<string, Union>
     */
    public function get_redefined_vars(array $new_vars_in_scope, bool $include_new_vars = false): array
    {
        $redefined_vars = [];
        foreach ($this->vars_in_scope as $var_id => $this_type) {
            if (!isset($new_vars_in_scope[$var_id])) {
                if ($include_new_vars) {
                    $redefined_vars[$var_id] = $this_type;
                }
                continue;
            }
            $new_type = $new_vars_in_scope[$var_id];
            if (!$this_type->equals($new_type, true, !($this_type->propagate_parent_nodes || $new_type->propagate_parent_nodes))) {
                $redefined_vars[$var_id] = $this_type;
            }
        }
        return $redefined_vars;
    }
    /**
     * @return list<string>
     */
    public static function get_new_or_updated_var_ids(Context $original_context, Context $new_context): array
    {
        $redefined_var_ids = [];
        foreach ($new_context->vars_in_scope as $var_id => $context_type) {
            if (!isset($original_context->vars_in_scope[$var_id]) || ($original_context->assigned_var_ids[$var_id] ?? 0) !== ($new_context->assigned_var_ids[$var_id] ?? 0) || !$original_context->vars_in_scope[$var_id]->equals($context_type)) {
                $redefined_var_ids[] = $var_id;
            }
        }
        return $redefined_var_ids;
    }
    public function remove(string $remove_var_id, bool $remove_descendents = true): void
    {
        if (isset($this->vars_in_scope[$remove_var_id])) {
            $existing_type = $this->vars_in_scope[$remove_var_id];
            unset($this->vars_in_scope[$remove_var_id]);
            if ($remove_descendents) {
                $this->remove_descendents($remove_var_id, $existing_type);
            }
        }
        $this->remove_possible_reference($remove_var_id);
        unset($this->vars_possibly_in_scope[$remove_var_id]);
    }
    /**
     * Remove a variable from the context which might be a reference to another variable, or
     * referenced by another variable. Leaves the variable as possibly-in-scope, unlike remove().
     */
    public function remove_possible_reference(string $remove_var_id): void
    {
        if (isset($this->referenced_counts[$remove_var_id]) && $this->referenced_counts[$remove_var_id] > 0) {
            // If a referenced variable goes out of scope, we need to update the references.
            // All of the references to this variable are still references to the same value,
            // so we pick the first one and make the rest of the references point to it.
            $references = [];
            foreach ($this->references_in_scope as $reference => $referenced) {
                if ($referenced === $remove_var_id) {
                    $references[] = $reference;
                    unset($this->references_in_scope[$reference]);
                }
            }
            assert(!empty($references));
            $first_reference = array_shift($references);
            if (!empty($references)) {
                $this->referenced_counts[$first_reference] = count($references);
                foreach ($references as $reference) {
                    $this->references_in_scope[$reference] = $first_reference;
                }
            }
        }
        if (isset($this->references_in_scope[$remove_var_id])) {
            $this->decrement_reference_count($remove_var_id);
        }
        unset($this->vars_in_scope[$remove_var_id], $this->cond_referenced_var_ids[$remove_var_id], $this->referenced_counts[$remove_var_id], $this->references_in_scope[$remove_var_id], $this->references_to_external_scope[$remove_var_id]);
    }
    /**
     * Decrement the reference count of the variable that $ref_id is referring to. This needs to
     * be done before $ref_id is changed to no longer reference its currently referenced variable,
     * for example by unsetting, reassigning to another reference, or being shadowed by a global.
     */
    public function decrement_reference_count(string $ref_id): void
    {
        if (!isset($this->referenced_counts[$this->references_in_scope[$ref_id]])) {
            throw new InvalidArgumentException("{$ref_id} is not a reference");
        }
        $reference_count = $this->referenced_counts[$this->references_in_scope[$ref_id]];
        if ($reference_count < 1) {
            throw new RuntimeException("Incorrect referenced count found");
        }
        --$reference_count;
        $this->referenced_counts[$this->references_in_scope[$ref_id]] = $reference_count;
    }
    /**
     * @param Clause[]             $clauses
     * @param array<string, bool>  $changed_var_ids
     * @return array{list<Clause>, list<Clause>}
     * @psalm-pure
     */
    public static function remove_reconciled_clauses(array $clauses, array $changed_var_ids): array
    {
        $included_clauses = [];
        $rejected_clauses = [];
        foreach ($clauses as $c) {
            if ($c->wedge) {
                $included_clauses[] = $c;
                continue;
            }
            foreach ($c->possibilities as $key => $_) {
                if (isset($changed_var_ids[$key])) {
                    $rejected_clauses[] = $c;
                    continue 2;
                }
            }
            $included_clauses[] = $c;
        }
        return [$included_clauses, $rejected_clauses];
    }
    /**
     * @param  Clause[]               $clauses
     * @return list<Clause>
     */
    public static function filter_clauses(string $remove_var_id, array $clauses, ?Union $new_type = null, ?Statements_Analyzer $statements_analyzer = null): array
    {
        $new_type_string = $new_type ? $new_type->get_id() : '';
        $clauses_to_keep = [];
        foreach ($clauses as $clause) {
            $clause = $clause->calculate_negation();
            $quoted_remove_var_id = preg_quote($remove_var_id, '/');
            foreach ($clause->possibilities as $var_id => $_) {
                if (preg_match('/' . $quoted_remove_var_id . '[\]\[\-]/', $var_id)) {
                    break 2;
                }
            }
            if (!isset($clause->possibilities[$remove_var_id]) || count($clause->possibilities[$remove_var_id]) === 1 && array_keys($clause->possibilities[$remove_var_id])[0] === $new_type_string) {
                $clauses_to_keep[] = $clause;
            } elseif ($statements_analyzer && $new_type && !$new_type->has_mixed()) {
                $type_changed = false;
                // if the clause contains any possibilities that would be altered
                // by the new type
                foreach ($clause->possibilities[$remove_var_id] as $assertion) {
                    // if we're negating a type, we generally don't need the clause anymore
                    if ($assertion->is_negation()) {
                        $type_changed = true;
                        break;
                    }
                    $result_type = Assertion_Reconciler::reconcile($assertion, $new_type, null, $statements_analyzer, false, [], null, [], $failed_reconciliation);
                    if ($result_type->get_id() !== $new_type_string) {
                        $type_changed = true;
                        break;
                    }
                }
                if (!$type_changed) {
                    $clauses_to_keep[] = $clause;
                }
            }
        }
        return $clauses_to_keep;
    }
    public function remove_var_from_conflicting_clauses(string $remove_var_id, ?Union $new_type = null, ?Statements_Analyzer $statements_analyzer = null): void
    {
        $this->clauses = self::filter_clauses($remove_var_id, $this->clauses, $new_type, $statements_analyzer);
        $this->parent_remove_vars[$remove_var_id] = true;
    }
    /**
     * This method is used after assignments to variables to remove any existing
     * items in $vars_in_scope that are now made redundant by an update to some data
     */
    public function remove_descendents(string $remove_var_id, Union $existing_type, ?Union $new_type = null, ?Statements_Analyzer $statements_analyzer = null): void
    {
        $this->remove_var_from_conflicting_clauses($remove_var_id, $existing_type->has_mixed() || $new_type && $existing_type->from_docblock !== $new_type->from_docblock ? null : $new_type, $statements_analyzer);
        foreach ($this->vars_in_scope as $var_id => &$type) {
            if (preg_match('/' . preg_quote($remove_var_id, '/') . '[\]\[\-]/', $var_id)) {
                $this->remove($var_id, false);
            }
            $builder = null;
            foreach ($type->get_atomic_types() as $atomic_type) {
                if ($atomic_type instanceof Dependent_Type && $atomic_type->get_var_id() === $remove_var_id) {
                    $builder ??= $type->get_builder();
                    $builder->add_type($atomic_type->get_replacement());
                }
            }
            if ($builder) {
                $type = $builder->freeze();
            }
        }
    }
    public function remove_mutable_object_vars(bool $methods_only = false): void
    {
        $vars_to_remove = [];
        foreach ($this->vars_in_scope as $var_id => $type) {
            if ($type->has_mutations && (str_contains($var_id, '->') || str_contains($var_id, '::')) && (!$methods_only || strpos($var_id, '()'))) {
                $vars_to_remove[] = $var_id;
            }
        }
        if (!$vars_to_remove) {
            return;
        }
        foreach ($vars_to_remove as $var_id) {
            $this->remove($var_id, false);
        }
        $clauses_to_keep = [];
        foreach ($this->clauses as $clause) {
            $abandon_clause = false;
            foreach (array_keys($clause->possibilities) as $key) {
                if ((str_contains($key, '->') || str_contains($key, '::')) && (!$methods_only || strpos($key, '()'))) {
                    $abandon_clause = true;
                    break;
                }
            }
            if (!$abandon_clause) {
                $clauses_to_keep[] = $clause;
            }
        }
        $this->clauses = $clauses_to_keep;
    }
    public function update_checks(Context $op_context): void
    {
        $this->check_classes = $this->check_classes && $op_context->check_classes;
        $this->check_variables = $this->check_variables && $op_context->check_variables;
        $this->check_methods = $this->check_methods && $op_context->check_methods;
        $this->check_functions = $this->check_functions && $op_context->check_functions;
        $this->check_consts = $this->check_consts && $op_context->check_consts;
    }
    public function is_phantom_class(string $class_name): bool
    {
        return isset($this->phantom_classes[strtolower($class_name)]);
    }
    public function has_variable(string $var_name): bool
    {
        if (!$var_name) {
            return false;
        }
        $stripped_var = (string) preg_replace('/(->|\[).*$/', '', $var_name, 1);
        if ($stripped_var !== '$this' || $var_name !== $stripped_var) {
            $this->cond_referenced_var_ids[$var_name] = true;
        }
        return isset($this->vars_in_scope[$var_name]);
    }
    public function get_scope_summary(): string
    {
        $summary = [];
        foreach ($this->vars_possibly_in_scope as $k => $_) {
            $summary[$k] = true;
        }
        foreach ($this->vars_in_scope as $k => $v) {
            $summary[$k] = $v->get_id();
        }
        return json_encode($summary, JSON_THROW_ON_ERROR);
    }
    public function define_globals(): void
    {
        $globals = [
            // not sure why this is declared here again, see VariableFetchAnalyzer
            '$argv' => new Union([Type::get_non_empty_list_atomic(Type::get_string()), new T_Null()], ['ignore_nullable_issues' => true]),
            '$argc' => new Union([new T_Int_Range(1, null), new T_Null()], ['ignore_nullable_issues' => true]),
        ];
        $config = Config::get_instance();
        foreach ($config->globals as $global_id => $type_string) {
            $globals[$global_id] = Type::parse_string($type_string);
        }
        foreach ($globals as $global_id => $type) {
            $this->vars_in_scope[$global_id] = $type;
            $this->vars_possibly_in_scope[$global_id] = true;
        }
    }
    public function merge_exceptions(Context $other_context): void
    {
        foreach ($other_context->possibly_thrown_exceptions as $possibly_thrown_exception => $codelocations) {
            foreach ($codelocations as $hash => $codelocation) {
                $this->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
            }
        }
    }
    public function is_suppressing_exceptions(Statements_Analyzer $statements_analyzer): bool
    {
        if (!$this->collect_exceptions) {
            return true;
        }
        $issue_type = $this->is_global ? 'UncaughtThrowInGlobalScope' : 'MissingThrowsDocblock';
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        $suppressed_issue_position = array_search($issue_type, $suppressed_issues, true);
        if ($suppressed_issue_position !== false) {
            if (is_int($suppressed_issue_position)) {
                $file = $statements_analyzer->get_file_analyzer()->get_file_path();
                Issue_Buffer::add_used_suppressions([$file => [$suppressed_issue_position => true]]);
            }
            return true;
        }
        return false;
    }
    public function merge_function_exceptions(Function_Like_Storage $function_storage, Code_Location $codelocation): void
    {
        $hash = $codelocation->get_hash();
        foreach ($function_storage->throws as $possibly_thrown_exception => $_) {
            $this->possibly_thrown_exceptions[$possibly_thrown_exception][$hash] = $codelocation;
        }
    }
    public function inside_use(): bool
    {
        return $this->inside_assignment || $this->inside_return || $this->inside_call || $this->inside_general_use || $this->inside_conditional || $this->inside_throw || $this->inside_isset;
    }
}