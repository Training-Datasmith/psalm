<?php

declare (strict_types=1);
namespace Psalm;

use Exception;
use InvalidArgumentException;
use Language_Server_Protocol\Command;
use Language_Server_Protocol\Completion_Item;
use Language_Server_Protocol\Completion_Item_Kind;
use Language_Server_Protocol\Insert_Text_Format;
use Language_Server_Protocol\Parameter_Information;
use Language_Server_Protocol\Position;
use Language_Server_Protocol\Range;
use Language_Server_Protocol\Signature_Information;
use Language_Server_Protocol\Text_Edit;
use Php_Parser;
use Php_Parser\Node\Arg;
use Psalm\Code_Location\Raw;
use Psalm\Exception\Unanalyzed_File_Exception;
use Psalm\Exception\Unpopulated_Classlike_Exception;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Analyzer\Statements\Block\Foreach_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Variable_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Analyzer;
use Psalm\Internal\Codebase\Class_Likes;
use Psalm\Internal\Codebase\Functions;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Methods;
use Psalm\Internal\Codebase\Populator;
use Psalm\Internal\Codebase\Properties;
use Psalm\Internal\Codebase\Reflection;
use Psalm\Internal\Codebase\Scanner;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Taint_Sink;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Language_Server\Php_Markdown_Content;
use Psalm\Internal\Language_Server\Reference;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Provider\Statements_Provider;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Function_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Taint_Kind_Group;
use Psalm\Type\Union;
use ReflectionProperty;
use Reflection_Type;
use UnexpectedValueException;
use function array_combine;
use function array_key_exists;
use function array_pop;
use function array_reverse;
use function array_values;
use function count;
use function dirname;
use function error_log;
use function explode;
use function implode;
use function in_array;
use function intdiv;
use function is_numeric;
use function is_string;
use function krsort;
use function ksort;
use function preg_match;
use function preg_replace;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;
use const PHP_VERSION_ID;
/**
 * @api
 */
