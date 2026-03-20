<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use InvalidArgumentException;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Exception\Refactor_Exception;
use Psalm\Exception\Unsupported_Issue_To_Fix_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Language_Server\Language_Server;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\Parser_Cache_Provider;
use Psalm\Internal\Provider\Project_Cache_Provider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Provider\Statements_Provider;
use Psalm\Issue\Class_Must_Be_Final;
use Psalm\Issue\Code_Issue;
use Psalm\Issue\Invalid_Falsable_Return_Type;
use Psalm\Issue\Invalid_Nullable_Return_Type;
use Psalm\Issue\Invalid_Return_Type;
use Psalm\Issue\Less_Specific_Return_Type;
use Psalm\Issue\Mismatching_Docblock_Param_Type;
use Psalm\Issue\Mismatching_Docblock_Return_Type;
use Psalm\Issue\Missing_Closure_Return_Type;
use Psalm\Issue\Missing_Override_Attribute;
use Psalm\Issue\Missing_Param_Type;
use Psalm\Issue\Missing_Property_Type;
use Psalm\Issue\Missing_Return_Type;
use Psalm\Issue\Param_Name_Mismatch;
use Psalm\Issue\Possibly_Undefined_Global_Variable;
use Psalm\Issue\Possibly_Undefined_Variable;
use Psalm\Issue\Possibly_Unused_Method;
use Psalm\Issue\Possibly_Unused_Property;
use Psalm\Issue\Redundant_Cast;
use Psalm\Issue\Redundant_Cast_Given_Docblock_Type;
use Psalm\Issue\Unnecessary_Var_Annotation;
use Psalm\Issue\Unused_Method;
use Psalm\Issue\Unused_Property;
use Psalm\Issue\Unused_Variable;
use Psalm\Plugin\Event_Handler\Event\After_Codebase_Populated_Event;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use Psalm\Report;
use Psalm\Report\Report_Options;
use Psalm\Type;
use ReflectionProperty;
use UnexpectedValueException;
use function array_combine;
use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function count;
use function dirname;
use function end;
use function explode;
use function file_exists;
use function fwrite;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function microtime;
use function mkdir;
use function number_format;
use function preg_match;
use function rename;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function usort;
use const PHP_EOL;
use const STDERR;
/**
 * @internal
 */
