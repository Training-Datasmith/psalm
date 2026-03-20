<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Attribute;
use Override;
use Php_Parser;
use Php_Parser\Node\Expr\Arrow_Function;
use Php_Parser\Node\Expr\Closure;
use Php_Parser\Node\Param;
use Php_Parser\Node\Stmt\Class_Method;
use Php_Parser\Node\Stmt\Function_;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Unresolvable_Constant_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Function_Like\Return_Type_Analyzer;
use Psalm\Internal\Analyzer\Function_Like\Return_Type_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Function_Call_Return_Type_Fetcher;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\File_Manipulation\Function_Docblock_Manipulator;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Php_Visitor\Node_Counter_Visitor;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Invalid_Docblock_Param_Name;
use Psalm\Issue\Invalid_Override;
use Psalm\Issue\Invalid_Param_Default;
use Psalm\Issue\Invalid_Throw;
use Psalm\Issue\Method_Signature_Mismatch;
use Psalm\Issue\Mismatching_Docblock_Param_Type;
use Psalm\Issue\Missing_Closure_Param_Type;
use Psalm\Issue\Missing_Override_Attribute;
use Psalm\Issue\Missing_Param_Type;
use Psalm\Issue\Missing_Throws_Docblock;
use Psalm\Issue\Reference_Constraint_Violation;
use Psalm\Issue\Reserved_Word;
use Psalm\Issue\Unresolvable_Constant;
use Psalm\Issue\Unused_Closure_Param;
use Psalm\Issue\Unused_Docblock_Param;
use Psalm\Issue\Unused_Param;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Stmt\Virtual_While;
use Psalm\Plugin\Event_Handler\Event\After_Function_Like_Analysis_Event;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Function_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_combine;
use function array_diff_key;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_search;
use function array_values;
use function count;
use function end;
use function in_array;
use function is_string;
use function krsort;
use function mb_strpos;
use function md5;
use function microtime;
use function reset;
use function str_starts_with;
use function strpos;
use function strtolower;
use function substr;
use const SORT_NUMERIC;
/**
 * @internal
 * @template-covariant TFunction as Closure|Function_|ClassMethod|ArrowFunction
 */
