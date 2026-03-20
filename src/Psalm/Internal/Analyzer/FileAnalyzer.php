<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Override;
use Php_Parser;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Unprepared_Analysis_Exception;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Reflection;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Type\Type_Alias\Linkable_Type_Alias;
use Psalm\Internal\Type\Type_Tokenizer;
use Psalm\Issue\Invalid_Type_Import;
use Psalm\Issue\Uncaught_Throw_In_Global_Scope;
use Psalm\Issue_Buffer;
use Psalm\Node_Type_Provider;
use Psalm\Plugin\Event_Handler\Event\After_File_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_File_Analysis_Event;
use Psalm\Type;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_combine;
use function array_diff_key;
use function array_keys;
use function count;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 * @psalm-consistent-constructor
 */
class File_Analyzer extends Source_Analyzer
{
    use Can_Alias;
    private ?string $root_file_path = null;
    private ?string $root_file_name = null;
    /**
     * @var array<string, bool>
     */
    private array $required_file_paths = [];
    /**
     * @var array<string, bool>
     */
    private array $parent_file_paths = [];
    /**
     * @var array<string>
     */
    private array $suppressed_issues = [];
    /**
     * @var array<string, array<string, string>>
     */
    private array $namespace_aliased_classes = [];
    /**
     * @var array<string, array<lowercase-string, string>>
     */
    private array $namespace_aliased_classes_flipped = [];
    /**
     * @var array<string, array<string, string>>
     */
    private array $namespace_aliased_classes_flipped_replaceable = [];
    /**
     * @var array<lowercase-string, InterfaceAnalyzer>
     */
    public array $interface_analyzers_to_analyze = [];
    /**
     * @var array<lowercase-string, ClassAnalyzer>
     */
    public array $class_analyzers_to_analyze = [];
    public ?Context $context = null;
    public Codebase $codebase;
    private int $first_statement_offset = -1;
    private ?Node_Data_Provider $node_data = null;
    private ?Union $return_type = null;
    public function __construct(public Project_Analyzer $project_analyzer, protected string $file_path, protected string $file_name)
    {
        $this->source = $this;
        $this->codebase = $project_analyzer->get_codebase();
    }
    public function analyze(?Context $file_context = null, ?Context $global_context = null): void
    {
        $codebase = $this->project_analyzer->get_codebase();
        $file_storage = $codebase->file_storage_provider->get($this->file_path);
        if (!$file_storage->deep_scan && !$codebase->server_mode) {
            throw new Unprepared_Analysis_Exception('File ' . $this->file_path . ' has not been properly scanned');
        }
        if ($file_storage->has_visitor_issues) {
            return;
        }
        if ($file_context) {
            $this->context = $file_context;
        }
        if (!$this->context) {
            $this->context = new Context();
        }
        if ($codebase->config->use_strict_types_for_file($this->file_path)) {
            $this->context->strict_types = true;
        }
        $this->context->is_global = true;
        $this->context->define_globals();
        $this->context->collect_exceptions = $codebase->config->check_for_throws_in_global_scope;
        try {
            $stmts = $codebase->get_statements_for_file($this->file_path);
        } catch (Php_Parser\Error) {
            return;
        }
        $event = new Before_File_Analysis_Event($this, $this->context, $file_storage, $codebase, $stmts);
        $codebase->config->event_dispatcher->dispatch_before_file_analysis($event);
        if ($codebase->alter_code) {
            foreach ($stmts as $stmt) {
                if (!$stmt instanceof Php_Parser\Node\Stmt\Declare_) {
                    $this->first_statement_offset = (int) $stmt->get_attribute('startFilePos');
                    break;
                }
            }
        }
        $leftover_stmts = $this->populate_checkers($stmts);
        $this->node_data = new Node_Data_Provider();
        $statements_analyzer = new Statements_Analyzer($this, $this->node_data);
        foreach ($file_storage->docblock_issues as $docblock_issue) {
            Issue_Buffer::maybe_add($docblock_issue);
        }
        // if there are any leftover statements, evaluate them,
        // in turn causing the classes/interfaces be evaluated
        if ($leftover_stmts) {
            $statements_analyzer->analyze($leftover_stmts, $this->context, $global_context, true);
            foreach ($leftover_stmts as $leftover_stmt) {
                if ($leftover_stmt instanceof Php_Parser\Node\Stmt\Return_) {
                    if ($leftover_stmt->expr) {
                        $this->return_type = $statements_analyzer->node_data->get_type($leftover_stmt->expr) ?? Type::get_mixed();
                    } else {
                        $this->return_type = Type::get_void();
                    }
                    break;
                }
            }
        }
        // check any leftover interfaces not already evaluated
        foreach ($this->interface_analyzers_to_analyze as $interface_analyzer) {
            $interface_analyzer->analyze();
        }
        // check any leftover classes not already evaluated
        foreach ($this->class_analyzers_to_analyze as $class_analyzer) {
            $class_analyzer->analyze(null, $this->context);
        }
        if ($codebase->config->check_for_throws_in_global_scope) {
            $uncaught_throws = $statements_analyzer->get_uncaught_throws($this->context);
            foreach ($uncaught_throws as $possibly_thrown_exception => $codelocations) {
                foreach ($codelocations as $codelocation) {
                    // issues are suppressed in ThrowAnalyzer, CallAnalyzer, etc.
                    Issue_Buffer::maybe_add(new Uncaught_Throw_In_Global_Scope($possibly_thrown_exception . ' is thrown but not caught in global scope', $codelocation));
                }
            }
        }
        // validate type imports
        foreach ($file_storage->type_aliases as $alias) {
            if ($alias instanceof Linkable_Type_Alias) {
                $location = new Docblock_Type_Location($this->get_source(), $alias->start_offset, $alias->end_offset, $alias->line_number);
                $fq_source_classlike = $alias->declaring_fq_classlike_name;
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($this->get_source(), $fq_source_classlike, $location, null, null, $this->suppressed_issues, new Class_Like_Name_Options(true, true, true, true, true)) === false) {
                    continue;
                }
                $referenced_class_storage = $codebase->classlike_storage_provider->get($fq_source_classlike);
                if (!isset($referenced_class_storage->type_aliases[$alias->alias_name])) {
                    Issue_Buffer::maybe_add(new Invalid_Type_Import('Type alias ' . $alias->alias_name . ' imported from ' . $fq_source_classlike . ' is not defined on the source class', $location));
                }
            }
        }
        $event = new After_File_Analysis_Event($this, $this->context, $file_storage, $codebase, $stmts);
        $codebase->config->event_dispatcher->dispatch_after_file_analysis($event);
        $this->class_analyzers_to_analyze = [];
        $this->interface_analyzers_to_analyze = [];
    }
    /**
     * @param  array<int, PhpParser\Node\Stmt>  $stmts
     * @return list<PhpParser\Node\Stmt>
     */
    public function populate_checkers(array $stmts): array
    {
        $leftover_stmts = [];
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Trait_) {
                $leftover_stmts[] = $stmt;
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Like) {
                $this->populate_class_like_analyzers($stmt);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Namespace_) {
                $namespace_name = $stmt->name ? $stmt->name->to_string() : '';
                $namespace_analyzer = new Namespace_Analyzer($stmt, $this);
                $namespace_analyzer->collect_analyzable_information();
                $this->namespace_aliased_classes[$namespace_name] = $namespace_analyzer->get_aliases()->uses;
                $this->namespace_aliased_classes_flipped[$namespace_name] = $namespace_analyzer->get_aliased_classes_flipped();
                $this->namespace_aliased_classes_flipped_replaceable[$namespace_name] = $namespace_analyzer->get_aliased_classes_flipped_replaceable();
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Use_) {
                $this->visit_use($stmt);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Group_Use) {
                $this->visit_group_use($stmt);
            } else {
                if ($stmt instanceof Php_Parser\Node\Stmt\If_) {
                    foreach ($stmt->stmts as $if_stmt) {
                        if ($if_stmt instanceof Php_Parser\Node\Stmt\Class_Like) {
                            $this->populate_class_like_analyzers($if_stmt);
                        }
                    }
                }
                $leftover_stmts[] = $stmt;
            }
        }
        return $leftover_stmts;
    }
    private function populate_class_like_analyzers(Php_Parser\Node\Stmt\Class_Like $stmt): void
    {
        if ($stmt instanceof Php_Parser\Node\Stmt\Class_ || $stmt instanceof Php_Parser\Node\Stmt\Enum_) {
            if (!$stmt->name) {
                return;
            }
            // this can happen when stubbing
            if (!$this->codebase->class_exists($stmt->name->name) && !$this->codebase->classlikes->enum_exists($stmt->name->name)) {
                return;
            }
            $class_analyzer = new Class_Analyzer($stmt, $this, $stmt->name->name);
            $fq_class_name = $class_analyzer->get_fqcln();
            $this->class_analyzers_to_analyze[strtolower($fq_class_name)] = $class_analyzer;
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Interface_) {
            if (!$stmt->name) {
                return;
            }
            // this can happen when stubbing
            if (!$this->codebase->interface_exists($stmt->name->name)) {
                return;
            }
            $class_analyzer = new Interface_Analyzer($stmt, $this, $stmt->name->name);
            $fq_class_name = $class_analyzer->get_fqcln();
            $this->interface_analyzers_to_analyze[strtolower($fq_class_name)] = $class_analyzer;
        }
    }
    public function add_namespaced_class_analyzer(string $fq_class_name, Class_Analyzer $class_analyzer): void
    {
        $this->class_analyzers_to_analyze[strtolower($fq_class_name)] = $class_analyzer;
    }
    public function add_namespaced_interface_analyzer(string $fq_class_name, Interface_Analyzer $interface_analyzer): void
    {
        $this->interface_analyzers_to_analyze[strtolower($fq_class_name)] = $interface_analyzer;
    }
    public function get_method_mutations(Method_Identifier $method_id, Context $this_context, bool $from_project_analyzer = false): void
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        $fq_class_name_lc = strtolower($fq_class_name);
        if (isset($this->class_analyzers_to_analyze[$fq_class_name_lc])) {
            $class_analyzer_to_examine = $this->class_analyzers_to_analyze[$fq_class_name_lc];
        } else {
            if (!$from_project_analyzer) {
                $this->project_analyzer->get_method_mutations($method_id, $this_context, $this->get_root_file_path(), $this->get_root_file_name());
            }
            return;
        }
        $call_context = new Context($this_context->self);
        $call_context->collect_mutations = $this_context->collect_mutations;
        $call_context->collect_initializations = $this_context->collect_initializations;
        $call_context->collect_nonprivate_initializations = $this_context->collect_nonprivate_initializations;
        $call_context->initialized_methods = $this_context->initialized_methods;
        $call_context->include_location = $this_context->include_location;
        $call_context->calling_method_id = $this_context->calling_method_id;
        foreach ($this_context->vars_possibly_in_scope as $var => $_) {
            if (str_starts_with($var, '$this->')) {
                $call_context->vars_possibly_in_scope[$var] = true;
            }
        }
        foreach ($this_context->vars_in_scope as $var => $type) {
            if (str_starts_with($var, '$this->')) {
                $call_context->vars_in_scope[$var] = $type;
            }
        }
        if (!isset($this_context->vars_in_scope['$this'])) {
            throw new UnexpectedValueException('Should exist');
        }
        $call_context->vars_in_scope['$this'] = $this_context->vars_in_scope['$this'];
        $class_analyzer_to_examine->get_method_mutations($method_name, $call_context);
        foreach ($call_context->vars_possibly_in_scope as $var => $_) {
            $this_context->vars_possibly_in_scope[$var] = true;
        }
        foreach ($call_context->vars_in_scope as $var => $type) {
            $this_context->vars_in_scope[$var] = $type;
        }
    }
    public function get_function_like_analyzer(Method_Identifier $method_id): ?Method_Analyzer
    {
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        $fq_class_name_lc = strtolower($fq_class_name);
        if (!isset($this->class_analyzers_to_analyze[$fq_class_name_lc])) {
            return null;
        }
        $class_analyzer_to_examine = $this->class_analyzers_to_analyze[$fq_class_name_lc];
        return $class_analyzer_to_examine->get_function_like_analyzer($method_name);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_namespace(): ?string
    {
        return null;
    }
    /**
     * @psalm-mutation-free
     * @return array<lowercase-string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped(?string $namespace_name = null): array
    {
        if ($namespace_name && isset($this->namespace_aliased_classes_flipped[$namespace_name])) {
            return $this->namespace_aliased_classes_flipped[$namespace_name];
        }
        return $this->aliased_classes_flipped;
    }
    /**
     * @psalm-mutation-free
     * @return array<string, string>
     */
    #[Override]
    public function get_aliased_classes_flipped_replaceable(?string $namespace_name = null): array
    {
        if ($namespace_name && isset($this->namespace_aliased_classes_flipped_replaceable[$namespace_name])) {
            return $this->namespace_aliased_classes_flipped_replaceable[$namespace_name];
        }
        return $this->aliased_classes_flipped_replaceable;
    }
    public static function clear_cache(): void
    {
        Type_Tokenizer::clear_cache();
        Reflection::clear_cache();
        Functions::clear_cache();
        Issue_Buffer::clear_cache();
        File_Manipulation_Buffer::clear_cache();
        Function_Like_Analyzer::clear_cache();
        Class_Like_Storage_Provider::delete_all();
        File_Storage_Provider::delete_all();
        File_Reference_Provider::clear_cache();
        Internal_Call_Map_Handler::clear_cache();
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_name(): string
    {
        return $this->file_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_file_path(): string
    {
        return $this->file_path;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_name(): string
    {
        return $this->root_file_name ?: $this->file_name;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_root_file_path(): string
    {
        return $this->root_file_path ?: $this->file_path;
    }
    #[Override]
    public function set_root_file_path(string $file_path, string $file_name): void
    {
        $this->root_file_name = $file_name;
        $this->root_file_path = $file_path;
    }
    public function add_required_file_path(string $file_path): void
    {
        $this->required_file_paths[$file_path] = true;
    }
    public function add_parent_file_path(string $file_path): void
    {
        $this->parent_file_paths[$file_path] = true;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function has_parent_file_path(string $file_path): bool
    {
        return $this->file_path === $file_path || isset($this->parent_file_paths[$file_path]);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function has_already_required_file_path(string $file_path): bool
    {
        return isset($this->required_file_paths[$file_path]);
    }
    /**
     * @psalm-mutation-free
     * @return list<string>
     */
    public function get_required_file_paths(): array
    {
        return array_keys($this->required_file_paths);
    }
    /**
     * @psalm-mutation-free
     * @return list<string>
     */
    public function get_parent_file_paths(): array
    {
        return array_keys($this->parent_file_paths);
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_require_nesting(): int
    {
        return count($this->parent_file_paths);
    }
    /**
     * @psalm-mutation-free
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
    /** @psalm-mutation-free */
    #[Override]
    public function get_fqcln(): ?string
    {
        return null;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_parent_fqcln(): ?string
    {
        return null;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_class_name(): ?string
    {
        return null;
    }
    /**
     * @psalm-mutation-free
     * @return array<string, array<string, Union>>|null
     */
    #[Override]
    public function get_template_type_map(): ?array
    {
        return null;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function is_static(): bool
    {
        return false;
    }
    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function get_file_analyzer(): File_Analyzer
    {
        return $this;
    }
    /**
     * @psalm-mutation-free
     */
    #[Override]
    public function get_project_analyzer(): Project_Analyzer
    {
        return $this->project_analyzer;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /** @psalm-mutation-free */
    public function get_first_statement_offset(): int
    {
        return $this->first_statement_offset;
    }
    /** @psalm-mutation-free */
    #[Override]
    public function get_node_type_provider(): Node_Type_Provider
    {
        if (!$this->node_data) {
            throw new UnexpectedValueException('There should be a node type provider');
        }
        return $this->node_data;
    }
    /** @psalm-mutation-free */
    public function get_return_type(): ?Union
    {
        return $this->return_type;
    }
    public function clear_source_before_destruction(): void
    {
        unset($this->source);
    }
}