final class Project_Analyzer
{
    /**
     * Cached config
     */
    private readonly Config $config;
    public static Project_Analyzer $instance;
    /**
     * An object representing everything we know about the code
     */
    private readonly Codebase $codebase;
    private readonly File_Provider $file_provider;
    private readonly Class_Like_Storage_Provider $classlike_storage_provider;
    private ?Parser_Cache_Provider $parser_cache_provider = null;
    public ?Project_Cache_Provider $project_cache_provider = null;
    private readonly File_Reference_Provider $file_reference_provider;
    public Progress $progress;
    public bool $debug_lines = false;
    public bool $debug_performance = false;
    public bool $show_issues = true;
    /**
     * @var array<string, bool>
     */
    private array $issues_to_fix = [];
    public bool $dry_run = false;
    public bool $full_run = false;
    public bool $only_replace_php_types_with_non_docblock_types = false;
    public ?int $onchange_line_limit = null;
    public bool $provide_completion = false;
    /**
     * @var list<string>
     */
    public array $check_paths_files = [];
    /**
     * @var array<string,string>
     */
    private array $project_files = [];
    /**
     * @var array<string,string>
     */
    private array $extra_files = [];
    /**
     * @var array<string, string>
     */
    private array $to_refactor = [];
    /**
     * @var array<int, class-string<CodeIssue>>
     */
    private const SUPPORTED_ISSUES_TO_FIX = [Class_Must_Be_Final::class, Missing_Override_Attribute::class, Invalid_Falsable_Return_Type::class, Invalid_Nullable_Return_Type::class, Invalid_Return_Type::class, Less_Specific_Return_Type::class, Mismatching_Docblock_Param_Type::class, Mismatching_Docblock_Return_Type::class, Missing_Closure_Return_Type::class, Missing_Param_Type::class, Missing_Property_Type::class, Missing_Return_Type::class, Param_Name_Mismatch::class, Possibly_Undefined_Global_Variable::class, Possibly_Undefined_Variable::class, Possibly_Unused_Method::class, Possibly_Unused_Property::class, Redundant_Cast::class, Redundant_Cast_Given_Docblock_Type::class, Unused_Method::class, Unused_Property::class, Unused_Variable::class, Unnecessary_Var_Annotation::class];
    private const PHP_VERSION_REGEX = '^(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\..*)?$';
    private const PHP_SUPPORTED_VERSIONS_REGEX = '^(5\.[456]|7\.[01234]|8\.[012345])(\..*)?$';
    /**
     * @param array<ReportOptions> $generated_report_options
     */
    public function __construct(
        Config $config,
        Providers $providers,
        public ?Report_Options $stdout_report_options = null,
        public array $generated_report_options = [],
        /** @var int<1, max> */
        public int $threads = 1,
        /** @var int<1, max> */
        public int $scan_threads = 1,
        ?Progress $progress = null,
        ?Codebase $codebase = null
    )
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        if ($codebase === null) {
            $codebase = new Codebase($config, $providers, $progress);
        }
        $this->parser_cache_provider = $providers->parser_cache_provider;
        $this->project_cache_provider = $providers->project_cache_provider;
        $this->file_provider = $providers->file_provider;
        $this->classlike_storage_provider = $providers->classlike_storage_provider;
        $this->file_reference_provider = $providers->file_reference_provider;
        $this->progress = $progress;
        $this->config = $config;
        $this->codebase = $codebase;
        $this->config->process_plugin_file_extensions($this);
        $file_extensions = $this->config->get_file_extensions();
        foreach ($this->config->get_project_directories() as $dir_name) {
            $file_paths = $this->file_provider->get_files_in_dir($dir_name, $file_extensions, $this->config->is_in_project_dirs(...));
            foreach ($file_paths as $file_path) {
                $this->project_files[$file_path] = $file_path;
            }
        }
        foreach ($this->config->get_extra_directories() as $dir_name) {
            $file_paths = $this->file_provider->get_files_in_dir($dir_name, $file_extensions, $this->config->is_in_extra_dirs(...));
            foreach ($file_paths as $file_path) {
                $this->extra_files[$file_path] = $file_path;
            }
        }
        foreach ($this->config->get_project_files() as $file_path) {
            $this->project_files[$file_path] = $file_path;
        }
        self::$instance = $this;
    }
    /**
     * @param  array<string>  $report_file_paths
     * @return list<ReportOptions>
     */
    public static function get_file_report_options(array $report_file_paths, bool $show_info = true): array
    {
        $report_options = [];
        $mapping = Report::get_mapping();
        foreach ($report_file_paths as $report_file_path) {
            foreach ($mapping as $extension => $type) {
                if (str_ends_with($report_file_path, $extension)) {
                    $o = new Report_Options();
                    $o->format = $type;
                    $o->show_info = $show_info;
                    $o->output_path = $report_file_path;
                    $o->use_color = false;
                    $report_options[] = $o;
                    continue 2;
                }
            }
            throw new UnexpectedValueException('Unknown report format ' . $report_file_path);
        }
        return $report_options;
    }
    private function visit_autoload_files(): void
    {
        $start_time = microtime(true);
        $this->config->visit_composer_autoload_files($this, $this->progress);
        $now_time = microtime(true);
        $this->progress->debug('Visiting autoload files took ' . number_format($now_time - $start_time, 3) . 's' . PHP_EOL);
    }
    public function server_mode(Language_Server $server): void
    {
        $server->log_info("Initializing: Visiting Autoload Files...");
        $this->visit_autoload_files();
        $this->codebase->diff_methods = true;
        $server->log_info("Initializing: Loading Reference Cache...");
        $this->file_reference_provider->load_reference_cache();
        $this->codebase->enter_server_mode();
        $server->log_info("Initializing: Initialize Plugins...");
        $this->config->initialize_plugins($this);
        foreach ($this->config->get_project_directories() as $dir_name) {
            $this->check_dir_with_config($dir_name, $this->config);
        }
    }
    /** @psalm-mutation-free */
    public static function get_instance(): Project_Analyzer
    {
        /** @psalm-suppress ImpureStaticProperty */
        return self::$instance;
    }
    /** @psalm-mutation-free */
    public function can_report_issues(string $file_path): bool
    {
        return isset($this->project_files[$file_path]);
    }
    private function generate_php_version_message(): string
    {
        $codebase = $this->codebase;
        switch ($codebase->php_version_source) {
            case 'cli':
                $source = '(set by CLI argument)';
                break;
            case 'config':
                $source = '(set by config file)';
                break;
            case 'composer':
                $source = '(inferred from composer.json)';
                break;
            case 'tests':
                $source = '(set by tests)';
                break;
            case 'runtime':
                $source = '(inferred from current PHP version)';
                break;
        }
        $unsupported_php_extensions = array_diff(array_keys($codebase->config->php_extensions_not_supported), $codebase->config->php_extensions_supported_by_psalm_callmaps);
        $message = "Target PHP version: " . $codebase->get_major_analysis_php_version() . "." . $codebase->get_minor_analysis_php_version() . " " . $source;
        $enabled_extensions_names = array_keys(array_filter($codebase->config->php_extensions));
        if (count($enabled_extensions_names) > 0) {
            $message .= ' Enabled extensions: ' . implode(', ', $enabled_extensions_names);
        }
        if (count($unsupported_php_extensions) > 0) {
            $message .= ' (unsupported extensions: ' . implode(', ', $unsupported_php_extensions) . ')';
        }
        return $message . ('.' . PHP_EOL . PHP_EOL);
    }
    public function check(string $base_dir, bool $is_diff = false): void
    {
        if (!$base_dir) {
            throw new InvalidArgumentException('Cannot work with empty base_dir');
        }
        $diff_files = null;
        $deleted_files = null;
        $this->full_run = true;
        $reference_cache = $this->file_reference_provider->load_reference_cache(true);
        $this->codebase->diff_methods = $is_diff;
        if ($is_diff && $reference_cache && $this->project_cache_provider && $this->project_cache_provider->can_diff_files()) {
            $deleted_files = $this->file_reference_provider->get_deleted_referenced_files();
            $diff_files = [...$deleted_files, ...$this->get_diff_files()];
        }
        $this->progress->write($this->generate_php_version_message());
        $this->progress->start_scanning_files();
        $diff_no_files = false;
        if ($diff_files === null || $deleted_files === null || count($diff_files) > 200) {
            $this->config->visit_preloaded_stub_files($this->codebase, $this->progress);
            $this->visit_autoload_files();
            $this->codebase->scanner->add_files_to_shallow_scan($this->extra_files);
            $this->codebase->scanner->add_files_to_deep_scan($this->project_files);
            $this->codebase->analyzer->add_files_to_analyze($this->project_files);
            $this->config->initialize_plugins($this);
            $this->codebase->scan_files($this->scan_threads);
            $this->codebase->infer_types_from_usage = true;
        } else {
            $this->codebase->diff_run = true;
            $this->progress->debug(count($diff_files) . ' changed files: ' . PHP_EOL);
            $this->progress->debug('    ' . implode(PHP_EOL . "    ", $diff_files) . PHP_EOL);
            $this->codebase->analyzer->add_files_to_show_results($this->project_files);
            if ($diff_files) {
                $file_list = $this->get_referenced_files_from_diff($diff_files);
                // strip out deleted files
                $file_list = array_diff($file_list, $deleted_files);
                if ($file_list) {
                    $this->config->visit_preloaded_stub_files($this->codebase, $this->progress);
                    $this->visit_autoload_files();
                    $this->check_diff_files_with_config($this->config, $file_list);
                    $this->config->initialize_plugins($this);
                    $this->codebase->scan_files($this->scan_threads);
                } else {
                    $diff_no_files = true;
                }
            } else {
                $diff_no_files = true;
            }
        }
        if (!$diff_no_files) {
            $this->config->visit_stub_files($this->codebase, $this->progress);
            $event = new After_Codebase_Populated_Event($this->codebase);
            $this->config->event_dispatcher->dispatch_after_codebase_populated($event);
        }
        $this->progress->start_analyzing_files();
        $this->codebase->analyzer->analyze_files($this, $this->threads, $this->codebase->alter_code, true);
    }
    public function consolidate_analyzed_data(): void
    {
        $this->codebase->classlikes->consolidate_analyzed_data($this->codebase->methods, $this->progress, (bool) $this->codebase->find_unused_code);
    }
    public function track_tainted_inputs(): void
    {
        $this->codebase->taint_flow_graph = new Taint_Flow_Graph();
    }
    public function track_unused_suppressions(): void
    {
        $this->codebase->track_unused_suppressions = true;
    }
    public function interpret_refactors(): void
    {
        if (!$this->codebase->alter_code) {
            throw new UnexpectedValueException('Should not be checking references');
        }
        // interpret wildcards
        foreach ($this->to_refactor as $source => $destination) {
            if (($source_pos = strpos($source, '*')) && ($destination_pos = strpos($destination, '*')) && $source_pos === strlen($source) - 1 && $destination_pos === strlen($destination) - 1) {
                foreach ($this->codebase->classlike_storage_provider->get_all() as $class_storage) {
                    if (str_starts_with($source, substr($class_storage->name, 0, $source_pos))) {
                        $this->to_refactor[$class_storage->name] = substr($destination, 0, -1) . substr($class_storage->name, $source_pos);
                    }
                }
                unset($this->to_refactor[$source]);
            }
        }
        foreach ($this->to_refactor as $source => $destination) {
            $source_parts = explode('::', $source);
            $destination_parts = explode('::', $destination);
            if (!$this->codebase->classlikes->has_fully_qualified_class_name($source_parts[0])) {
                throw new Refactor_Exception('Source class ' . $source_parts[0] . ' doesn’t exist');
            }
            if (count($source_parts) === 1 && count($destination_parts) === 1) {
                if ($this->codebase->classlikes->has_fully_qualified_class_name($destination_parts[0])) {
                    throw new Refactor_Exception('Destination class ' . $destination_parts[0] . ' already exists');
                }
                $source_class_storage = $this->codebase->classlike_storage_provider->get($source_parts[0]);
                $destination_parts = explode('\\', $destination, -1);
                $destination_ns = implode('\\', $destination_parts);
                $this->codebase->classes_to_move[strtolower($source)] = $destination;
                $destination_class_storage = $this->codebase->classlike_storage_provider->create($destination);
                $destination_class_storage->name = $destination;
                if ($source_class_storage->aliases) {
                    $destination_class_storage->aliases = clone $source_class_storage->aliases;
                    $destination_class_storage->aliases->namespace = $destination_ns;
                }
                $destination_class_storage->location = $source_class_storage->location;
                $destination_class_storage->stmt_location = $source_class_storage->stmt_location;
                $destination_class_storage->populated = true;
                $this->codebase->class_transforms[strtolower($source)] = $destination;
                continue;
            }
            $source_method_id = new Method_Identifier($source_parts[0], strtolower($source_parts[1]));
            if ($this->codebase->methods->method_exists($source_method_id)) {
                if ($this->codebase->methods->method_exists(new Method_Identifier($destination_parts[0], strtolower($destination_parts[1])))) {
                    throw new Refactor_Exception('Destination method ' . $destination . ' already exists');
                }
                if (!$this->codebase->classlikes->class_exists($destination_parts[0])) {
                    throw new Refactor_Exception('Destination class ' . $destination_parts[0] . ' doesn’t exist');
                }
                $source_lc = strtolower($source);
                if (strtolower($source_parts[0]) !== strtolower($destination_parts[0])) {
                    $source_method_storage = $this->codebase->methods->get_storage($source_method_id);
                    $destination_class_storage = $this->codebase->classlike_storage_provider->get($destination_parts[0]);
                    if (!$source_method_storage->is_static && !isset($destination_class_storage->parent_classes[strtolower($source_method_id->fq_class_name)])) {
                        throw new Refactor_Exception('Cannot move non-static method ' . $source . ' into unrelated class ' . $destination_parts[0]);
                    }
                    $this->codebase->methods_to_move[$source_lc] = $destination;
                } else {
                    $this->codebase->methods_to_rename[$source_lc] = $destination_parts[1];
                }
                $this->codebase->call_transforms[$source_lc . '\((.*\))'] = $destination . '($1)';
                continue;
            }
            if ($source_parts[1][0] === '$') {
                if ($destination_parts[1][0] !== '$') {
                    throw new Refactor_Exception('Destination property must be of the form Foo::$bar');
                }
                if (!$this->codebase->properties->property_exists($source, true)) {
                    throw new Refactor_Exception('Property ' . $source . ' does not exist');
                }
                if ($this->codebase->properties->property_exists($destination, true)) {
                    throw new Refactor_Exception('Destination property ' . $destination . ' already exists');
                }
                if (!$this->codebase->classlikes->class_exists($destination_parts[0])) {
                    throw new Refactor_Exception('Destination class ' . $destination_parts[0] . ' doesn’t exist');
                }
                $source_id = strtolower($source_parts[0]) . '::' . $source_parts[1];
                if (strtolower($source_parts[0]) !== strtolower($destination_parts[0])) {
                    $source_storage = $this->codebase->properties->get_storage($source);
                    if (!$source_storage->is_static) {
                        throw new Refactor_Exception('Cannot move non-static property ' . $source);
                    }
                    $this->codebase->properties_to_move[$source_id] = $destination;
                } else {
                    $this->codebase->properties_to_rename[$source_id] = substr($destination_parts[1], 1);
                }
                $this->codebase->property_transforms[$source_id] = $destination;
                continue;
            }
            $source_class_constants = $this->codebase->classlikes->get_constants_for_class($source_parts[0], ReflectionProperty::IS_PRIVATE);
            if (isset($source_class_constants[$source_parts[1]])) {
                if (!$this->codebase->classlikes->has_fully_qualified_class_name($destination_parts[0])) {
                    throw new Refactor_Exception('Destination class ' . $destination_parts[0] . ' doesn’t exist');
                }
                $destination_class_constants = $this->codebase->classlikes->get_constants_for_class($destination_parts[0], ReflectionProperty::IS_PRIVATE);
                if (isset($destination_class_constants[$destination_parts[1]])) {
                    throw new Refactor_Exception('Destination constant ' . $destination . ' already exists');
                }
                $source_id = strtolower($source_parts[0]) . '::' . $source_parts[1];
                if (strtolower($source_parts[0]) !== strtolower($destination_parts[0])) {
                    $this->codebase->class_constants_to_move[$source_id] = $destination;
                } else {
                    $this->codebase->class_constants_to_rename[$source_id] = $destination_parts[1];
                }
                $this->codebase->class_constant_transforms[$source_id] = $destination;
                continue;
            }
            throw new Refactor_Exception('Psalm cannot locate ' . $source);
        }
    }
    public function prepare_migration(): void
    {
        if (!$this->codebase->alter_code) {
            throw new UnexpectedValueException('Should not be checking references');
        }
        $this->codebase->classlikes->move_methods($this->codebase->methods, $this->progress);
        $this->codebase->classlikes->move_properties($this->codebase->properties, $this->progress);
        $this->codebase->classlikes->move_class_constants($this->progress);
    }
    public function migrate_code(): void
    {
        if (!$this->codebase->alter_code) {
            throw new UnexpectedValueException('Should not be checking references');
        }
        $migration_manipulations = File_Manipulation_Buffer::get_migration_manipulations($this->codebase->file_provider);
        foreach ($migration_manipulations as $file_path => $file_manipulations) {
            usort($file_manipulations, static function (File_Manipulation $a, File_Manipulation $b): int {
                if ($a->start === $b->start) {
                    if ($b->end === $a->end) {
                        return $b->insertion_text > $a->insertion_text ? 1 : -1;
                    }
                    return $b->end > $a->end ? 1 : -1;
                }
                return $b->start > $a->start ? 1 : -1;
            });
            $existing_contents = $this->codebase->file_provider->get_contents($file_path);
            foreach ($file_manipulations as $manipulation) {
                $existing_contents = $manipulation->transform($existing_contents);
            }
            $this->codebase->file_provider->set_contents($file_path, $existing_contents);
        }
        foreach ($this->codebase->classes_to_move as $source => $destination) {
            $source_class_storage = $this->codebase->classlike_storage_provider->get($source);
            if (!$source_class_storage->location) {
                continue;
            }
            $potential_file_path = $this->config->get_potential_composer_file_path_for_class_like($destination);
            if ($potential_file_path && !file_exists($potential_file_path)) {
                $containing_dir = dirname($potential_file_path);
                if (!file_exists($containing_dir)) {
                    mkdir($containing_dir, 0777, true);
                }
                rename($source_class_storage->location->file_path, $potential_file_path);
            }
        }
    }
    public function find_references_to(string $symbol): void
    {
        if (!$this->stdout_report_options) {
            throw new UnexpectedValueException('Not expecting to emit output');
        }
        $locations = $this->codebase->find_references_to_symbol($symbol);
        foreach ($locations as $location) {
            $snippet = $location->get_snippet();
            $snippet_bounds = $location->get_snippet_bounds();
            $selection_bounds = $location->get_selection_bounds();
            $selection_start = $selection_bounds[0] - $snippet_bounds[0];
            $selection_length = $selection_bounds[1] - $selection_bounds[0];
            echo $location->file_name . ':' . $location->get_line_number() . PHP_EOL . ($this->stdout_report_options->use_color ? substr($snippet, 0, $selection_start) . "\x1b[97;42m" . substr($snippet, $selection_start, $selection_length) . "\x1b[0m" . substr($snippet, $selection_length + $selection_start) : $snippet) . PHP_EOL . PHP_EOL;
        }
    }
    public function check_dir(string $dir_name): void
    {
        $this->file_reference_provider->load_reference_cache();
        $this->config->visit_preloaded_stub_files($this->codebase, $this->progress);
        $this->check_dir_with_config($dir_name, $this->config, true);
        $this->progress->write($this->generate_php_version_message());
        $this->progress->start_scanning_files();
        $this->config->initialize_plugins($this);
        $this->codebase->scan_files($this->scan_threads);
        $this->config->visit_stub_files($this->codebase, $this->progress);
        $this->progress->start_analyzing_files();
        $this->codebase->analyzer->analyze_files($this, $this->threads, $this->codebase->alter_code, $this->codebase->find_unused_code === 'always');
    }
    private function check_dir_with_config(string $dir_name, Config $config, bool $allow_non_project_files = false): void
    {
        $file_extensions = $config->get_file_extensions();
        $filter = $allow_non_project_files ? null : $this->config->is_in_project_dirs(...);
        $file_paths = $this->file_provider->get_files_in_dir($dir_name, $file_extensions, $filter);
        $files_to_scan = [];
        foreach ($file_paths as $file_path) {
            $files_to_scan[$file_path] = $file_path;
        }
        $this->codebase->add_files_to_analyze($files_to_scan);
    }
    /**
     * @return list<string>
     */
    private function get_diff_files(): array
    {
        if (!$this->parser_cache_provider || !$this->project_cache_provider) {
            throw new UnexpectedValueException('Parser cache provider cannot be null here');
        }
        $diff_files = [];
        foreach ($this->project_files as $file_path) {
            $hash = $this->parser_cache_provider->get_hash($file_path);
            if ($hash !== null && $hash !== $this->file_provider->get_contents($file_path)) {
                $diff_files[] = $file_path;
            }
        }
        return $diff_files;
    }
    /**
     * @param  array<string>    $file_list
     */
    private function check_diff_files_with_config(Config $config, array $file_list = []): void
    {
        $files_to_scan = [];
        foreach ($file_list as $file_path) {
            if (!$this->file_provider->file_exists($file_path)) {
                continue;
            }
            if (!$config->is_in_project_dirs($file_path)) {
                $this->progress->debug('skipping ' . $file_path . PHP_EOL);
                continue;
            }
            $files_to_scan[$file_path] = $file_path;
        }
        $this->codebase->add_files_to_analyze($files_to_scan);
    }
    public function check_file(string $file_path): void
    {
        $this->progress->debug('Checking ' . $file_path . PHP_EOL);
        $this->config->visit_preloaded_stub_files($this->codebase, $this->progress);
        $this->config->hide_external_errors = $this->config->is_in_project_dirs($file_path);
        $this->codebase->add_files_to_analyze([$file_path => $file_path]);
        $this->file_reference_provider->load_reference_cache();
        $this->progress->write($this->generate_php_version_message());
        $this->progress->start_scanning_files();
        $this->config->initialize_plugins($this);
        $this->codebase->scan_files($this->scan_threads);
        $this->config->visit_stub_files($this->codebase, $this->progress);
        $this->progress->start_analyzing_files();
        $this->codebase->analyzer->analyze_files($this, $this->threads, $this->codebase->alter_code, $this->codebase->find_unused_code === 'always');
    }
    /**
     * @param string[] $paths_to_check
     */
    public function check_paths(array $paths_to_check): void
    {
        $this->progress->write($this->generate_php_version_message());
        $this->progress->start_scanning_files();
        $this->config->visit_preloaded_stub_files($this->codebase, $this->progress);
        $this->config->initialize_plugins($this);
        $this->visit_autoload_files();
        $this->codebase->scanner->add_files_to_shallow_scan($this->extra_files);
        foreach ($paths_to_check as $path) {
            $this->progress->debug('Checking ' . $path . PHP_EOL);
            if (is_dir($path)) {
                $this->check_dir_with_config($path, $this->config, true);
            } elseif (is_file($path)) {
                $this->check_paths_files[] = $path;
                $this->codebase->add_files_to_analyze([$path => $path]);
                $this->config->hide_external_errors = $this->config->is_in_project_dirs($path);
            }
        }
        $this->file_reference_provider->load_reference_cache();
        $this->codebase->scan_files($this->scan_threads);
        $this->config->visit_stub_files($this->codebase, $this->progress);
        $event = new After_Codebase_Populated_Event($this->codebase);
        $this->config->event_dispatcher->dispatch_after_codebase_populated($event);
        $this->progress->start_analyzing_files();
        $this->codebase->analyzer->analyze_files($this, $this->threads, $this->codebase->alter_code, $this->codebase->find_unused_code === 'always');
        if ($this->stdout_report_options && in_array($this->stdout_report_options->format, [Report::TYPE_CONSOLE, Report::TYPE_PHP_STORM]) && $this->codebase->collect_references) {
            fwrite(STDERR, PHP_EOL . 'To whom it may concern: Psalm cannot detect unused classes, methods and properties' . PHP_EOL . 'when analyzing individual files and folders. Run on the full project to enable' . PHP_EOL . 'complete unused code detection.' . PHP_EOL);
        }
    }
    public function finish(float $start_time, string $psalm_version): void
    {
        $this->codebase->file_reference_provider->remove_deleted_files_from_references();
        if ($this->project_cache_provider) {
            $this->project_cache_provider->process_successful_run($start_time, $psalm_version);
        }
    }
    public function get_config(): Config
    {
        return $this->config;
    }
    /**
     * @param  array<string>  $diff_files
     * @return array<string, string>
     */
    public function get_referenced_files_from_diff(array $diff_files, bool $include_referencing_files = true): array
    {
        $all_inherited_files_to_check = $diff_files;
        while ($diff_files) {
            $diff_file = array_shift($diff_files);
            $dependent_files = $this->file_reference_provider->get_files_inheriting_from_file($diff_file);
            $new_dependent_files = array_diff($dependent_files, $all_inherited_files_to_check);
            $all_inherited_files_to_check = array_merge($all_inherited_files_to_check, $new_dependent_files);
            $diff_files = array_merge($diff_files, $new_dependent_files);
        }
        $all_files_to_check = $all_inherited_files_to_check;
        if ($include_referencing_files) {
            foreach ($all_inherited_files_to_check as $file_name) {
                $dependent_files = $this->file_reference_provider->get_files_referencing_file($file_name);
                $all_files_to_check = array_merge($dependent_files, $all_files_to_check);
            }
        }
        return array_combine($all_files_to_check, $all_files_to_check);
    }
    public function file_exists(string $file_path): bool
    {
        return $this->file_provider->file_exists($file_path);
    }
    public function is_directory(string $file_path): bool
    {
        return $this->file_provider->is_directory($file_path);
    }
    public function alter_code_after_completion(bool $dry_run = false, bool $safe_types = false): void
    {
        $this->codebase->alter_code = true;
        $this->codebase->infer_types_from_usage = true;
        $this->show_issues = false;
        $this->dry_run = $dry_run;
        $this->only_replace_php_types_with_non_docblock_types = $safe_types;
    }
    /**
     * @param array<string, string> $to_refactor
     */
    public function refactor_code_after_completion(array $to_refactor): void
    {
        $this->to_refactor = $to_refactor;
        $this->codebase->alter_code = true;
        $this->show_issues = false;
    }
    /**
     * @param 'cli'|'config'|'composer'|'tests' $source
     */
    public function set_php_version(string $version, string $source): void
    {
        if (!preg_match('/' . self::PHP_VERSION_REGEX . '/', $version)) {
            throw new UnexpectedValueException('Expecting a version number in the format x.y or x.y.z');
        }
        if (!preg_match('/' . self::PHP_SUPPORTED_VERSIONS_REGEX . '/', $version)) {
            throw new UnexpectedValueException('Psalm supports PHP version ">=5.4". The specified version ' . $version . " is either not supported or doesn't exist.");
        }
        [$php_major_version, $php_minor_version] = explode('.', $version);
        $php_major_version = (int) $php_major_version;
        $php_minor_version = (int) $php_minor_version;
        $analysis_php_version_id = $php_major_version * 10000 + $php_minor_version * 100;
        if ($this->codebase->analysis_php_version_id !== $analysis_php_version_id) {
            // reset parser when php version changes
            Statements_Provider::clear_parser();
        }
        $this->codebase->analysis_php_version_id = $analysis_php_version_id;
        $this->codebase->php_version_source = $source;
    }
    /**
     * @param array<string, bool> $issues
     * @throws UnsupportedIssueToFixException
     */
    public function set_issues_to_fix(array $issues): void
    {
        $supported_issues_to_fix = static::get_supported_issues_to_fix();
        $supported_issues_to_fix[] = 'MissingImmutableAnnotation';
        $supported_issues_to_fix[] = 'MissingPureAnnotation';
        $supported_issues_to_fix[] = 'MissingThrowsDocblock';
        $unsupported_issues = array_diff(array_keys($issues), $supported_issues_to_fix);
        if (!empty($unsupported_issues)) {
            throw new Unsupported_Issue_To_Fix_Exception('Psalm doesn\'t know how to fix issue(s): ' . implode(', ', $unsupported_issues) . PHP_EOL . 'Supported issues to fix are: ' . implode(',', $supported_issues_to_fix));
        }
        $this->issues_to_fix = $issues;
    }
    public function set_all_issues_to_fix(): void
    {
        $keyed_issues = array_fill_keys(static::get_supported_issues_to_fix(), true);
        $this->set_issues_to_fix($keyed_issues);
    }
    /**
     * @return array<string, bool>
     */
    public function get_issues_to_fix(): array
    {
        return $this->issues_to_fix;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    public function get_file_analyzer_for_class_like(string $fq_class_name): File_Analyzer
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $file_path = $this->codebase->scanner->get_class_like_file_path($fq_class_name_lc);
        return new File_Analyzer($this, $file_path, $this->config->shorten_file_name($file_path));
    }
    public function get_method_mutations(Method_Identifier $original_method_id, Context $this_context, string $root_file_path, string $root_file_name): void
    {
        $fq_class_name = $original_method_id->fq_class_name;
        $appearing_method_id = $this->codebase->methods->get_appearing_method_id($original_method_id);
        if (!$appearing_method_id) {
            // this can happen for some abstract classes implementing (but not fully) interfaces
            return;
        }
        $appearing_fq_class_name = $appearing_method_id->fq_class_name;
        $appearing_class_storage = $this->classlike_storage_provider->get($appearing_fq_class_name);
        if (!$appearing_class_storage->user_defined) {
            return;
        }
        $file_analyzer = $this->get_file_analyzer_for_class_like($fq_class_name);
        $file_analyzer->set_root_file_path($root_file_path, $root_file_name);
        if ($appearing_fq_class_name !== $fq_class_name) {
            $file_analyzer = $this->get_file_analyzer_for_class_like($appearing_fq_class_name);
        }
        $stmts = $this->codebase->get_statements_for_file($file_analyzer->get_file_path());
        $file_analyzer->populate_checkers($stmts);
        if (!$this_context->self) {
            $this_context->self = $fq_class_name;
            $this_context->vars_in_scope['$this'] = Type::parse_string($fq_class_name);
        }
        $file_analyzer->get_method_mutations($appearing_method_id, $this_context, true);
        $file_analyzer->class_analyzers_to_analyze = [];
        $file_analyzer->interface_analyzers_to_analyze = [];
        $file_analyzer->clear_source_before_destruction();
    }
    public function get_function_like_analyzer(Method_Identifier $method_id, string $file_path): ?Function_Like_Analyzer
    {
        $file_analyzer = new File_Analyzer($this, $file_path, $this->config->shorten_file_name($file_path));
        $stmts = $this->codebase->get_statements_for_file($file_analyzer->get_file_path());
        $file_analyzer->populate_checkers($stmts);
        $function_analyzer = $file_analyzer->get_function_like_analyzer($method_id);
        $file_analyzer->class_analyzers_to_analyze = [];
        $file_analyzer->interface_analyzers_to_analyze = [];
        return $function_analyzer;
    }
    /**
     * @return array<int, string>
     * @psalm-pure
     */
    public static function get_supported_issues_to_fix(): array
    {
        return array_map(
            /** @param class-string $issue_class */
            static function (string $issue_class): string {
                $parts = explode('\\', $issue_class);
                return end($parts);
            },
            self::SUPPORTED_ISSUES_TO_FIX
        );
    }
}