abstract class Function_Like_Analyzer extends Source_Analyzer
{
    protected Codebase $codebase;
    /**
     * @var array<string>
     */
    protected array $suppressed_issues;
    protected bool $is_static = false;
    /**
     * @var ?array<string, Union>
     */
    protected ?array $return_vars_in_scope = [];
    /**
     * @var ?array<string, bool>
     */
    protected ?array $return_vars_possibly_in_scope = [];
    private ?Union $local_return_type = null;
    /**
     * @var array<string, bool>
     */
    protected static array $no_effects_hashes = [];
    public bool $track_mutations = false;
    public bool $inferred_impure = false;
    public bool $inferred_has_mutation = false;
    /**
     * Holds param nodes for functions with func_get_args calls
     *
     * @var array<string, DataFlowNode>
     */
    public array $param_nodes = [];
    /**
     * @param TFunction $function
     */
    public function __construct(protected Closure|Function_|Class_Method|Arrow_Function $function, Source_Analyzer $source, protected Function_Like_Storage $storage)
    {
        $this->source = $source;
        $this->suppressed_issues = $source->get_suppressed_issues();
        $this->codebase = $source->get_codebase();
    }
    /**
     * @param bool          $add_mutations  whether or not to add mutations to this method
     * @param array<string, Union> $byref_vars
     * @param-out array<string, Union> $byref_vars
     * @return false|null
     * @psalm-suppress PossiblyUnusedReturnValue unused but seems important
     * @psalm-suppress ComplexMethod Unavoidably complex
     */
    public function analyze(Context $context, Node_Data_Provider $type_provider, ?Context $global_context = null, bool $add_mutations = false, array &$byref_vars = []): ?bool
    {
        $storage = $this->storage;
        $function_stmts = $this->function->get_stmts() ?: [];
        if ($this->function instanceof Arrow_Function && isset($function_stmts[0]) && $function_stmts[0] instanceof Php_Parser\Node\Stmt\Return_ && $function_stmts[0]->expr) {
            $function_stmts[0]->set_attributes($function_stmts[0]->expr->get_attributes());
        }
        if ($global_context) {
            foreach ($global_context->constants as $const_name => $var_type) {
                if (!$context->has_variable($const_name)) {
                    $context->vars_in_scope[$const_name] = $var_type;
                }
            }
        }
        $codebase = $this->codebase;
        $project_analyzer = $this->get_project_analyzer();
        if ($codebase->track_unused_suppressions && !isset($storage->suppressed_issues[0])) {
            if (count($storage->suppressed_issues) === 1 || !in_array("UnusedPsalmSuppress", $storage->suppressed_issues)) {
                foreach ($storage->suppressed_issues as $offset => $issue_name) {
                    Issue_Buffer::add_unused_suppression($storage->location !== null ? $storage->location->file_path : $this->get_file_path(), $offset, $issue_name);
                }
            }
        }
        foreach ($storage->docblock_issues as $docblock_issue) {
            Issue_Buffer::maybe_add($docblock_issue);
        }
        $function_information = $this->get_function_information($context, $codebase, $type_provider, $storage, $add_mutations);
        if ($function_information === null) {
            return null;
        }
        [$real_method_id, $method_id, $appearing_class_storage, $hash, $cased_method_id, $overridden_method_ids] = $function_information;
        $this->suppressed_issues = $this->get_source()->get_suppressed_issues() + $storage->suppressed_issues;
        if ($appearing_class_storage) {
            $this->suppressed_issues += $appearing_class_storage->suppressed_issues;
        }
        if (($storage instanceof Method_Storage || $storage instanceof Function_Storage) && $storage->is_static) {
            $this->is_static = true;
        }
        $statements_analyzer = new Statements_Analyzer($this, $type_provider);
        $byref_uses = [];
        if ($this instanceof Closure_Analyzer && $this->function instanceof Closure) {
            foreach ($this->function->uses as $use) {
                if (!is_string($use->var->name)) {
                    continue;
                }
                $use_var_id = '$' . $use->var->name;
                $use_location = new Code_Location($this, $use);
                $use_assignment = null;
                if ($statements_analyzer->data_flow_graph) {
                    $use_assignment = Data_Flow_Node::get_for_assignment($use_var_id, $use_location);
                    $statements_analyzer->data_flow_graph->add_node($use_assignment);
                    $context->vars_in_scope[$use_var_id] = $context->vars_in_scope[$use_var_id]->add_parent_nodes([$use_assignment->id => $use_assignment]);
                }
                if ($use->by_ref) {
                    $byref_uses[$use_var_id] = true;
                    if ($statements_analyzer->data_flow_graph && $use_assignment) {
                        $statements_analyzer->data_flow_graph->add_path($use_assignment, new Data_Flow_Node('closure-use', 'closure use', null), 'closure-use');
                    }
                } else {
                    $statements_analyzer->register_variable($use_var_id, $use_location, null);
                }
            }
            $statements_analyzer->set_by_ref_uses($byref_uses);
        }
        if ($storage->template_types) {
            foreach ($storage->template_types as $param_name => $_) {
                $fq_classlike_name = Type::get_fqcln_from_string($param_name, $this->get_aliases());
                if ($codebase->class_or_interface_exists($fq_classlike_name)) {
                    Issue_Buffer::maybe_add(new Reserved_Word('Cannot use ' . $param_name . ' as template name since the class already exists', new Code_Location($this, $this->function), 'resource'), $this->get_suppressed_issues());
                }
            }
        }
        $template_types = $storage->template_types;
        if ($appearing_class_storage && $appearing_class_storage->template_types) {
            $template_types = array_merge($template_types ?: [], $appearing_class_storage->template_types);
        }
        $params = $storage->params;
        if ($codebase->alter_code) {
            $this->alter_params($codebase, $storage, $params, $context);
        }
        foreach ($codebase->methods_to_rename as $original_method_id => $new_method_name) {
            if ($this instanceof Method_Analyzer && strtolower((string) $this->get_method_id()) === $original_method_id) {
                $file_manipulations = [new File_Manipulation((int) $this->function->name->get_attribute('startFilePos'), (int) $this->function->name->get_attribute('endFilePos') + 1, $new_method_name)];
                File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
            }
        }
        if ($storage instanceof Method_Storage && $method_id instanceof Method_Identifier && $overridden_method_ids) {
            $params = $codebase->methods->get_method_params($method_id, $this);
        }
        $check_stmts = $this->process_params($statements_analyzer, $storage, $cased_method_id, $params, array_values($this->function->params), $context, (bool) $template_types);
        if ($byref_uses) {
            $ref_context = clone $context;
            $var = '$__tmp_byref_closure_if__' . (int) $this->function->get_attribute('startFilePos');
            $ref_context->vars_in_scope[$var] = Type::get_bool();
            $var = new Virtual_Variable(substr($var, 1));
            $virtual_while = new Virtual_While($var, $function_stmts);
            $statements_analyzer->analyze([$virtual_while], $ref_context);
            foreach ($byref_uses as $var_id => $_) {
                $byref_vars[$var_id] = $ref_context->vars_in_scope[$var_id];
                $context->vars_in_scope[$var_id] = $ref_context->vars_in_scope[$var_id];
            }
        }
        if ($storage->pure) {
            $context->pure = true;
        }
        if ($storage->mutation_free && $cased_method_id && !strpos($cased_method_id, '__construct') && !($storage instanceof Method_Storage && $storage->mutation_free_inferred)) {
            $context->mutation_free = true;
        }
        if ($storage instanceof Method_Storage && $storage->external_mutation_free && !$storage->mutation_free_inferred) {
            $context->external_mutation_free = true;
        }
        foreach ($storage->unused_docblock_parameters as $param_name => $param_location) {
            if ($storage->has_undertyped_native_parameters) {
                Issue_Buffer::maybe_add(new Invalid_Docblock_Param_Name('Incorrect param name $' . $param_name . ' in docblock for ' . $cased_method_id, $param_location));
            } elseif ($codebase->find_unused_code) {
                Issue_Buffer::maybe_add(new Unused_Docblock_Param('Docblock parameter $' . $param_name . ' in docblock for ' . $cased_method_id . ' does not have a counterpart in signature parameter list', $param_location));
            }
        }
        if ($storage->signature_return_type && $storage->signature_return_type_location) {
            [$start, $end] = $storage->signature_return_type_location->get_selection_bounds();
            $codebase->analyzer->add_offset_reference($this->get_file_path(), $start, $end, (string) $storage->signature_return_type);
        }
        if ($storage instanceof Method_Storage && $storage->location && !$storage->allow_named_arg_calls) {
            foreach ($overridden_method_ids as $overridden_method_id) {
                $overridden_storage = $codebase->methods->get_storage($overridden_method_id);
                if ($overridden_storage->allow_named_arg_calls) {
                    Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $method_id . ' should accept named arguments ' . ' as ' . $overridden_method_id . ' does', $storage->location));
                }
            }
        }
        if (Return_Type_Analyzer::check_return_type($this->function, $project_analyzer, $this, $storage, $context) === false) {
            $check_stmts = false;
        }
        if (!$check_stmts) {
            return false;
        }
        if ($context->collect_initializations || $context->collect_mutations) {
            $statements_analyzer->add_suppressed_issues(['DocblockTypeContradiction', 'InvalidReturnStatement', 'RedundantCondition', 'RedundantConditionGivenDocblockType', 'TypeDoesNotContainNull', 'TypeDoesNotContainType', 'LoopInvalidation']);
            if ($context->collect_initializations) {
                $statements_analyzer->add_suppressed_issues(['UndefinedInterfaceMethod', 'UndefinedMethod', 'PossiblyUndefinedMethod']);
            }
        } elseif ($cased_method_id && strpos($cased_method_id, '__destruct')) {
            $statements_analyzer->add_suppressed_issues(['InvalidPropertyAssignmentValue', 'PossiblyNullPropertyAssignmentValue']);
        }
        $time = microtime(true);
        $project_analyzer = $statements_analyzer->get_project_analyzer();
        if ($codebase->alter_code && (isset($project_analyzer->get_issues_to_fix()['MissingPureAnnotation']) || isset($project_analyzer->get_issues_to_fix()['MissingImmutableAnnotation']))) {
            $this->track_mutations = true;
        } elseif ($this->function instanceof Closure || $this->function instanceof Arrow_Function) {
            $this->track_mutations = true;
        }
        if ($this->function instanceof Arrow_Function && (!$storage->return_type || $storage->return_type->is_never())) {
            // ArrowFunction perform a return implicitly so if the return type is never, we have to suppress the error
            // note: the never can only come from phpdoc. PHP will refuse short closures with never in signature
            $statements_analyzer->add_suppressed_issues(['NoValue']);
        }
        $statements_analyzer->analyze($function_stmts, $context, $global_context, true);
        if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingPureAnnotation']) && !$this->inferred_impure && ($this->function instanceof Function_ || $this->function instanceof Class_Method) && $storage->params && !$overridden_method_ids) {
            $manipulator = Function_Docblock_Manipulator::get_for_function($project_analyzer, $this->source->get_file_path(), $this->function);
            $yield_types = [];
            $inferred_return_types = Return_Type_Collector::get_return_types($codebase, $type_provider, $function_stmts, $yield_types, true);
            $inferred_return_type = $inferred_return_types ? Type::combine_union_type_array($inferred_return_types, $codebase) : Type::get_void();
            if (!$inferred_return_type->is_void() && !$inferred_return_type->is_false() && !$inferred_return_type->is_null() && !$inferred_return_type->is_single_int_literal() && !$inferred_return_type->is_single_string_literal() && !$inferred_return_type->is_true() && !$inferred_return_type->is_empty_array()) {
                $manipulator->make_pure();
            }
        }
        if ($this->inferred_has_mutation && $context->self) {
            $this->codebase->analyzer->add_mutable_class($context->self);
        }
        if (!$context->collect_initializations && !$context->collect_mutations && $project_analyzer->debug_performance && $cased_method_id) {
            $traverser = new Php_Parser\Node_Traverser();
            $node_counter = new Node_Counter_Visitor();
            $traverser->add_visitor($node_counter);
            $traverser->traverse($function_stmts);
            if ($node_counter->count > 5) {
                $time_taken = microtime(true) - $time;
                $codebase->analyzer->add_function_timing($cased_method_id, $time_taken / $node_counter->count);
            }
        }
        $final_actions = Scope_Analyzer::get_control_actions($this->function->get_stmts() ?: [], null, []);
        if ($final_actions !== [Scope_Analyzer::ACTION_END]) {
            $this->examine_param_types($statements_analyzer, $context, $codebase);
        }
        foreach ($params as $function_param) {
            // only complain if there's no type defined by a parent type
            if (!$function_param->type && $function_param->location) {
                if ($this->function instanceof Closure || $this->function instanceof Arrow_Function) {
                    Issue_Buffer::maybe_add(new Missing_Closure_Param_Type('Parameter $' . $function_param->name . ' has no provided type', $function_param->location), $storage->suppressed_issues + $this->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Missing_Param_Type('Parameter $' . $function_param->name . ' has no provided type', $function_param->location), $storage->suppressed_issues + $this->get_suppressed_issues());
                }
            }
        }
        if ($this->function instanceof Closure || $this->function instanceof Arrow_Function) {
            $this->verify_return_type($function_stmts, $statements_analyzer, $storage->return_type, $this->source->get_fqcln(), $storage->return_type_location, $context->has_returned, $global_context && ($global_context->inside_call || $global_context->inside_return));
            $closure_yield_types = [];
            $closure_return_types = Return_Type_Collector::get_return_types($codebase, $type_provider, $function_stmts, $closure_yield_types, true);
            $closure_return_type = $closure_return_types ? Type::combine_union_type_array($closure_return_types, $codebase) : Type::get_void();
            $closure_yield_type = $closure_yield_types ? Type::combine_union_type_array($closure_yield_types, $codebase) : null;
            if ($closure_yield_type) {
                $closure_return_type = $closure_yield_type;
            }
            if ($function_type = $statements_analyzer->node_data->get_type($this->function)) {
                /**
                 * @var TClosure
                 */
                $closure_atomic = $function_type->get_single_atomic();
                $new_closure_return_type = $closure_atomic->return_type;
                if ($storage->return_type === $storage->signature_return_type && (!$storage->return_type || $storage->return_type->has_mixed() || Union_Type_Comparator::is_contained_by($codebase, $closure_return_type, $storage->return_type))) {
                    $new_closure_return_type = $closure_return_type;
                }
                $new_closure_is_pure = !$this->inferred_impure;
                $statements_analyzer->node_data->set_type($this->function, new Union([new T_Closure($closure_atomic->value, $closure_atomic->params, $new_closure_return_type, $new_closure_is_pure, $closure_atomic->byref_uses, $closure_atomic->extra_types, $closure_atomic->from_docblock)]));
            }
        }
        if ($codebase->collect_references && !$context->collect_initializations && !$context->collect_mutations && $codebase->find_unused_variables && $context->check_variables) {
            $this->check_param_references($statements_analyzer, $storage, $appearing_class_storage, $context);
        }
        foreach ($storage->throws as $expected_exception => $_) {
            if (($expected_exception === 'self' || $expected_exception === 'static') && $context->self) {
                $expected_exception = $context->self;
            }
            if (isset($storage->throw_locations[$expected_exception])) {
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $expected_exception, $storage->throw_locations[$expected_exception], $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(false, false, true, true, true))) {
                    $input_type = new Union([new T_Named_Object($expected_exception)]);
                    $container_type = new Union([new T_Named_Object('Exception'), new T_Named_Object('Throwable')]);
                    if (!Union_Type_Comparator::is_contained_by($codebase, $input_type, $container_type)) {
                        Issue_Buffer::maybe_add(new Invalid_Throw('Class supplied for @throws ' . $expected_exception . ' does not implement Throwable', $storage->throw_locations[$expected_exception], $expected_exception), $statements_analyzer->get_suppressed_issues());
                    }
                    if ($codebase->alter_code) {
                        $codebase->classlikes->handle_docblock_type_in_migration($codebase, $this, $input_type, $storage->throw_locations[$expected_exception], $context->calling_method_id);
                    }
                }
            }
        }
        $missing_throws_docblock_errors = [];
        foreach ($statements_analyzer->get_uncaught_throws($context) as $possibly_thrown_exception => $codelocations) {
            $is_expected = false;
            foreach ($storage->throws as $expected_exception => $_) {
                if ($expected_exception === $possibly_thrown_exception || $codebase->class_or_interface_exists($possibly_thrown_exception) && ($codebase->interface_extends($possibly_thrown_exception, $expected_exception) || $codebase->class_extends_or_implements($possibly_thrown_exception, $expected_exception))) {
                    $is_expected = true;
                    break;
                }
            }
            if (!$is_expected) {
                $missing_docblock_exception = new T_Named_Object($possibly_thrown_exception);
                $missing_throws_docblock_errors[] = $missing_docblock_exception->to_namespaced_string($this->source->get_namespace(), $this->source->get_aliased_classes_flipped(), $this->source->get_fqcln(), true);
                foreach ($codelocations as $codelocation) {
                    // issues are suppressed in ThrowAnalyzer, CallAnalyzer, etc.
                    Issue_Buffer::maybe_add(new Missing_Throws_Docblock($possibly_thrown_exception . ' is thrown but not caught - please either catch' . ' or add a @throws annotation', $codelocation, $possibly_thrown_exception));
                }
            }
        }
        if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingThrowsDocblock'])) {
            $manipulator = Function_Docblock_Manipulator::get_for_function($project_analyzer, $this->source->get_file_path(), $this->function);
            $manipulator->add_throws_docblock($missing_throws_docblock_errors);
        }
        if ($codebase->taint_flow_graph && $this->function instanceof Class_Method && $cased_method_id && $storage->specialize_call && isset($context->vars_in_scope['$this']) && $context->vars_in_scope['$this']->parent_nodes) {
            $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $storage->location);
            $codebase->taint_flow_graph->add_node($method_source);
            foreach ($context->vars_in_scope['$this']->parent_nodes as $parent_node) {
                $codebase->taint_flow_graph->add_path($parent_node, $method_source, '$this');
            }
        }
        // Class methods are analyzed deferred, therefor it's required to
        // add taint sources additionally on analyze not only on call
        if ($codebase->taint_flow_graph && $this->function instanceof Class_Method && $cased_method_id) {
            $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $storage->location);
            Function_Call_Return_Type_Fetcher::taint_using_storage($storage, $codebase->taint_flow_graph, $method_source);
        }
        if ($add_mutations) {
            if ($this->return_vars_in_scope !== null) {
                $context->vars_in_scope = Type_Analyzer::combine_keyed_types($context->vars_in_scope, $this->return_vars_in_scope);
            }
            if ($this->return_vars_possibly_in_scope !== null) {
                $context->vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$this->return_vars_possibly_in_scope];
            }
            foreach ($context->vars_in_scope as $var => $_) {
                if (!str_starts_with($var, '$this->') && $var !== '$this') {
                    $context->remove_possible_reference($var);
                }
            }
            foreach ($context->vars_possibly_in_scope as $var => $_) {
                if (!str_starts_with($var, '$this->') && $var !== '$this') {
                    unset($context->vars_possibly_in_scope[$var]);
                }
            }
            if ($hash && $real_method_id && $this instanceof Method_Analyzer && !$context->collect_initializations) {
                $new_hash = md5($real_method_id . '::' . $context->get_scope_summary());
                if ($new_hash === $hash) {
                    self::$no_effects_hashes[$hash] = true;
                }
            }
        }
        $event = new After_Function_Like_Analysis_Event($this->function, $storage, $this, $codebase, [], $type_provider, $context);
        if ($codebase->config->event_dispatcher->dispatch_after_function_like_analysis($event) === false) {
            return false;
        }
        $file_manipulations = $event->get_file_replacements();
        if ($file_manipulations) {
            File_Manipulation_Buffer::add($this->get_file_path(), $file_manipulations);
        }
        Attributes_Analyzer::analyze($this, $context, $storage, $this->function->attr_groups, $storage instanceof Method_Storage ? Attribute::TARGET_METHOD : Attribute::TARGET_FUNCTION, $storage->suppressed_issues + $this->get_suppressed_issues());
        return null;
    }
    private function check_param_references(Statements_Analyzer $statements_analyzer, Function_Like_Storage $storage, ?Class_Like_Storage $class_storage, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $unused_params = $this->detect_unused_parameters($statements_analyzer, $storage, $context);
        if (!$storage instanceof Method_Storage || !$storage->cased_name || $storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
            $last_unused_argument_position = $this->detect_previous_unused_argument_position($storage, count($storage->params) - 1);
            // Sort parameters in reverse order so that we can start from the end of parameters
            krsort($unused_params, SORT_NUMERIC);
            foreach ($unused_params as $unused_param_position => $unused_param_code_location) {
                $unused_param_var_name = $storage->params[$unused_param_position]->name;
                $unused_param_message = 'Param ' . $unused_param_var_name . ' is never referenced in this method';
                // Remove the key as we already report the issue
                unset($unused_params[$unused_param_position]);
                // Do not report unused required parameters
                if ($unused_param_position !== $last_unused_argument_position) {
                    break;
                }
                $last_unused_argument_position = $this->detect_previous_unused_argument_position($storage, $unused_param_position - 1);
                if ($this instanceof Closure_Analyzer) {
                    Issue_Buffer::maybe_add(new Unused_Closure_Param($unused_param_message, $unused_param_code_location), $this->get_suppressed_issues());
                    continue;
                }
                Issue_Buffer::maybe_add(new Unused_Param($unused_param_message, $unused_param_code_location), $this->get_suppressed_issues());
            }
        }
        if ($storage instanceof Method_Storage && $this instanceof Method_Analyzer && $class_storage && $storage->cased_name && $storage->visibility !== Class_Like_Analyzer::VISIBILITY_PRIVATE) {
            $method_id_lc = strtolower((string) $this->get_method_id());
            foreach ($storage->params as $i => $_) {
                if (!isset($unused_params[$i])) {
                    $codebase->file_reference_provider->add_method_param_use($method_id_lc, $i, $method_id_lc);
                    $method_name_lc = strtolower($storage->cased_name);
                    if (!isset($class_storage->overridden_method_ids[$method_name_lc])) {
                        continue;
                    }
                    foreach ($class_storage->overridden_method_ids[$method_name_lc] as $parent_method_id) {
                        $codebase->file_reference_provider->add_method_param_use(strtolower((string) $parent_method_id), $i, $method_id_lc);
                    }
                }
            }
        }
    }
    /**
     * @param list<FunctionLikeParameter> $params
     * @param list<Param> $param_stmts
     */
    private function process_params(Statements_Analyzer $statements_analyzer, Function_Like_Storage $storage, ?string $cased_method_id, array $params, array $param_stmts, Context $context, bool $has_template_types): bool
    {
        $check_stmts = true;
        $codebase = $statements_analyzer->get_codebase();
        $project_analyzer = $statements_analyzer->get_project_analyzer();
        foreach ($params as $offset => $function_param) {
            $function_param_id = '$' . $function_param->name;
            $signature_type = $function_param->signature_type;
            $signature_type_location = $function_param->signature_type_location;
            if ($signature_type && $signature_type_location && $signature_type->has_object_type()) {
                $referenced_type = $signature_type;
                if ($referenced_type->is_nullable()) {
                    $referenced_type = $referenced_type->get_builder();
                    $referenced_type->remove_type('null');
                    $referenced_type = $referenced_type->freeze();
                }
                [$start, $end] = $signature_type_location->get_selection_bounds();
                $codebase->analyzer->add_offset_reference($this->get_file_path(), $start, $end, (string) $referenced_type);
            }
            if ($signature_type) {
                $signature_type = Type_Expander::expand_union($codebase, $signature_type, $context->self, $context->self, $this->get_parent_fqcln());
            }
            $parent_nodes = [];
            if ($statements_analyzer->data_flow_graph && $function_param->location) {
                //don't add to taint flow graph if the type can't transmit taints
                if (!$statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph || $function_param->type === null || !$function_param->type->is_single() || !$function_param->type->is_int() && !$function_param->type->is_float() && !$function_param->type->is_bool()) {
                    $param_assignment = Data_Flow_Node::get_for_assignment($function_param_id, $function_param->location);
                    $statements_analyzer->data_flow_graph->add_node($param_assignment);
                    if ($cased_method_id !== null) {
                        $type_source = Data_Flow_Node::get_for_method_argument($cased_method_id, $cased_method_id, $offset, $function_param->location);
                        $statements_analyzer->data_flow_graph->add_path($type_source, $param_assignment, 'param');
                    }
                    if ($storage->variadic) {
                        $this->param_nodes += [$param_assignment->id => $param_assignment];
                    }
                    $parent_nodes = [$param_assignment->id => $param_assignment];
                }
            }
            if ($function_param->type) {
                $param_type = $function_param->type;
                try {
                    $param_type = Type_Expander::expand_union($codebase, $param_type, $context->self, $context->self, $this->get_parent_fqcln(), true, false, false, true, false, true);
                } catch (Unresolvable_Constant_Exception $e) {
                    if ($function_param->type_location !== null) {
                        Issue_Buffer::maybe_add(new Unresolvable_Constant("Could not resolve constant {$e->class_name}::{$e->const_name}", $function_param->type_location), $storage->suppressed_issues, true);
                    }
                }
                if ($function_param->type_location) {
                    if ($param_type->check($this, $function_param->type_location, $storage->suppressed_issues, [], false, false, $this->function instanceof Class_Method && strtolower($this->function->name->name) !== '__construct', $context->calling_method_id) === false) {
                        $check_stmts = false;
                    }
                }
                $param_type = $param_type->add_parent_nodes($parent_nodes);
            } else {
                $param_type = new Union([new T_Mixed()], ['by_ref' => $function_param->by_ref, 'parent_nodes' => $parent_nodes]);
            }
            $var_type = $param_type;
            if ($function_param->is_variadic) {
                if ($storage->allow_named_arg_calls) {
                    $var_type = new Union([new T_Array([Type::get_array_key(), $param_type])], ['by_ref' => $function_param->by_ref, 'parent_nodes' => $parent_nodes]);
                } else {
                    $var_type = new Union([Type::get_list_atomic($param_type)], ['by_ref' => $function_param->by_ref, 'parent_nodes' => $parent_nodes]);
                }
            }
            $context->vars_in_scope[$function_param_id] = $var_type;
            $context->vars_possibly_in_scope[$function_param_id] = true;
            if ($function_param->by_ref) {
                $context->vars_in_scope[$function_param_id] = $context->vars_in_scope[$function_param_id]->set_properties(['by_ref' => true]);
                $context->references_to_external_scope[$function_param_id] = true;
            }
            $parser_param = $this->function->get_params()[$offset] ?? null;
            if ($function_param->location) {
                $statements_analyzer->register_variable($function_param_id, $function_param->location, null);
            }
            if (!$function_param->type_location || !$function_param->location) {
                if ($parser_param && $parser_param->default) {
                    Expression_Analyzer::analyze($statements_analyzer, $parser_param->default, $context);
                }
                continue;
            }
            if ($signature_type) {
                $union_comparison_result = new Type_Comparison_Result();
                if (!Union_Type_Comparator::is_contained_by($codebase, $param_type, $signature_type, false, false, $union_comparison_result) && !$union_comparison_result->type_coerced_from_mixed) {
                    if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MismatchingDocblockParamType'])) {
                        $this->add_or_update_param_type($project_analyzer, $function_param->name, $signature_type, true);
                        continue;
                    }
                    Issue_Buffer::maybe_add(new Mismatching_Docblock_Param_Type('Parameter ' . $function_param_id . ' has wrong type \'' . $param_type . '\', should be \'' . $signature_type . '\'', $function_param->type_location), $storage->suppressed_issues, true);
                    if ($signature_type->check($this, $function_param->type_location, $storage->suppressed_issues, [], false) === false) {
                        $check_stmts = false;
                    }
                    continue;
                }
            }
            if ($parser_param && $parser_param->default) {
                Expression_Analyzer::analyze($statements_analyzer, $parser_param->default, $context);
                $default_type = $statements_analyzer->node_data->get_type($parser_param->default);
                if ($default_type && !$default_type->has_mixed() && !Union_Type_Comparator::is_contained_by($codebase, $default_type, $param_type, false, false, null, true)) {
                    Issue_Buffer::maybe_add(new Invalid_Param_Default('Default value type ' . $default_type->get_id() . ' for argument ' . ($offset + 1) . ' of method ' . $cased_method_id . ' does not match the given type ' . $param_type->get_id(), $function_param->type_location));
                }
                if ($default_type && !$default_type->is_null() && $param_type->is_single_and_maybe_nullable() && $param_type->get_callable_types()) {
                    Issue_Buffer::maybe_add(new Invalid_Param_Default('Default value type for ' . $param_type->get_id() . ' argument ' . ($offset + 1) . ' of method ' . $cased_method_id . ' can only be null, ' . $default_type->get_id() . ' specified', $function_param->type_location));
                }
            }
            if ($has_template_types) {
                if ($param_type->check($this->source, $function_param->type_location, $this->suppressed_issues, [], false) === false) {
                    $check_stmts = false;
                }
            } else {
                if ($param_type->is_void()) {
                    Issue_Buffer::maybe_add(new Reserved_Word('Parameter cannot be void', $function_param->type_location, 'void'), $this->suppressed_issues);
                }
                if ($param_type->is_never()) {
                    Issue_Buffer::maybe_add(new Reserved_Word('Parameter cannot be never', $function_param->type_location, 'never'), $this->suppressed_issues);
                }
                if ($param_type->check($this->source, $function_param->type_location, $this->suppressed_issues, [], false) === false) {
                    $check_stmts = false;
                }
            }
            if ($codebase->collect_locations) {
                if ($function_param->type_location !== $function_param->signature_type_location && $function_param->signature_type_location && $function_param->signature_type) {
                    if ($function_param->signature_type->check($this->source, $function_param->signature_type_location, $this->suppressed_issues, [], false) === false) {
                        $check_stmts = false;
                    }
                }
            }
            if ($function_param->by_ref) {
                // register by ref params as having been used, to avoid false positives
                // @todo change the assignment analysis *just* for byref params
                // so that we don't have to do this
                $context->has_variable('$' . $function_param->name);
            }
            if (count($param_stmts) === count($params)) {
                Attributes_Analyzer::analyze($this, $context, $function_param, $param_stmts[$offset]->attr_groups, Attribute::TARGET_PARAMETER | ($function_param->promoted_property ? Attribute::TARGET_PROPERTY : 0), $storage->suppressed_issues + $this->get_suppressed_issues());
            }
        }
        return $check_stmts;
    }
    /**
     * @param FunctionLikeParameter[] $params
     */
    private function alter_params(Codebase $codebase, Function_Like_Storage $storage, array $params, Context $context): void
    {
        foreach ($this->function->params as $param) {
            $param_name_node = null;
            if ($param->type instanceof Php_Parser\Node\Name) {
                $param_name_node = $param->type;
            } elseif ($param->type instanceof Php_Parser\Node\Nullable_Type && $param->type->type instanceof Php_Parser\Node\Name) {
                $param_name_node = $param->type->type;
            }
            if ($param_name_node) {
                $resolved_name = Class_Like_Analyzer::get_fqcln_from_name_object($param_name_node, $this->get_aliases());
                $parent_fqcln = $this->get_parent_fqcln();
                if ($resolved_name === 'self' && $context->self) {
                    $resolved_name = $context->self;
                } elseif ($resolved_name === 'parent' && $parent_fqcln) {
                    $resolved_name = $parent_fqcln;
                }
                $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $this, $param_name_node, $resolved_name, $context->calling_method_id, false, true);
            }
        }
        if ($this->function->return_type) {
            $return_name_node = null;
            if ($this->function->return_type instanceof Php_Parser\Node\Name) {
                $return_name_node = $this->function->return_type;
            } elseif ($this->function->return_type instanceof Php_Parser\Node\Nullable_Type && $this->function->return_type->type instanceof Php_Parser\Node\Name) {
                $return_name_node = $this->function->return_type->type;
            }
            if ($return_name_node) {
                $resolved_name = Class_Like_Analyzer::get_fqcln_from_name_object($return_name_node, $this->get_aliases());
                $parent_fqcln = $this->get_parent_fqcln();
                if ($resolved_name === 'self' && $context->self) {
                    $resolved_name = $context->self;
                } elseif ($resolved_name === 'parent' && $parent_fqcln) {
                    $resolved_name = $parent_fqcln;
                }
                $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $this, $return_name_node, $resolved_name, $context->calling_method_id, false, true);
            }
        }
        if ($storage->return_type && $storage->return_type_location && $storage->return_type_location !== $storage->signature_return_type_location) {
            $replace_type = Type_Expander::expand_union($codebase, $storage->return_type, $context->self, 'static', $this->get_parent_fqcln(), false);
            $codebase->classlikes->handle_docblock_type_in_migration($codebase, $this, $replace_type, $storage->return_type_location, $context->calling_method_id);
        }
        foreach ($params as $function_param) {
            if ($function_param->type && $function_param->type_location && $function_param->type_location !== $function_param->signature_type_location && $function_param->type_location->file_path === $this->get_file_path()) {
                $replace_type = Type_Expander::expand_union($codebase, $function_param->type, $context->self, 'static', $this->get_parent_fqcln(), false);
                $codebase->classlikes->handle_docblock_type_in_migration($codebase, $this, $replace_type, $function_param->type_location, $context->calling_method_id);
            }
        }
    }
    /**
     * @param array<PhpParser\Node\Stmt> $function_stmts
     */
    public function verify_return_type(array $function_stmts, Statements_Analyzer $statements_analyzer, ?Union $return_type = null, ?string $fq_class_name = null, ?Code_Location $return_type_location = null, bool $did_explicitly_return = false, bool $closure_inside_call = false): void
    {
        Return_Type_Analyzer::verify_return_type($this->function, $function_stmts, $statements_analyzer, $statements_analyzer->node_data, $this, $return_type, $fq_class_name, $fq_class_name, $return_type_location, [], $did_explicitly_return, $closure_inside_call);
    }
    public function add_or_update_param_type(Project_Analyzer $project_analyzer, string $param_name, Union $inferred_return_type, bool $docblock_only = false): void
    {
        $manipulator = Function_Docblock_Manipulator::get_for_function($project_analyzer, $this->source->get_file_path(), $this->function);
        $codebase = $project_analyzer->get_codebase();
        $is_final = true;
        $fqcln = $this->source->get_fqcln();
        if ($fqcln !== null && $this instanceof Method_Analyzer) {
            $class_storage = $codebase->classlike_storage_provider->get($fqcln);
            $is_final = $this->function->is_final() || $class_storage->final;
        }
        $allow_native_type = !$docblock_only && $codebase->analysis_php_version_id >= 70000 && ($codebase->allow_backwards_incompatible_changes || $is_final || !$this instanceof Method_Analyzer);
        $manipulator->set_param_type($param_name, $allow_native_type ? $inferred_return_type->to_php_string($this->source->get_namespace(), $this->source->get_aliased_classes_flipped(), $this->source->get_fqcln(), $project_analyzer->get_codebase()->analysis_php_version_id) : null, $inferred_return_type->to_namespaced_string($this->source->get_namespace(), $this->source->get_aliased_classes_flipped(), $this->source->get_fqcln(), false), $inferred_return_type->to_namespaced_string($this->source->get_namespace(), $this->source->get_aliased_classes_flipped(), $this->source->get_fqcln(), true));
    }
    /**
     * Adds return types for the given function
     */
    public function add_return_types(Context $context): void
    {
        if ($this->return_vars_in_scope !== null) {
            $this->return_vars_in_scope = Type_Analyzer::combine_keyed_types($context->vars_in_scope, $this->return_vars_in_scope);
        } else {
            $this->return_vars_in_scope = $context->vars_in_scope;
        }
        if ($this->return_vars_possibly_in_scope !== null) {
            $this->return_vars_possibly_in_scope = [...$context->vars_possibly_in_scope, ...$this->return_vars_possibly_in_scope];
        } else {
            $this->return_vars_possibly_in_scope = $context->vars_possibly_in_scope;
        }
    }
    public function examine_param_types(Statements_Analyzer $statements_analyzer, Context $context, Codebase $codebase, ?Php_Parser\Node $stmt = null): void
    {
        $storage = $this->get_function_like_storage($statements_analyzer);
        foreach ($storage->params as $param) {
            if ($param->by_ref && isset($context->vars_in_scope['$' . $param->name]) && !$param->is_variadic) {
                $actual_type = $context->vars_in_scope['$' . $param->name];
                $param_out_type = $param->out_type ?: $param->type;
                if ($param_out_type && !$actual_type->has_mixed() && $param->location) {
                    if (!Union_Type_Comparator::is_contained_by($codebase, $actual_type, $param_out_type, $actual_type->ignore_nullable_issues, $actual_type->ignore_falsable_issues)) {
                        Issue_Buffer::maybe_add(new Reference_Constraint_Violation('Variable ' . '$' . $param->name . ' is limited to values of type ' . $param_out_type->get_id() . ' because it is passed by reference, ' . $actual_type->get_id() . ' type found. Use @param-out to specify ' . 'a different output type', $stmt ? new Code_Location($this, $stmt) : $param->location), $statements_analyzer->get_suppressed_issues());
                    }
                }
            }
        }
    }
    public function get_method_name(): ?string
    {
        if ($this->function instanceof Class_Method) {
            return (string) $this->function->name;
        }
        return null;
    }
    public function get_correctly_cased_method_id(?string $context_self = null): string
    {
        if ($this->function instanceof Class_Method) {
            $function_name = (string) $this->function->name;
            return ($context_self ?: $this->source->get_fqcln()) . '::' . $function_name;
        }
        if ($this->function instanceof Function_) {
            $namespace = $this->source->get_namespace();
            return ($namespace ? $namespace . '\\' : '') . $this->function->name;
        }
        if (!$this instanceof Closure_Analyzer) {
            throw new UnexpectedValueException('This is weird');
        }
        return $this->get_closure_id();
    }
    public function get_function_like_storage(?Statements_Analyzer $statements_analyzer = null): Function_Like_Storage
    {
        $codebase = $this->codebase;
        if ($this->function instanceof Class_Method && $this instanceof Method_Analyzer) {
            $method_id = $this->get_method_id();
            $codebase_methods = $codebase->methods;
            try {
                return $codebase_methods->get_storage($method_id);
            } catch (UnexpectedValueException) {
                $declaring_method_id = $codebase_methods->get_declaring_method_id($method_id);
                if ($declaring_method_id === null) {
                    throw new UnexpectedValueException('Cannot get storage for function that doesn‘t exist');
                }
                // happens for fake constructors
                return $codebase_methods->get_storage($declaring_method_id);
            }
        }
        if ($this instanceof Function_Analyzer) {
            $function_id = $this->get_function_id();
        } elseif ($this instanceof Closure_Analyzer) {
            $function_id = $this->get_closure_id();
        } else {
            throw new UnexpectedValueException('This is weird');
        }
        return $codebase->functions->get_storage($statements_analyzer, $function_id);
    }
    /** @return non-empty-string */
    public function get_id(): string
    {
        if ($this instanceof Method_Analyzer) {
            return (string) $this->get_method_id();
        }
        if ($this instanceof Function_Analyzer) {
            return $this->get_function_id();
        }
        if ($this instanceof Closure_Analyzer) {
            return $this->get_closure_id();
        }
        throw new UnexpectedValueException('This is weird');
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(): array
    {
        if ($this->source instanceof Namespace_Analyzer || $this->source instanceof File_Analyzer || $this->source instanceof Class_Like_Analyzer) {
            return $this->source->get_aliased_classes_flipped();
        }
        return [];
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(): array
    {
        if ($this->source instanceof Namespace_Analyzer || $this->source instanceof File_Analyzer || $this->source instanceof Class_Like_Analyzer) {
            return $this->source->get_aliased_classes_flipped_replaceable();
        }
        return [];
    }
    /**
     * @psalm-mutation-free
     * @return array<string, array<string, Union>>|null
     */
    #[Override]
    public function get_template_type_map(): ?array
    {
        if ($this->source instanceof Class_Like_Analyzer) {
            return ($this->source->get_template_type_map() ?: []) + ($this->storage->template_types ?: []);
        }
        return $this->storage->template_types;
    }
    #[Override]
    public function is_static(): bool
    {
        return $this->is_static;
    }
    #[Override]
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * Get a list of suppressed issues
     *
     * @return array<string>
     */
    #[Override]
    public function get_suppressed_issues(): array
    {
        return $this->suppressed_issues;
    }
    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function add_suppressed_issues(array $new_issues): void
    {
        if (isset($new_issues[0])) {
            $new_issues = array_combine($new_issues, $new_issues);
        }
        $this->suppressed_issues = $new_issues + $this->suppressed_issues;
    }
    /**
     * @param array<int, string> $new_issues
     */
    #[Override]
    public function remove_suppressed_issues(array $new_issues): void
    {
        if (isset($new_issues[0])) {
            $new_issues = array_combine($new_issues, $new_issues);
        }
        $this->suppressed_issues = array_diff_key($this->suppressed_issues, $new_issues);
    }
    /**
     * Adds a suppressed issue, useful when creating a method checker from scratch
     */
    public function add_suppressed_issue(string $issue_name): void
    {
        $this->suppressed_issues[] = $issue_name;
    }
    public static function clear_cache(): void
    {
        self::$no_effects_hashes = [];
    }
    public function get_local_return_type(Union $storage_return_type, bool $final = false): Union
    {
        if ($this->local_return_type) {
            return $this->local_return_type;
        }
        $this->local_return_type = Type_Expander::expand_union($this->codebase, $storage_return_type, $this->get_fqcln(), $this->get_fqcln(), $this->get_parent_fqcln(), true, true, $final);
        return $this->local_return_type;
    }
    /**
     * @return array{
     *        MethodIdentifier|null,
     *        MethodIdentifier|null,
     *        ClassLikeStorage|null,
     *        ?string,
     *        ?string,
     *        array<string, MethodIdentifier>
     * }|null
     */
    private function get_function_information(Context $context, Codebase $codebase, Node_Data_Provider $type_provider, Function_Like_Storage $storage, bool $add_mutations): ?array
    {
        $classlike_storage_provider = $codebase->classlike_storage_provider;
        $real_method_id = null;
        $method_id = null;
        $cased_method_id = null;
        $hash = null;
        $appearing_class_storage = null;
        $overridden_method_ids = [];
        if ($this instanceof Method_Analyzer) {
            if (!$storage instanceof Method_Storage) {
                throw new UnexpectedValueException('$storage must be MethodStorage');
            }
            $real_method_id = $this->get_method_id();
            $method_id = $this->get_method_id($context->self);
            $fq_class_name = (string) $context->self;
            $appearing_class_storage = $classlike_storage_provider->get($fq_class_name);
            if ($add_mutations) {
                if (!$context->collect_initializations) {
                    $hash = md5($real_method_id . '::' . $context->get_scope_summary());
                    // if we know that the function has no effects on vars, we don't bother rechecking
                    if (isset(self::$no_effects_hashes[$hash])) {
                        return null;
                    }
                }
            } elseif ($context->self) {
                if ($appearing_class_storage->template_types) {
                    $template_params = [];
                    foreach ($appearing_class_storage->template_types as $param_name => $template_map) {
                        $key = array_keys($template_map)[0];
                        $template_params[] = new Union([new T_Template_Param($param_name, reset($template_map), $key)]);
                    }
                    $this_object_type = new T_Generic_Object($context->self, $template_params, false, !$storage->final);
                } else {
                    $this_object_type = new T_Named_Object($context->self, !$storage->final);
                }
                $props = [];
                if ($storage->external_mutation_free && !$storage->mutation_free_inferred) {
                    $props = ['reference_free' => true];
                    if ($this->function->name->name !== '__construct') {
                        $props['allow_mutations'] = false;
                    }
                }
                if ($codebase->taint_flow_graph && $storage->specialize_call && $storage->location) {
                    $new_parent_node = Data_Flow_Node::get_for_assignment('$this in ' . $method_id, $storage->location);
                    $codebase->taint_flow_graph->add_node($new_parent_node);
                    $props['parent_nodes'] = [$new_parent_node->id => $new_parent_node];
                }
                if ($this->storage instanceof Method_Storage && $this->storage->if_this_is_type) {
                    $template_result = new Template_Result($this->get_template_type_map() ?? [], []);
                    Template_Standin_Type_Replacer::fill_template_result(new Union([$this_object_type]), $template_result, $codebase, null, $this->storage->if_this_is_type);
                    foreach ($context->vars_in_scope as $var_name => &$var_type) {
                        if (0 === mb_strpos($var_name, '$this->')) {
                            $var_type = Template_Inferred_Type_Replacer::replace($var_type, $template_result, $codebase);
                        }
                    }
                    $context->vars_in_scope['$this'] = $this->storage->if_this_is_type->set_properties($props);
                } else {
                    $context->vars_in_scope['$this'] = new Union([$this_object_type], $props);
                }
                $context->vars_possibly_in_scope['$this'] = true;
            }
            if ($appearing_class_storage->has_visitor_issues) {
                return null;
            }
            $cased_method_id = $fq_class_name . '::' . $storage->cased_name;
            $overridden_method_ids = $codebase->methods->get_overridden_method_ids($method_id);
            $code_location = new Code_Location($this, $this->function, null, true);
            $has_override_attribute = false;
            foreach ($storage->attributes as $s) {
                if ($s->fq_class_name === 'Override') {
                    $has_override_attribute = true;
                    break;
                }
            }
            if ($has_override_attribute && (!$overridden_method_ids || $storage->cased_name === '__construct')) {
                Issue_Buffer::maybe_add(new Invalid_Override('Method ' . $storage->cased_name . ' does not match any parent method', $code_location), $this->get_suppressed_issues());
            }
            if (!$has_override_attribute && $codebase->config->ensure_override_attribute && $overridden_method_ids && ($storage->defining_fqcln === null || !$codebase->classlike_storage_provider->get($storage->defining_fqcln)->is_trait) && $storage->cased_name !== '__construct' && ($storage->cased_name !== '__toString' || isset($appearing_class_storage->direct_class_interfaces['stringable']))) {
                Issue_Buffer::maybe_add(new Missing_Override_Attribute('Method ' . $method_id . ' should have the "Override" attribute', $code_location), $this->get_suppressed_issues(), true);
                if ($codebase->alter_code && $storage->stmt_location !== null && isset($this->get_project_analyzer()->get_issues_to_fix()['MissingOverrideAttribute'])) {
                    $idx = $storage->stmt_location->get_selection_bounds()[0];
                    File_Manipulation_Buffer::add($storage->stmt_location->file_path, [new File_Manipulation($idx, $idx, "#[\\Override]\n", true)]);
                }
            }
            if ($overridden_method_ids && !$context->collect_initializations && !$context->collect_mutations) {
                foreach ($overridden_method_ids as $overridden_method_id) {
                    $parent_method_storage = $codebase->methods->get_storage($overridden_method_id);
                    $overridden_fq_class_name = $overridden_method_id->fq_class_name;
                    $parent_storage = $classlike_storage_provider->get($overridden_fq_class_name);
                    if ($this->function->name->name === '__construct' && !$parent_storage->preserve_constructor_signature) {
                        continue;
                    }
                    $implementer_visibility = $storage->visibility;
                    $implementer_appearing_method_id = $codebase->methods->get_appearing_method_id($method_id);
                    $implementer_declaring_method_id = $real_method_id;
                    $declaring_class_storage = $appearing_class_storage;
                    if ($implementer_appearing_method_id && $implementer_appearing_method_id !== $implementer_declaring_method_id) {
                        $appearing_fq_class_name = $implementer_appearing_method_id->fq_class_name;
                        $appearing_method_name = $implementer_appearing_method_id->method_name;
                        $declaring_fq_class_name = $implementer_declaring_method_id->fq_class_name;
                        $appearing_class_storage = $classlike_storage_provider->get($appearing_fq_class_name);
                        $declaring_class_storage = $classlike_storage_provider->get($declaring_fq_class_name);
                        if (isset($appearing_class_storage->trait_visibility_map[$appearing_method_name])) {
                            $implementer_visibility = $appearing_class_storage->trait_visibility_map[$appearing_method_name];
                        }
                    }
                    // we've already checked this in the class checker
                    if (!isset($appearing_class_storage->class_implements[strtolower($overridden_fq_class_name)])) {
                        Method_Comparator::compare($codebase, count($overridden_method_ids) === 1 ? $this->function : null, $declaring_class_storage, $parent_storage, $storage, $parent_method_storage, $fq_class_name, $implementer_visibility, $code_location, $storage->suppressed_issues);
                    }
                }
            }
            Method_Analyzer::check_method_signature_must_omit_return_type($storage, $code_location);
            if ($appearing_class_storage->is_enum) {
                Method_Analyzer::check_forbidden_enum_method($storage, $appearing_class_storage);
            }
            if (!$context->calling_method_id || !$context->collect_initializations) {
                $context->calling_method_id = strtolower((string) $method_id);
            }
        } elseif ($this instanceof Function_Analyzer) {
            $function_name = $this->function->name->name;
            $namespace_prefix = $this->get_namespace();
            $cased_method_id = ($namespace_prefix !== null ? $namespace_prefix . '\\' : '') . $function_name;
            $context->calling_function_id = strtolower($cased_method_id);
        } elseif ($this instanceof Closure_Analyzer) {
            if ($storage->return_type) {
                $closure_return_type = Type_Expander::expand_union($codebase, $storage->return_type, $context->self, $context->self, $this->get_parent_fqcln());
            } else {
                $closure_return_type = Type::get_mixed();
            }
            $closure_type = new T_Closure('Closure', $storage->params, $closure_return_type, $storage instanceof Function_Storage ? $storage->pure : null, $storage instanceof Function_Storage ? $storage->byref_uses : []);
            $type_provider->set_type($this->function, new Union([$closure_type]));
        } else {
            throw new UnexpectedValueException('Impossible');
        }
        return [$real_method_id, $method_id, $appearing_class_storage, $hash, $cased_method_id, $overridden_method_ids];
    }
    /**
     * @return array<int,CodeLocation>
     */
    private function detect_unused_parameters(Statements_Analyzer $statements_analyzer, Function_Like_Storage $storage, Context $context): array
    {
        $codebase = $statements_analyzer->get_codebase();
        $unused_params = [];
        foreach ($statements_analyzer->get_unused_var_locations() as [$var_name, $original_location]) {
            if (!array_key_exists(substr($var_name, 1), $storage->param_lookup)) {
                continue;
            }
            if ($this->is_ignored_for_unused_param($var_name)) {
                continue;
            }
            $position = array_search(substr($var_name, 1), array_keys($storage->param_lookup), true);
            if ($position === false) {
                throw new UnexpectedValueException('$position should not be false here');
            }
            if ($storage->params[$position]->promoted_property) {
                continue;
            }
            $did_match_param = false;
            foreach ($this->function->params as $param) {
                if ($param->var->get_attribute('endFilePos') === $original_location->raw_file_end) {
                    $did_match_param = true;
                    break;
                }
            }
            if (!$did_match_param) {
                continue;
            }
            $assignment_node = Data_Flow_Node::get_for_assignment($var_name, $original_location);
            if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph && $statements_analyzer->data_flow_graph->is_variable_used($assignment_node)) {
                continue;
            }
            if (!$storage instanceof Method_Storage || !$storage->cased_name || $storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                $unused_params[$position] = $original_location;
                continue;
            }
            $fq_class_name = (string) $context->self;
            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            $method_name_lc = strtolower($storage->cased_name);
            if ($storage->abstract) {
                continue;
            }
            if (isset($class_storage->overridden_method_ids[$method_name_lc])) {
                $parent_method_id = end($class_storage->overridden_method_ids[$method_name_lc]);
                if ($parent_method_id) {
                    $parent_method_storage = $codebase->methods->get_storage($parent_method_id);
                    // if the parent method has a param at that position and isn't abstract
                    if (!$parent_method_storage->abstract && isset($parent_method_storage->params[$position])) {
                        continue;
                    }
                }
            }
            $unused_params[$position] = $original_location;
        }
        return $unused_params;
    }
    private function detect_previous_unused_argument_position(Function_Like_Storage $function, int $position): int
    {
        $params = $function->params;
        krsort($params, SORT_NUMERIC);
        foreach ($params as $index => $param) {
            if ($index > $position) {
                continue;
            }
            if ($this->is_ignored_for_unused_param($param->name)) {
                continue;
            }
            return $index;
        }
        return 0;
    }
    private function is_ignored_for_unused_param(string $var_name): bool
    {
        return str_starts_with($var_name, '$_') || str_starts_with($var_name, '$unused') && $var_name !== '$unused';
    }
}