final class Codebase
{
    /**
     * A map of fully-qualified use declarations to the files
     * that reference them (keyed by filename)
     *
     * @var array<lowercase-string, array<int, CodeLocation>>
     */
    public array $use_referencing_locations = [];
    public File_Storage_Provider $file_storage_provider;
    public Class_Like_Storage_Provider $classlike_storage_provider;
    public bool $collect_references = false;
    public bool $collect_locations = false;
    /**
     * @var null|'always'|'auto'
     */
    public ?string $find_unused_code = null;
    public File_Provider $file_provider;
    public File_Reference_Provider $file_reference_provider;
    public Statements_Provider $statements_provider;
    public readonly Progress $progress;
    /**
     * @var array<string, Union>
     */
    private static array $stubbed_constants = [];
    /**
     * Whether to register autoloaded information
     */
    public bool $register_autoload_files = false;
    /**
     * Whether to log functions just at the file level or globally (for stubs)
     */
    public bool $register_stub_files = false;
    public bool $all_functions_global = false;
    public bool $all_constants_global = false;
    public bool $find_unused_variables = false;
    public Scanner $scanner;
    public Analyzer $analyzer;
    public Functions $functions;
    public Class_Likes $classlikes;
    public Methods $methods;
    public Properties $properties;
    public Populator $populator;
    public ?Taint_Flow_Graph $taint_flow_graph = null;
    public bool $server_mode = false;
    public bool $store_node_types = false;
    /**
     * Whether or not to infer types from usage. Computationally expensive, so turned off by default
     */
    public bool $infer_types_from_usage = false;
    public bool $alter_code = false;
    public bool $diff_methods = false;
    /** whether or not we only checked a part of the codebase */
    public bool $diff_run = false;
    public bool $language_server = false;
    /**
     * @var array<lowercase-string, string>
     */
    public array $methods_to_move = [];
    /**
     * @var array<lowercase-string, string>
     */
    public array $methods_to_rename = [];
    /**
     * @var array<string, string>
     */
    public array $properties_to_move = [];
    /**
     * @var array<string, string>
     */
    public array $properties_to_rename = [];
    /**
     * @var array<string, string>
     */
    public array $class_constants_to_move = [];
    /**
     * @var array<string, string>
     */
    public array $class_constants_to_rename = [];
    /**
     * @var array<lowercase-string, string>
     */
    public array $classes_to_move = [];
    /**
     * @var array<lowercase-string, string>
     */
    public array $call_transforms = [];
    /**
     * @var array<string, string>
     */
    public array $property_transforms = [];
    /**
     * @var array<string, string>
     */
    public array $class_constant_transforms = [];
    /**
     * @var array<lowercase-string, string>
     */
    public array $class_transforms = [];
    public bool $allow_backwards_incompatible_changes = true;
    public int $analysis_php_version_id = PHP_VERSION_ID;
    /** @var 'cli'|'config'|'composer'|'tests'|'runtime' */
    public string $php_version_source = 'runtime';
    public bool $track_unused_suppressions = false;
    public bool $literal_array_key_check = false;
    /** @internal */
    public function __construct(public Config $config, Providers $providers, ?Progress $progress = null)
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $this->file_storage_provider = $providers->file_storage_provider;
        $this->classlike_storage_provider = $providers->classlike_storage_provider;
        $this->progress = $progress;
        $this->file_provider = $providers->file_provider;
        $this->file_reference_provider = $providers->file_reference_provider;
        $this->statements_provider = $providers->statements_provider;
        self::$stubbed_constants = [];
        $reflection = new Reflection($providers->classlike_storage_provider, $this);
        $this->scanner = new Scanner($this, $config, $providers->file_storage_provider, $providers->file_provider, $reflection, $providers->file_reference_provider, $progress);
        $this->load_analyzer();
        $this->functions = new Functions($providers->file_storage_provider, $reflection);
        $this->classlikes = new Class_Likes($this->config, $providers->classlike_storage_provider, $providers->file_reference_provider, $this->scanner);
        $this->properties = new Properties($providers->classlike_storage_provider, $providers->file_reference_provider, $this->classlikes);
        $this->methods = new Methods($providers->classlike_storage_provider, $providers->file_reference_provider, $this->classlikes);
        $this->populator = new Populator($providers->classlike_storage_provider, $providers->file_storage_provider, $this->classlikes, $providers->file_reference_provider, $progress);
        $this->load_analyzer();
    }
    private function load_analyzer(): void
    {
        $this->analyzer = new Analyzer($this->config, $this->file_provider, $this->file_storage_provider, $this->progress);
    }
    /**
     * @param array<string> $candidate_files
     */
    public function reload_files(Project_Analyzer $project_analyzer, array $candidate_files, bool $force = false): void
    {
        $this->load_analyzer();
        if ($force) {
            File_Reference_Provider::clear_cache();
        }
        $this->file_reference_provider->load_reference_cache(false);
        Function_Like_Analyzer::clear_cache();
        if ($force || !$this->statements_provider->parser_cache_provider) {
            $diff_files = $candidate_files;
        } else {
            $diff_files = [];
            $parser_cache_provider = $this->statements_provider->parser_cache_provider;
            foreach ($candidate_files as $candidate_file_path) {
                $hash = $parser_cache_provider->get_hash($candidate_file_path);
                if ($hash !== null && $hash !== $this->file_provider->get_contents($candidate_file_path)) {
                    $diff_files[] = $candidate_file_path;
                }
            }
        }
        $referenced_files = $project_analyzer->get_referenced_files_from_diff($diff_files, false);
        foreach ($diff_files as $diff_file_path) {
            $this->invalidate_information_for_file($diff_file_path);
        }
        foreach ($referenced_files as $referenced_file_path) {
            if (in_array($referenced_file_path, $diff_files, true)) {
                continue;
            }
            $file_storage = $this->file_storage_provider->get($referenced_file_path);
            foreach ($file_storage->classlikes_in_file as $fq_classlike_name) {
                $this->classlike_storage_provider->remove($fq_classlike_name);
                $this->classlikes->remove_class_like($fq_classlike_name);
            }
            $this->file_storage_provider->remove($referenced_file_path);
            $this->scanner->remove_file($referenced_file_path);
        }
        $referenced_files = array_combine($referenced_files, $referenced_files);
        $this->scanner->add_files_to_deep_scan($referenced_files);
        $this->add_files_to_analyze(array_combine($candidate_files, $candidate_files));
        $this->scanner->scan_files($this->classlikes);
        $this->file_reference_provider->update_reference_cache($this, $referenced_files);
        $this->populator->populate_codebase();
    }
    public function enter_server_mode(): void
    {
        $this->server_mode = true;
        $this->store_node_types = true;
    }
    public function collect_locations(): void
    {
        $this->collect_locations = true;
        $this->classlikes->collect_locations = true;
        $this->methods->collect_locations = true;
        $this->properties->collect_locations = true;
    }
    /**
     * @param 'always'|'auto' $find_unused_code
     */
    public function report_unused_code(string $find_unused_code = 'auto'): void
    {
        $this->collect_references = true;
        $this->classlikes->collect_references = true;
        $this->find_unused_code = $find_unused_code;
        $this->find_unused_variables = true;
    }
    public function report_unused_variables(): void
    {
        $this->collect_references = true;
        $this->find_unused_variables = true;
    }
    /**
     * @param array<string, string> $files_to_analyze
     */
    public function add_files_to_analyze(array $files_to_analyze): void
    {
        $this->scanner->add_files_to_deep_scan($files_to_analyze);
        $this->analyzer->add_files_to_analyze($files_to_analyze);
    }
    /**
     * Scans all files their related files
     */
    public function scan_files(int $threads = 1): void
    {
        $has_changes = $this->scanner->scan_files($this->classlikes, $threads);
        if ($has_changes) {
            $this->populator->populate_codebase();
        }
    }
    public function get_file_contents(string $file_path): string
    {
        return $this->file_provider->get_contents($file_path);
    }
    /**
     * @return list<PhpParser\Node\Stmt>
     */
    public function get_statements_for_file(string $file_path, ?Progress $progress = null): array
    {
        return $this->statements_provider->get_statements_for_file($file_path, $this->analysis_php_version_id, $this->diff_methods || $this->diff_run || $this->language_server || $this->file_reference_provider->cache?->persistent, $progress ?? $this->progress);
    }
    public function create_class_like_storage(string $fq_classlike_name): Class_Like_Storage
    {
        return $this->classlike_storage_provider->create($fq_classlike_name);
    }
    public function cache_class_like_storage(Class_Like_Storage $classlike_storage, string $file_path): void
    {
        if (!$this->classlike_storage_provider->cache) {
            return;
        }
        $file_contents = $this->file_provider->get_contents($file_path);
        $this->classlike_storage_provider->cache->write_to_cache($classlike_storage, $file_path, $file_contents);
    }
    public function exhume_class_like_storage(string $fq_classlike_name, string $file_path): void
    {
        $file_contents = $this->file_provider->get_contents($file_path);
        $storage = $this->classlike_storage_provider->exhume($fq_classlike_name, $file_path, $file_contents);
        if ($storage->is_trait) {
            $this->classlikes->add_fully_qualified_trait_name($storage->name, $file_path);
        } elseif ($storage->is_interface) {
            $this->classlikes->add_fully_qualified_interface_name($storage->name, $file_path);
        } elseif ($storage->is_enum) {
            $this->classlikes->add_fully_qualified_enum_name($storage->name, $file_path);
        } else {
            $this->classlikes->add_fully_qualified_class_name($storage->name, $file_path);
        }
    }
    public static function get_psalm_type_from_reflection(?Reflection_Type $type): Union
    {
        return Reflection::get_psalm_type_from_reflection_type($type);
    }
    public function create_file_storage_for_path(string $file_path): File_Storage
    {
        return $this->file_storage_provider->create($file_path);
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function find_references_to_symbol(string $symbol): array
    {
        if (!$this->collect_locations) {
            throw new UnexpectedValueException('Should not be checking references');
        }
        if (str_contains($symbol, '::$')) {
            return $this->find_references_to_property($symbol);
        }
        if (str_contains($symbol, '::')) {
            return $this->find_references_to_method($symbol);
        }
        return $this->find_references_to_class_like($symbol);
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function find_references_to_method(string $method_id): array
    {
        return $this->file_reference_provider->get_class_method_locations(strtolower($method_id));
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function find_references_to_property(string $property_id): array
    {
        /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
        [$fq_class_name, $property_name] = explode('::', $property_id);
        return $this->file_reference_provider->get_class_property_locations(strtolower($fq_class_name) . '::' . $property_name);
    }
    /**
     * @return CodeLocation[]
     * @psalm-return array<int, CodeLocation>
     */
    public function find_references_to_class_like(string $fq_class_name): array
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $locations = $this->file_reference_provider->get_class_locations($fq_class_name_lc);
        if (isset($this->use_referencing_locations[$fq_class_name_lc])) {
            return [...$locations, ...$this->use_referencing_locations[$fq_class_name_lc]];
        }
        return $locations;
    }
    public function get_closure_storage(string $file_path, string $closure_id): Function_Storage
    {
        $file_storage = $this->file_storage_provider->get($file_path);
        // closures can be returned here
        if (isset($file_storage->functions[$closure_id])) {
            return $file_storage->functions[$closure_id];
        }
        throw new UnexpectedValueException('Expecting ' . $closure_id . ' to have storage in ' . $file_path);
    }
    public function add_global_constant_type(string $const_id, Union $type): void
    {
        self::$stubbed_constants[$const_id] = $type;
    }
    public function get_stubbed_constant_type(string $const_id): ?Union
    {
        return self::$stubbed_constants[$const_id] ?? null;
    }
    /**
     * @param array<string, Union> $stubs
     */
    public function add_global_constant_types(array $stubs): void
    {
        self::$stubbed_constants += $stubs;
    }
    /**
     * @return array<string, Union>
     */
    public function get_all_stubbed_constants(): array
    {
        return self::$stubbed_constants;
    }
    public function file_exists(string $file_path): bool
    {
        return $this->file_provider->file_exists($file_path);
    }
    /**
     * Check whether a class/interface exists
     */
    public function class_or_interface_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        return $this->classlikes->class_or_interface_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /**
     * Check whether a class/interface exists
     *
     * @psalm-assert-if-true class-string|interface-string|enum-string $fq_class_name
     */
    public function class_or_interface_or_enum_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        return $this->classlikes->class_or_interface_or_enum_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /** @psalm-mutation-free */
    public function class_extends_or_implements(string $fq_class_name, string $possible_parent): bool
    {
        if ($this->classlikes->class_extends($fq_class_name, $possible_parent)) {
            return true;
        }
        return $this->classlikes->class_implements($fq_class_name, $possible_parent);
    }
    /**
     * Determine whether or not a given class exists
     */
    public function class_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        return $this->classlikes->class_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /**
     * Determine whether or not a class extends a parent
     *
     * @throws UnpopulatedClasslikeException when called on unpopulated class
     * @throws InvalidArgumentException when class does not exist
     */
    public function class_extends(string $fq_class_name, string $possible_parent): bool
    {
        return $this->classlikes->class_extends($fq_class_name, $possible_parent, true);
    }
    /**
     * Check whether a class implements an interface
     */
    public function class_implements(string $fq_class_name, string $interface): bool
    {
        return $this->classlikes->class_implements($fq_class_name, $interface);
    }
    public function interface_exists(string $fq_interface_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        return $this->classlikes->interface_exists($fq_interface_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    public function interface_extends(string $interface_name, string $possible_parent): bool
    {
        return $this->classlikes->interface_extends($interface_name, $possible_parent);
    }
    /**
     * @return array<string, string> all interfaces extended by $interface_name
     */
    public function get_parent_interfaces(string $fq_interface_name): array
    {
        return $this->classlikes->get_parent_interfaces($this->classlikes->get_un_aliased_name($fq_interface_name));
    }
    /**
     * Determine whether or not a class has the correct casing
     */
    public function class_has_correct_casing(string $fq_class_name): bool
    {
        return $this->classlikes->class_has_correct_casing($fq_class_name);
    }
    public function interface_has_correct_casing(string $fq_interface_name): bool
    {
        return $this->classlikes->interface_has_correct_casing($fq_interface_name);
    }
    public function trait_has_correct_casing(string $fq_trait_name): bool
    {
        return $this->classlikes->trait_has_correct_casing($fq_trait_name);
    }
    /**
     * Given a function id, return the function like storage for
     * a method, closure, or function.
     *
     * @param non-empty-string $function_id
     * @return FunctionStorage|MethodStorage
     */
    public function get_function_like_storage(Statements_Analyzer $statements_analyzer, string $function_id): Function_Like_Storage
    {
        $does_method_exist = Method_Identifier::is_valid_method_id_reference($function_id) && $this->method_exists($function_id);
        if ($does_method_exist) {
            $method_id = Method_Identifier::wrap($function_id);
            $declaring_method_id = $this->methods->get_declaring_method_id($method_id);
            if (!$declaring_method_id) {
                throw new UnexpectedValueException('Declaring method for ' . $method_id . ' cannot be found');
            }
            return $this->methods->get_storage($declaring_method_id);
        }
        return $this->functions->get_storage($statements_analyzer, strtolower($function_id));
    }
    /**
     * Whether or not a given method exists
     */
    public function method_exists(string|Method_Identifier $method_id, ?Code_Location $code_location = null, string|Method_Identifier|null $calling_method_id = null, ?string $file_path = null, bool $is_used = true): bool
    {
        return $this->methods->method_exists(Method_Identifier::wrap($method_id), is_string($calling_method_id) ? strtolower($calling_method_id) : strtolower((string) $calling_method_id), $code_location, null, $file_path, true, $is_used);
    }
    /**
     * @return array<int, FunctionLikeParameter>
     */
    public function get_method_params(string|Method_Identifier $method_id): array
    {
        return $this->methods->get_method_params(Method_Identifier::wrap($method_id));
    }
    public function is_variadic(string|Method_Identifier $method_id): bool
    {
        return $this->methods->is_variadic(Method_Identifier::wrap($method_id));
    }
    /**
     * @param  list<Arg> $call_args
     */
    public function get_method_return_type(string|Method_Identifier $method_id, ?string &$self_class, array $call_args = []): ?Union
    {
        return $this->methods->get_method_return_type(Method_Identifier::wrap($method_id), $self_class, null, $call_args);
    }
    public function get_method_returns_by_ref(string|Method_Identifier $method_id): bool
    {
        return $this->methods->get_method_returns_by_ref(Method_Identifier::wrap($method_id));
    }
    public function get_method_return_type_location(string|Method_Identifier $method_id, ?Code_Location &$defined_location = null): ?Code_Location
    {
        return $this->methods->get_method_return_type_location(Method_Identifier::wrap($method_id), $defined_location);
    }
    public function get_declaring_method_id(string|Method_Identifier $method_id): ?string
    {
        $new_method_id = $this->methods->get_declaring_method_id(Method_Identifier::wrap($method_id));
        return $new_method_id ? (string) $new_method_id : null;
    }
    /**
     * Get the class this method appears in (vs is declared in, which could give a trait)
     */
    public function get_appearing_method_id(string|Method_Identifier $method_id): ?string
    {
        $new_method_id = $this->methods->get_appearing_method_id(Method_Identifier::wrap($method_id));
        return $new_method_id ? (string) $new_method_id : null;
    }
    /**
     * @return array<string, MethodIdentifier>
     */
    public function get_overridden_method_ids(string|Method_Identifier $method_id): array
    {
        return $this->methods->get_overridden_method_ids(Method_Identifier::wrap($method_id));
    }
    public function get_cased_method_id(string|Method_Identifier $method_id): string
    {
        return $this->methods->get_cased_method_id(Method_Identifier::wrap($method_id));
    }
    public function invalidate_information_for_file(string $file_path): void
    {
        $this->scanner->remove_file($file_path);
        try {
            $file_storage = $this->file_storage_provider->get($file_path);
        } catch (InvalidArgumentException) {
            return;
        }
        foreach ($file_storage->classlikes_in_file as $fq_classlike_name) {
            $this->classlike_storage_provider->remove($fq_classlike_name);
            $this->classlikes->remove_class_like($fq_classlike_name);
        }
        $this->file_storage_provider->remove($file_path);
    }
    public function get_function_storage_for_symbol(string $file_path, string $symbol): ?Function_Like_Storage
    {
        if (strpos($symbol, '::')) {
            $symbol = substr($symbol, 0, -2);
            /** @psalm-suppress ArgumentTypeCoercion */
            $method_id = new Method_Identifier(...explode('::', $symbol));
            $declaring_method_id = $this->methods->get_declaring_method_id($method_id);
            if (!$declaring_method_id) {
                return null;
            }
            return $this->methods->get_storage($declaring_method_id);
        }
        $function_id = strtolower(substr($symbol, 0, -2));
        $file_storage = $this->file_storage_provider->get($file_path);
        if (isset($file_storage->functions[$function_id])) {
            return $file_storage->functions[$function_id];
        }
        if (!$function_id) {
            return null;
        }
        return $this->functions->get_storage(null, $function_id);
    }
    /**
     * Get Markup content from Reference
     */
    public function get_markup_content_for_symbol_by_reference(Reference $reference): ?Php_Markdown_Content
    {
        //Direct Assignment
        if (is_numeric($reference->symbol[0])) {
            return new Php_Markdown_Content((string) preg_replace('/^[^:]*:/', '', $reference->symbol));
        }
        //Class
        if (strpos($reference->symbol, '::')) {
            //Class Method
            if (strpos($reference->symbol, '()')) {
                $symbol = substr($reference->symbol, 0, -2);
                /** @psalm-suppress ArgumentTypeCoercion */
                $method_id = new Method_Identifier(...explode('::', $symbol));
                $declaring_method_id = $this->methods->get_declaring_method_id($method_id);
                if (!$declaring_method_id) {
                    return null;
                }
                $storage = $this->methods->get_storage($declaring_method_id);
                return new Php_Markdown_Content($storage->get_hover_markdown(), "{$storage->defining_fqcln}::{$storage->cased_name}", $storage->description);
            }
            /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
            [, $symbol_name] = explode('::', $reference->symbol);
            //Class Property
            if (str_contains($reference->symbol, '$')) {
                $property_id = (string) preg_replace('/^\\\\/', '', $reference->symbol);
                /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
                [$fq_class_name, $property_name] = explode('::$', $property_id);
                $class_storage = $this->classlikes->get_storage_for($fq_class_name);
                //Get Real Properties
                if (isset($class_storage->declaring_property_ids[$property_name])) {
                    $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
                    $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);
                    if (isset($declaring_class_storage->properties[$property_name])) {
                        $storage = $declaring_class_storage->properties[$property_name];
                        return new Php_Markdown_Content("{$storage->get_info()} {$symbol_name}", $reference->symbol, $storage->description);
                    }
                }
                //Get Docblock properties
                if (isset($class_storage->pseudo_property_set_types['$' . $property_name])) {
                    return new Php_Markdown_Content('public ' . $class_storage->pseudo_property_set_types['$' . $property_name] . ' $' . $property_name, $reference->symbol);
                }
                //Get Docblock properties
                if (isset($class_storage->pseudo_property_get_types['$' . $property_name])) {
                    return new Php_Markdown_Content('public ' . $class_storage->pseudo_property_get_types['$' . $property_name] . ' $' . $property_name, $reference->symbol);
                }
                return null;
            }
            /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
            [$fq_classlike_name, $const_name] = explode('::', $reference->symbol);
            $class_constants = $this->classlikes->get_constants_for_class($fq_classlike_name, ReflectionProperty::IS_PRIVATE);
            if (!isset($class_constants[$const_name])) {
                return null;
            }
            //Class Constant
            return new Php_Markdown_Content($class_constants[$const_name]->get_hover_markdown($const_name), $fq_classlike_name . '::' . $const_name, $class_constants[$const_name]->description);
        }
        //Procedural Function
        if (strpos($reference->symbol, '()')) {
            $function_id = strtolower(substr($reference->symbol, 0, -2));
            $file_storage = $this->file_storage_provider->get($reference->file_path);
            if (isset($file_storage->functions[$function_id])) {
                $function_storage = $file_storage->functions[$function_id];
                return new Php_Markdown_Content($function_storage->get_hover_markdown(), $function_id, $function_storage->description);
            }
            if (!$function_id) {
                return null;
            }
            $function = $this->functions->get_storage(null, $function_id);
            return new Php_Markdown_Content($function->get_hover_markdown(), $function_id, $function->description);
        }
        //Procedural Variable
        if (str_starts_with($reference->symbol, '$')) {
            $type = Variable_Fetch_Analyzer::get_global_type($reference->symbol, $this->analysis_php_version_id);
            if (!$type->is_mixed()) {
                return new Php_Markdown_Content((string) $type, $reference->symbol);
            }
        }
        try {
            $storage = $this->classlike_storage_provider->get($reference->symbol);
            return new Php_Markdown_Content(($storage->abstract ? 'abstract ' : '') . 'class ' . $storage->name, $storage->name, $storage->description);
        } catch (InvalidArgumentException) {
            //continue on as normal
        }
        if (strpos($reference->symbol, '\\')) {
            $const_name_parts = explode('\\', $reference->symbol);
            $const_name = array_pop($const_name_parts);
            $namespace_name = implode('\\', $const_name_parts);
            $namespace_constants = Namespace_Analyzer::get_constants_for_namespace($namespace_name, ReflectionProperty::IS_PUBLIC);
            //Namespace Constant
            if (isset($namespace_constants[$const_name])) {
                $type = $namespace_constants[$const_name];
                return new Php_Markdown_Content($reference->symbol . ' ' . $type, $reference->symbol);
            }
        } else {
            $file_storage = $this->file_storage_provider->get($reference->file_path);
            // ?
            if (isset($file_storage->constants[$reference->symbol])) {
                return new Php_Markdown_Content('const ' . $reference->symbol . ' ' . $file_storage->constants[$reference->symbol], $reference->symbol);
            }
            $type = Const_Fetch_Analyzer::get_global_const_type($this, $reference->symbol, $reference->symbol);
            //Global Constant
            if ($type) {
                return new Php_Markdown_Content('const ' . $reference->symbol . ' ' . $type, $reference->symbol);
            }
        }
        return new Php_Markdown_Content($reference->symbol);
    }
    public function get_symbol_location_by_reference(Reference $reference): ?Code_Location
    {
        if (is_numeric($reference->symbol[0])) {
            $symbol = (string) preg_replace('/:.*/', '', $reference->symbol);
            $symbol_parts = explode('-', $symbol);
            if (!isset($symbol_parts[0]) || !isset($symbol_parts[1])) {
                return null;
            }
            $file_contents = $this->get_file_contents($reference->file_path);
            return new Raw($file_contents, $reference->file_path, $this->config->shorten_file_name($reference->file_path), (int) $symbol_parts[0], (int) $symbol_parts[1]);
        }
        try {
            if (strpos($reference->symbol, '::')) {
                if (strpos($reference->symbol, '()')) {
                    $symbol = substr($reference->symbol, 0, -2);
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $method_id = new Method_Identifier(...explode('::', $symbol));
                    $declaring_method_id = $this->methods->get_declaring_method_id($method_id);
                    if (!$declaring_method_id) {
                        return null;
                    }
                    $storage = $this->methods->get_storage($declaring_method_id);
                    return $storage->location;
                }
                if (str_contains($reference->symbol, '$')) {
                    $storage = $this->properties->get_storage($reference->symbol);
                    return $storage->location;
                }
                /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
                [$fq_classlike_name, $const_name] = explode('::', $reference->symbol);
                $class_constants = $this->classlikes->get_constants_for_class($fq_classlike_name, ReflectionProperty::IS_PRIVATE);
                if (!isset($class_constants[$const_name])) {
                    return null;
                }
                return $class_constants[$const_name]->location;
            }
            if (strpos($reference->symbol, '()')) {
                $file_storage = $this->file_storage_provider->get($reference->file_path);
                $function_id = strtolower(substr($reference->symbol, 0, -2));
                if (isset($file_storage->functions[$function_id])) {
                    return $file_storage->functions[$function_id]->location;
                }
                if (!$function_id) {
                    return null;
                }
                return $this->functions->get_storage(null, $function_id)->location;
            }
            return $this->classlike_storage_provider->get($reference->symbol)->location;
        } catch (UnexpectedValueException $e) {
            error_log($e->get_message());
            return null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
    /**
     * @return array{0: string, 1: Range}|null
     */
    public function get_reference_at_position(string $file_path, Position $position): ?array
    {
        $ref = $this->get_reference_at_position_as_reference($file_path, $position);
        if ($ref === null) {
            return null;
        }
        return [$ref->symbol, $ref->range];
    }
    /**
     * Get Reference from Position
     */
    public function get_reference_at_position_as_reference(string $file_path, Position $position): ?Reference
    {
        $is_open = $this->file_provider->is_open($file_path);
        if (!$is_open) {
            throw new Unanalyzed_File_Exception($file_path . ' is not open');
        }
        $file_contents = $this->get_file_contents($file_path);
        $offset = $position->to_offset($file_contents);
        $reference_maps = $this->analyzer->get_maps_for_file($file_path);
        $reference_start_pos = null;
        $reference_end_pos = null;
        $symbol = null;
        foreach ($reference_maps as $reference_map) {
            ksort($reference_map);
            foreach ($reference_map as $start_pos => [$end_pos, $possible_reference]) {
                if ($offset < $start_pos) {
                    break;
                }
                if ($offset > $end_pos) {
                    continue;
                }
                $reference_start_pos = $start_pos;
                $reference_end_pos = $end_pos;
                $symbol = $possible_reference;
            }
            if ($symbol !== null && $reference_start_pos !== null && $reference_end_pos !== null) {
                break;
            }
        }
        if ($symbol === null || $reference_start_pos === null || $reference_end_pos === null) {
            return null;
        }
        $range = new Range(self::get_position_from_offset($reference_start_pos, $file_contents), self::get_position_from_offset($reference_end_pos, $file_contents));
        return new Reference($file_path, $symbol, $range);
    }
    /**
     * @return array{0: non-empty-string, 1: int, 2: Range}|null
     */
    public function get_function_argument_at_position(string $file_path, Position $position): ?array
    {
        $is_open = $this->file_provider->is_open($file_path);
        if (!$is_open) {
            throw new Unanalyzed_File_Exception($file_path . ' is not open');
        }
        $file_contents = $this->get_file_contents($file_path);
        $offset = $position->to_offset($file_contents);
        [, , $argument_map] = $this->analyzer->get_maps_for_file($file_path);
        $reference = null;
        $argument_number = null;
        if (!$argument_map) {
            return null;
        }
        $start_pos = null;
        $end_pos = null;
        ksort($argument_map);
        foreach ($argument_map as $start_pos => [$end_pos, $possible_reference, $possible_argument_number]) {
            if ($offset < $start_pos) {
                break;
            }
            if ($offset > $end_pos) {
                continue;
            }
            $reference = $possible_reference;
            $argument_number = $possible_argument_number;
        }
        if ($reference === null || $start_pos === null || $end_pos === null || $argument_number === null) {
            return null;
        }
        $range = new Range(self::get_position_from_offset($start_pos, $file_contents), self::get_position_from_offset($end_pos, $file_contents));
        return [$reference, $argument_number, $range];
    }
    /**
     * @param  non-empty-string $function_symbol
     */
    public function get_signature_information(string $function_symbol, ?string $file_path = null): ?Signature_Information
    {
        $signature_label = '';
        $signature_documentation = null;
        if (str_contains($function_symbol, '::')) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $method_id = new Method_Identifier(...explode('::', $function_symbol));
            $declaring_method_id = $this->methods->get_declaring_method_id($method_id);
            if ($declaring_method_id === null) {
                return null;
            }
            $method_storage = $this->methods->get_storage($declaring_method_id);
            $params = $method_storage->params;
            $signature_label = $method_storage->cased_name;
            $signature_documentation = $method_storage->description;
        } else {
            try {
                if ($file_path) {
                    $function_storage = $this->functions->get_storage(null, strtolower($function_symbol), dirname($file_path), $file_path);
                } else {
                    $function_storage = $this->functions->get_storage(null, strtolower($function_symbol));
                }
                $params = $function_storage->params;
                $signature_label = $function_storage->cased_name;
                $signature_documentation = $function_storage->description;
            } catch (Exception) {
                if (Internal_Call_Map_Handler::in_call_map($function_symbol)) {
                    $callables = Internal_Call_Map_Handler::get_callables_from_call_map($function_symbol);
                    if (!$callables || !isset($callables[0]->params)) {
                        return null;
                    }
                    $params = $callables[0]->params;
                } else {
                    return null;
                }
            }
        }
        $signature_label .= '(';
        $parameters = [];
        foreach ($params as $i => $param) {
            $parameter_label = ($param->type ?: 'mixed') . ' $' . $param->name;
            $parameters[] = new Parameter_Information([strlen($signature_label), strlen($signature_label) + strlen($parameter_label)], $param->description ?? null);
            $signature_label .= $parameter_label;
            if ($i < count($params) - 1) {
                $signature_label .= ', ';
            }
        }
        $signature_label .= ')';
        return new Signature_Information($signature_label, $parameters, $signature_documentation);
    }
    /**
     * @return array{0: string, 1: '->'|'::'|'['|'symbol', 2: int}|null
     */
    public function get_completion_data_at_position(string $file_path, Position $position): ?array
    {
        $is_open = $this->file_provider->is_open($file_path);
        if (!$is_open) {
            throw new Unanalyzed_File_Exception($file_path . ' is not open');
        }
        $file_contents = $this->get_file_contents($file_path);
        $offset = $position->to_offset($file_contents);
        $literal_part = $this->get_begined_literal_part($file_path, $position);
        $begin_literal_offset = $offset - strlen($literal_part);
        [$reference_map, $type_map] = $this->analyzer->get_maps_for_file($file_path);
        if (!$reference_map && !$type_map) {
            return null;
        }
        krsort($type_map);
        foreach ($type_map as $start_pos => [$end_pos_excluding_whitespace, $possible_type]) {
            if ($offset < $start_pos) {
                continue;
            }
            /** @psalm-suppress PossiblyUndefinedIntArrayOffset */
            $num_whitespace_bytes = preg_match('/\G\s+/', $file_contents, $matches, 0, $end_pos_excluding_whitespace) ? strlen($matches[0]) : 0;
            $end_pos = $end_pos_excluding_whitespace + $num_whitespace_bytes;
            if ($offset - $end_pos === 1) {
                $candidate_gap = substr($file_contents, $end_pos, 1);
                if ($candidate_gap === '[') {
                    $gap = $candidate_gap;
                    $recent_type = $possible_type;
                    if ($recent_type === 'mixed') {
                        return null;
                    }
                    return [$recent_type, $gap, $offset];
                }
            }
            if ($begin_literal_offset - $end_pos === 2) {
                $candidate_gap = substr($file_contents, $end_pos, 2);
                if ($candidate_gap === '->' || $candidate_gap === '::') {
                    $gap = $candidate_gap;
                    $recent_type = $possible_type;
                    if ($recent_type === 'mixed') {
                        return null;
                    }
                    return [$recent_type, $gap, $offset];
                }
            }
        }
        foreach ($reference_map as $start_pos => [$end_pos, $possible_reference]) {
            if ($offset < $start_pos) {
                continue;
            }
            // If the reference precedes a "::" then treat it as a class reference.
            if ($offset - $end_pos === 2 && substr($file_contents, $end_pos, 2) === '::') {
                return [$possible_reference, '::', $offset];
            }
            if ($offset <= $end_pos && substr($file_contents, $begin_literal_offset - 2, 2) === '::') {
                $class_name = explode('::', (string) $possible_reference)[0];
                return [$class_name, '::', $offset];
            }
            // Only continue for references that are partial / don't exist.
            if ($possible_reference[0] !== '*') {
                continue;
            }
            if ($offset - $end_pos === 0) {
                $recent_type = $possible_reference;
                return [$recent_type, 'symbol', $offset];
            }
        }
        return null;
    }
    public function get_begined_literal_part(string $file_path, Position $position): string
    {
        $is_open = $this->file_provider->is_open($file_path);
        if (!$is_open) {
            throw new Unanalyzed_File_Exception($file_path . ' is not open');
        }
        $file_contents = $this->get_file_contents($file_path);
        $offset = $position->to_offset($file_contents);
        preg_match('/\$?\w+$/', substr($file_contents, 0, $offset), $matches);
        return $matches[0] ?? '';
    }
    public function get_type_context_at_position(string $file_path, Position $position): ?Union
    {
        $file_contents = $this->get_file_contents($file_path);
        $offset = $position->to_offset($file_contents);
        [$reference_map, $type_map, $argument_map] = $this->analyzer->get_maps_for_file($file_path);
        if (!$reference_map && !$type_map && !$argument_map) {
            return null;
        }
        foreach ($argument_map as $start_pos => [$end_pos, $function, $argument_num]) {
            if ($offset < $start_pos) {
                continue;
            }
            if ($offset > $end_pos) {
                continue;
            }
            // First parameter to a function-like
            $function_storage = $this->get_function_storage_for_symbol($file_path, $function . '()');
            if (!$function_storage || !$function_storage->params || !isset($function_storage->params[$argument_num])) {
                return null;
            }
            return $function_storage->params[$argument_num]->type;
        }
        return null;
    }
    /**
     * @param list<int> $allow_visibilities
     * @param list<string> $ignore_fq_class_names
     * @return list<CompletionItem>
     */
    public function get_completion_items_for_classish_thing(string $type_string, string $gap, bool $snippets_supported = false, ?array $allow_visibilities = null, array $ignore_fq_class_names = []): array
    {
        if ($allow_visibilities === null) {
            $allow_visibilities = [Class_Like_Analyzer::VISIBILITY_PUBLIC, Class_Like_Analyzer::VISIBILITY_PROTECTED, Class_Like_Analyzer::VISIBILITY_PRIVATE];
        }
        $allow_visibilities[] = null;
        $completion_items = [];
        $type = Type::parse_string($type_string);
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Named_Object) {
                try {
                    $class_storage = $this->classlike_storage_provider->get($atomic_type->value);
                    $method_storages = [];
                    foreach ($class_storage->declaring_method_ids as $declaring_method_id) {
                        try {
                            $method_storages[] = $this->methods->get_storage($declaring_method_id);
                        } catch (UnexpectedValueException $e) {
                            error_log($e->get_message());
                        }
                    }
                    if ($gap === '->') {
                        $method_storages += $class_storage->pseudo_methods;
                    }
                    if ($gap === '::') {
                        $method_storages += $class_storage->pseudo_static_methods;
                    }
                    $had = [];
                    foreach ($method_storages as $method_storage) {
                        if (!in_array($method_storage->visibility, $allow_visibilities)) {
                            continue;
                        }
                        if ($method_storage->cased_name !== null) {
                            if (array_key_exists($method_storage->cased_name, $had)) {
                                continue;
                            }
                            $had[$method_storage->cased_name] = true;
                        }
                        if ($method_storage->is_static || $gap === '->') {
                            $completion_item = new Completion_Item($method_storage->cased_name, Completion_Item_Kind::METHOD, $method_storage->get_completion_signature(), $method_storage->description, (string) $method_storage->visibility, $method_storage->cased_name, $method_storage->cased_name, null, null, new Command('Trigger parameter hints', 'editor.action.triggerParameterHints'), null, 2);
                            if ($snippets_supported && count($method_storage->params) > 0) {
                                $completion_item->insert_text .= '($0)';
                                $completion_item->insert_text_format = Insert_Text_Format::SNIPPET;
                            } else {
                                $completion_item->insert_text .= '()';
                            }
                            $completion_items[] = $completion_item;
                        }
                    }
                    if ($gap === '->') {
                        $pseudo_property_types = [];
                        foreach ($class_storage->pseudo_property_get_types as $property_name => $type) {
                            $pseudo_property_types[$property_name] = new Completion_Item(
                                str_replace('$', '', $property_name),
                                Completion_Item_Kind::PROPERTY,
                                $type->__toString(),
                                null,
                                '1',
                                //sort text
                                str_replace('$', '', $property_name),
                                str_replace('$', '', $property_name)
                            );
                        }
                        foreach ($class_storage->pseudo_property_set_types as $property_name => $type) {
                            $pseudo_property_types[$property_name] = new Completion_Item(str_replace('$', '', $property_name), Completion_Item_Kind::PROPERTY, $type->__toString(), null, '1', str_replace('$', '', $property_name), str_replace('$', '', $property_name));
                        }
                        $completion_items = [...$completion_items, ...array_values($pseudo_property_types)];
                    }
                    foreach ($class_storage->declaring_property_ids as $property_name => $declaring_class) {
                        try {
                            $property_storage = $this->properties->get_storage($declaring_class . '::$' . $property_name);
                        } catch (UnexpectedValueException $e) {
                            error_log($e->get_message());
                            continue;
                        }
                        if (!in_array($property_storage->visibility, $allow_visibilities)) {
                            continue;
                        }
                        if ($property_storage->is_static === ($gap === '::')) {
                            $completion_items[] = new Completion_Item($property_name, Completion_Item_Kind::PROPERTY, $property_storage->get_info(), $property_storage->description, (string) $property_storage->visibility, $property_name, ($gap === '::' ? '$' : '') . $property_name);
                        }
                    }
                    foreach ($class_storage->constants as $const_name => $const) {
                        $completion_items[] = new Completion_Item($const_name, Completion_Item_Kind::VARIABLE, 'const ' . $const_name, $const->description, null, $const_name, $const_name);
                    }
                    if ($gap === '->') {
                        foreach ($class_storage->named_mixins as $mixin) {
                            if (in_array($mixin->value, $ignore_fq_class_names)) {
                                continue;
                            }
                            $mixin_completion_items = $this->get_completion_items_for_classish_thing($mixin->value, $gap, $snippets_supported, [Class_Like_Analyzer::VISIBILITY_PUBLIC], [$type_string, ...$ignore_fq_class_names]);
                            $completion_items = [...$completion_items, ...$mixin_completion_items];
                        }
                    }
                } catch (Exception $e) {
                    error_log($e->get_message());
                    continue;
                }
            }
        }
        return $completion_items;
    }
    /**
     * @param list<CompletionItem> $items
     * @return list<CompletionItem>
     * @deprecated to be removed in Psalm 6
     * @api fix deprecation problem "PossiblyUnusedMethod: Cannot find any calls to method"
     */
    public function filter_completion_items_by_begin_literal_part(array $items, string $literal_part): array
    {
        if (!$literal_part) {
            return $items;
        }
        $res = [];
        foreach ($items as $item) {
            if ($item->insert_text && str_starts_with((string) $item->insert_text, $literal_part)) {
                $res[] = $item;
            }
        }
        return $res;
    }
    /**
     * @return list<CompletionItem>
     */
    public function get_completion_items_for_partial_symbol(string $type_string, int $offset, string $file_path): array
    {
        $fq_suggestion = false;
        if (($type_string[1] ?? '') === '\\') {
            $fq_suggestion = true;
        }
        $matching_classlike_names = $this->classlikes->get_matching_class_like_names($type_string);
        $completion_items = [];
        $file_storage = $this->file_storage_provider->get($file_path);
        $aliases = null;
        foreach ($file_storage->classlikes_in_file as $fq_class_name => $_) {
            try {
                $class_storage = $this->classlike_storage_provider->get($fq_class_name);
            } catch (Exception) {
                continue;
            }
            if (!$class_storage->stmt_location) {
                continue;
            }
            if ($offset > $class_storage->stmt_location->raw_file_start && $offset < $class_storage->stmt_location->raw_file_end) {
                $aliases = $class_storage->aliases;
                break;
            }
        }
        if (!$aliases) {
            foreach ($file_storage->namespace_aliases as $namespace_start => $namespace_aliases) {
                if ($namespace_start < $offset) {
                    $aliases = $namespace_aliases;
                    break;
                }
            }
            if (!$aliases) {
                $aliases = $file_storage->aliases;
            }
        }
        foreach ($matching_classlike_names as $fq_class_name) {
            $extra_edits = [];
            $insertion_text = Type::get_string_from_fqcln($fq_class_name, $aliases && $aliases->namespace ? $aliases->namespace : null, $aliases->uses_flipped ?? [], null);
            if ($aliases && !$fq_suggestion && $aliases->namespace && $insertion_text === '\\' . $fq_class_name && $aliases->namespace_first_stmt_start) {
                $file_contents = $this->get_file_contents($file_path);
                $class_name = (string) preg_replace('/^.*\\\\/', '', $fq_class_name, 1);
                if ($aliases->uses_end) {
                    $position = self::get_position_from_offset($aliases->uses_end, $file_contents);
                    $extra_edits[] = new Text_Edit(new Range($position, $position), "\n" . 'use ' . $fq_class_name . ';');
                } else {
                    $position = self::get_position_from_offset($aliases->namespace_first_stmt_start, $file_contents);
                    $extra_edits[] = new Text_Edit(new Range($position, $position), 'use ' . $fq_class_name . ';' . "\n" . "\n");
                }
                $insertion_text = $class_name;
            }
            try {
                $class_storage = $this->classlike_storage_provider->get($fq_class_name);
                $description = $class_storage->description;
            } catch (Exception) {
                $description = null;
            }
            $completion_items[] = new Completion_Item($fq_class_name, Completion_Item_Kind::CLASS_, null, $description, null, $fq_class_name, $insertion_text, null, $extra_edits);
        }
        $functions = $this->functions->get_matching_function_names($type_string, $offset, $file_path, $this);
        $namespace_map = [];
        if ($aliases) {
            $namespace_map += $aliases->uses_flipped;
            if ($aliases->namespace) {
                $namespace_map[$aliases->namespace] = '';
            }
        }
        // Sort the map by longest first, so we replace most specific
        // used namespaces first.
        ksort($namespace_map);
        $namespace_map = array_reverse($namespace_map);
        foreach ($functions as $function_lowercase => $function) {
            // Transform FQFN relative to all uses namespaces
            $function_name = $function->cased_name;
            if (!$function_name) {
                continue;
            }
            $in_namespace_map = false;
            foreach ($namespace_map as $namespace_name => $namespace_alias) {
                if (str_starts_with($function_lowercase, $namespace_name . '\\')) {
                    $function_name = $namespace_alias . '\\' . substr($function_name, strlen($namespace_name) + 1);
                    $in_namespace_map = true;
                }
            }
            // If the function is not use'd, and it's not a global function
            // prepend it with a backslash.
            if (!$in_namespace_map && str_contains($function_name, '\\')) {
                $function_name = '\\' . $function_name;
            }
            $completion_items[] = new Completion_Item($function_name, Completion_Item_Kind::FUNCTION, $function->get_completion_signature(), $function->description, null, $function_name, $function_name . (count($function->params) !== 0 ? '($0)' : '()'), null, null, new Command('Trigger parameter hints', 'editor.action.triggerParameterHints'), null, 2);
        }
        return $completion_items;
    }
    /**
     * @return list<CompletionItem>
     */
    public function get_completion_items_for_type(Union $type): array
    {
        $completion_items = [];
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Bool) {
                $bools = (string) $atomic_type === 'bool' ? ['true', 'false'] : [(string) $atomic_type];
                foreach ($bools as $property_name) {
                    $completion_items[] = new Completion_Item($property_name, Completion_Item_Kind::VALUE, 'bool', null, null, null, $property_name);
                }
            } elseif ($atomic_type instanceof T_Literal_String) {
                $completion_items[] = new Completion_Item($atomic_type->value, Completion_Item_Kind::VALUE, $atomic_type->get_id(), null, null, null, "'{$atomic_type->value}'");
            } elseif ($atomic_type instanceof T_Literal_Int) {
                $completion_items[] = new Completion_Item((string) $atomic_type->value, Completion_Item_Kind::VALUE, $atomic_type->get_id(), null, null, null, (string) $atomic_type->value);
            } elseif ($atomic_type instanceof T_Class_Constant) {
                $const = $atomic_type->fq_classlike_name . '::' . $atomic_type->const_name;
                $completion_items[] = new Completion_Item($const, Completion_Item_Kind::VALUE, $atomic_type->get_id(), null, null, null, $const);
            }
        }
        return $completion_items;
    }
    /**
     * @return list<CompletionItem>
     */
    public function get_completion_items_for_array_keys(string $type_string): array
    {
        $completion_items = [];
        $type = Type::parse_string($type_string);
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Keyed_Array) {
                foreach ($atomic_type->properties as $property_name => $property) {
                    $completion_items[] = new Completion_Item((string) $property_name, Completion_Item_Kind::PROPERTY, (string) $property, null, null, null, "'{$property_name}'");
                }
            }
        }
        return $completion_items;
    }
    private static function get_position_from_offset(int $offset, string $file_contents): Position
    {
        $file_contents = substr($file_contents, 0, $offset);
        $offset_length = $offset - strlen($file_contents);
        //PHP 8.0: Argument #3 ($offset) must be contained in argument #1 ($haystack)
        if (($textlen = strlen($file_contents)) < $offset_length) {
            $offset_length = $textlen;
        }
        $before_newline_count = strrpos($file_contents, "\n", $offset_length);
        return new Position(substr_count($file_contents, "\n"), $offset - (int) $before_newline_count - 1);
    }
    public function add_temporary_file_changes(string $file_path, string $new_content, ?int $version = null): void
    {
        $this->file_provider->add_temporary_file_changes($file_path, $new_content, $version);
    }
    public function remove_temporary_file_changes(string $file_path): void
    {
        $this->file_provider->remove_temporary_file_changes($file_path);
    }
    /**
     * Checks if type is a subtype of other
     *
     * Given two types, checks if `$input_type` is a subtype of `$container_type`.
     * If you consider `Union` as a set of types, this will tell you
     * if `$input_type` is fully contained in `$container_type`,
     *
     * $input_type ⊆ $container_type
     *
     * Useful for emitting issues like InvalidArgument, where argument at the call site
     * should be a subset of the function parameter type.
     */
    public function is_type_contained_by_type(Union $input_type, Union $container_type, bool $ignore_null = false, bool $ignore_false = false, bool $allow_interface_equality = false, bool $allow_float_int_equality = true): bool
    {
        return Union_Type_Comparator::is_contained_by($this, $input_type, $container_type, $ignore_null, $ignore_false, null, $allow_interface_equality, $allow_float_int_equality);
    }
    /**
     * Checks if type has any part that is a subtype of other
     *
     * Given two types, checks if *any part* of `$input_type` is a subtype of `$container_type`.
     * If you consider `Union` as a set of types, this will tell you if intersection
     * of `$input_type` with `$container_type` is not empty.
     *
     * $input_type ∩ $container_type ≠ ∅ , e.g. they are not disjoint.
     *
     * Useful for emitting issues like PossiblyInvalidArgument, where argument at the call
     * site should be a subtype of the function parameter type, but it's has some types that are
     * not a subtype of the required type.
     */
    public function can_type_be_contained_by_type(Union $input_type, Union $container_type): bool
    {
        return Union_Type_Comparator::can_be_contained_by($this, $input_type, $container_type);
    }
    /**
     * Extracts key and value types from a traversable object (or iterable)
     *
     * Given an iterable type (*but not TArray*) returns a tuple of it's key/value types.
     * First element of the tuple holds key type, second has the value type.
     *
     * Example:
     * ```php
     * $codebase->getKeyValueParamsForTraversableObject(Type::parseString('iterable<int,string>'))
     * //  returns [Union(TInt), Union(TString)]
     * ```
     *
     * @return array{Union, Union}
     */
    public function get_key_value_params_for_traversable_object(Atomic $type): array
    {
        $key_type = null;
        $value_type = null;
        Foreach_Analyzer::get_key_value_params_for_traversable_object($type, $this, $key_type, $value_type);
        return [$key_type ?? Type::get_mixed(), $value_type ?? Type::get_mixed()];
    }
    /**
     * @param array<string, mixed> $phantom_classes
     */
    public function queue_class_like_for_scanning(string $fq_classlike_name, bool $analyze_too = false, bool $store_failure = true, array $phantom_classes = []): void
    {
        $this->scanner->queue_class_like_for_scanning($fq_classlike_name, $analyze_too, $store_failure, $phantom_classes);
    }
    /**
     * @param array<string> $taints
     */
    public function add_taint_source(Union $expr_type, string $taint_id, array $taints = Taint_Kind_Group::ALL_INPUT, ?Code_Location $code_location = null): Union
    {
        if (!$this->taint_flow_graph) {
            return $expr_type;
        }
        $source = new Taint_Source($taint_id, $taint_id, $code_location, null, $taints);
        $this->taint_flow_graph->add_source($source);
        return $expr_type->add_parent_nodes([$source->id => $source]);
    }
    /**
     * @param array<string> $taints
     */
    public function add_taint_sink(string $taint_id, array $taints = Taint_Kind_Group::ALL_INPUT, ?Code_Location $code_location = null): void
    {
        if (!$this->taint_flow_graph) {
            return;
        }
        $sink = new Taint_Sink($taint_id, $taint_id, $code_location, null, $taints);
        $this->taint_flow_graph->add_sink($sink);
    }
    public function get_minor_analysis_php_version(): int
    {
        return self::transform_php_version_id($this->analysis_php_version_id % 10000, 100);
    }
    public function get_major_analysis_php_version(): int
    {
        return self::transform_php_version_id($this->analysis_php_version_id, 10000);
    }
    public static function transform_php_version_id(int $php_version_id, int $div): int
    {
        return intdiv($php_version_id, $div);
    }
}