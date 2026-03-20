<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Error_Handler;
use Psalm\Internal\Fork\Init_Scanner_Task;
use Psalm\Internal\Fork\Pool;
use Psalm\Internal\Fork\Scanner_Task;
use Psalm\Internal\Fork\Shutdown_Scanner_Task;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Issue_Buffer;
use Psalm\Progress\Progress;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Function_Storage;
use Psalm\Type;
use Psalm\Type\Union;
use ReflectionClass;
use Throwable;
use UnexpectedValueException;
use function Amp\Future\await;
use function array_filter;
use function array_merge;
use function array_pop;
use function ceil;
use function count;
use function error_reporting;
use function explode;
use function file_exists;
use function min;
use function realpath;
use function str_ends_with;
use function strtolower;
use function substr;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;
/**
 * @psalm-type  ThreadData = array{
 *     array<string, string>,
 *     array<string, string>,
 *     array<string, string>,
 *     array<string, bool>,
 *     array<string, bool>,
 *     array<string, string>,
 *     array<string, bool>,
 *     array<string, bool>,
 *     array<string, bool>
 * }
 *
 * @psalm-type  PoolData = array{
 *     classlikes_data:array{
 *         array<lowercase-string, bool>,
 *         array<lowercase-string, bool>,
 *         array<lowercase-string, bool>,
 *         array<string, bool>,
 *         array<lowercase-string, bool>,
 *         array<string, bool>,
 *         array<lowercase-string, bool>,
 *         array<string, bool>,
 *         array<string, bool>
 *     },
 *     scanner_data: ThreadData,
 *     issues:array<string, list<IssueData>>,
 *     changed_members:array<string, array<string, bool>>,
 *     unchanged_signature_members:array<string, array<string, bool>>,
 *     diff_map:array<string, array<int, array{int, int, int, int}>>,
 *     deletion_ranges:array<string, array<int, array{int, int}>>,
 *     errors:array<string, bool>,
 *     classlike_storage:array<string, ClassLikeStorage>,
 *     file_storage:array<lowercase-string, FileStorage>,
 *     taint_data: ?TaintFlowGraph,
 *     global_constants: array<string, Union>,
 *     global_functions: array<lowercase-string, FunctionStorage>
 * }
 */
/**
 * @internal
 *
 * Contains methods that aid in the scanning of Psalm's codebase
 */
final class Scanner
{
    /**
     * @var array<string, string>
     */
    private array $classlike_files = [];
    /**
     * @var array<string, bool>
     */
    private array $deep_scanned_classlike_files = [];
    /**
     * @var array<string, string>
     */
    private array $files_to_scan = [];
    /**
     * @var array<string, string>
     */
    private array $classes_to_scan = [];
    /**
     * @var array<string, bool>
     */
    private array $classes_to_deep_scan = [];
    /**
     * @var array<string, string>
     */
    private array $files_to_deep_scan = [];
    /**
     * @var array<string, bool>
     */
    private array $scanned_files = [];
    /**
     * @var array<string, bool>
     */
    private array $store_scan_failure = [];
    /**
     * @var array<string, bool>
     */
    private array $reflected_classlikes_lc = [];
    private bool $is_forked = false;
    public function __construct(private readonly Codebase $codebase, private readonly Config $config, private readonly File_Storage_Provider $file_storage_provider, private readonly File_Provider $file_provider, private readonly Reflection $reflection, private readonly File_Reference_Provider $file_reference_provider, private readonly Progress $progress)
    {
    }
    /**
     * @param array<string, string> $files_to_scan
     */
    public function add_files_to_shallow_scan(array $files_to_scan): void
    {
        $this->files_to_scan += $files_to_scan;
    }
    /**
     * @param array<string, string> $files_to_scan
     */
    public function add_files_to_deep_scan(array $files_to_scan): void
    {
        $this->files_to_scan += $files_to_scan;
        $this->files_to_deep_scan += $files_to_scan;
    }
    public function add_file_to_shallow_scan(string $file_path): void
    {
        $this->files_to_scan[$file_path] = $file_path;
    }
    public function add_file_to_deep_scan(string $file_path): void
    {
        $this->files_to_scan[$file_path] = $file_path;
        $this->files_to_deep_scan[$file_path] = $file_path;
    }
    public function remove_file(string $file_path): void
    {
        unset($this->scanned_files[$file_path]);
    }
    public function remove_class_like(string $fq_classlike_name_lc): void
    {
        unset($this->classlike_files[$fq_classlike_name_lc], $this->deep_scanned_classlike_files[$fq_classlike_name_lc]);
    }
    public function set_class_like_file_path(string $fq_classlike_name_lc, string $file_path): void
    {
        $this->classlike_files[$fq_classlike_name_lc] = $file_path;
    }
    public function get_class_like_file_path(string $fq_classlike_name_lc): string
    {
        if (!isset($this->classlike_files[$fq_classlike_name_lc])) {
            throw new UnexpectedValueException('Could not find file for ' . $fq_classlike_name_lc);
        }
        return $this->classlike_files[$fq_classlike_name_lc];
    }
    /**
     * @param  array<string, mixed> $phantom_classes
     */
    public function queue_class_like_for_scanning(string $fq_classlike_name, bool $analyze_too = false, bool $store_failure = true, array $phantom_classes = []): void
    {
        if ($fq_classlike_name[0] === '\\') {
            $fq_classlike_name = substr($fq_classlike_name, 1);
        }
        $fq_classlike_name_lc = strtolower($fq_classlike_name);
        if ($fq_classlike_name_lc === 'static') {
            return;
        }
        // avoid checking classes that we know will just end in failure
        if ($fq_classlike_name_lc === 'null' || str_ends_with($fq_classlike_name_lc, '\null')) {
            return;
        }
        if (!isset($this->classlike_files[$fq_classlike_name_lc]) || $analyze_too && !isset($this->deep_scanned_classlike_files[$fq_classlike_name_lc])) {
            if (!isset($this->classes_to_scan[$fq_classlike_name_lc]) || $store_failure) {
                $this->classes_to_scan[$fq_classlike_name_lc] = $fq_classlike_name;
            }
            if ($analyze_too) {
                $this->classes_to_deep_scan[$fq_classlike_name_lc] = true;
            }
            $this->store_scan_failure[$fq_classlike_name] = $store_failure;
            if (Property_Map::in_property_map($fq_classlike_name_lc)) {
                $public_mapped_properties = Property_Map::get_property_map()[$fq_classlike_name_lc];
                foreach ($public_mapped_properties as $public_mapped_property) {
                    $property_type = Type::parse_string($public_mapped_property);
                    /** @psalm-suppress UnusedMethodCall */
                    $property_type->queue_class_likes_for_scanning($this->codebase, null, $phantom_classes + [$fq_classlike_name_lc => true]);
                }
            }
        }
    }
    public function scan_files(Class_Likes $classlikes, int $pool_size = 1): bool
    {
        $has_changes = false;
        while ($this->files_to_scan || $this->classes_to_scan) {
            if ($this->files_to_scan) {
                if ($this->scan_file_paths($pool_size)) {
                    $has_changes = true;
                }
            } else {
                $this->convert_classes_to_file_paths($classlikes);
            }
        }
        return $has_changes;
    }
    private function should_scan(string $file_path): bool
    {
        return $this->file_provider->file_exists($file_path) && !$this->file_provider->is_directory($file_path) && (!isset($this->scanned_files[$file_path]) || isset($this->files_to_deep_scan[$file_path]) && !$this->scanned_files[$file_path]);
    }
    private function scan_file_paths(int $pool_size): bool
    {
        $files_to_scan = array_filter($this->files_to_scan, $this->should_scan(...));
        $this->files_to_scan = [];
        if (!$files_to_scan) {
            return false;
        }
        if (!$this->is_forked && $pool_size > 1 && count($files_to_scan) > 512) {
            $pool_size = (int) ceil(min($pool_size, count($files_to_scan) / 256));
        } else {
            $pool_size = 1;
        }
        $this->progress->expand(count($files_to_scan));
        if ($pool_size > 1) {
            $this->progress->debug('Forking process for scanning' . PHP_EOL);
            // Run scanning one file at a time, splitting the set of
            // files up among a given number of child processes.
            $pool = new Pool($pool_size, $this->config->long_scan_warning, $this->progress);
            await($pool->run_all(new Init_Scanner_Task()));
            $pool->run($files_to_scan, Scanner_Task::class, function (): void {
                $this->progress->task_done(0);
            });
            // Wait for all tasks to complete and collect the results.
            $forked_pool_data = $pool->run_all(new Shutdown_Scanner_Task());
            foreach ($forked_pool_data as $pool_data) {
                $pool_data = $pool_data->await();
                Issue_Buffer::add_issues($pool_data['issues']);
                $this->codebase->statements_provider->add_changed_members($pool_data['changed_members']);
                $this->codebase->statements_provider->add_unchanged_signature_members($pool_data['unchanged_signature_members']);
                $this->codebase->statements_provider->add_diff_map($pool_data['diff_map']);
                $this->codebase->statements_provider->add_deletion_ranges($pool_data['deletion_ranges']);
                $this->codebase->statements_provider->add_errors($pool_data['errors']);
                if ($this->codebase->taint_flow_graph && $pool_data['taint_data']) {
                    $this->codebase->taint_flow_graph->add_graph($pool_data['taint_data']);
                }
                $this->codebase->file_storage_provider->add_more($pool_data['file_storage']);
                $this->codebase->classlike_storage_provider->add_more($pool_data['classlike_storage']);
                $this->codebase->classlikes->add_thread_data($pool_data['classlikes_data']);
                $this->add_thread_data($pool_data['scanner_data']);
                $this->codebase->add_global_constant_types($pool_data['global_constants']);
                $this->codebase->functions->add_global_functions($pool_data['global_functions']);
            }
        } else {
            foreach ($files_to_scan as $file_path => $_) {
                $this->scan_a_path($file_path);
                $this->progress->task_done(0);
            }
        }
        $this->file_reference_provider->add_class_like_files($this->classlike_files);
        return true;
    }
    private function convert_classes_to_file_paths(Class_Likes $classlikes): void
    {
        $classes_to_scan = $this->classes_to_scan;
        $this->classes_to_scan = [];
        foreach ($classes_to_scan as $fq_classlike_name) {
            $fq_classlike_name_lc = strtolower($fq_classlike_name);
            if (isset($this->reflected_classlikes_lc[$fq_classlike_name_lc])) {
                continue;
            }
            if ($classlikes->is_missing_class_like($fq_classlike_name_lc)) {
                continue;
            }
            if (!isset($this->classlike_files[$fq_classlike_name_lc])) {
                if ($classlikes->does_class_like_exist($fq_classlike_name_lc)) {
                    if ($fq_classlike_name_lc === 'self') {
                        continue;
                    }
                    $this->progress->debug('Using reflection to get metadata for ' . $fq_classlike_name . "\n");
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $reflected_class = new ReflectionClass($fq_classlike_name);
                    $this->reflection->register_class($reflected_class);
                    $this->reflected_classlikes_lc[$fq_classlike_name_lc] = true;
                } elseif ($this->file_exists_for_class_like($classlikes, $fq_classlike_name)) {
                    $fq_classlike_name_lc = strtolower($classlikes->get_un_aliased_name($fq_classlike_name_lc));
                    // even though we've checked this above, calling the method invalidates it
                    if (isset($this->classlike_files[$fq_classlike_name_lc])) {
                        $file_path = $this->classlike_files[$fq_classlike_name_lc];
                        $this->files_to_scan[$file_path] = $file_path;
                        if (isset($this->classes_to_deep_scan[$fq_classlike_name_lc])) {
                            unset($this->classes_to_deep_scan[$fq_classlike_name_lc]);
                            $this->files_to_deep_scan[$file_path] = $file_path;
                        }
                    }
                } elseif ($this->store_scan_failure[$fq_classlike_name]) {
                    $classlikes->register_missing_class_like($fq_classlike_name_lc);
                }
            } elseif (isset($this->classes_to_deep_scan[$fq_classlike_name_lc]) && !isset($this->deep_scanned_classlike_files[$fq_classlike_name_lc])) {
                $file_path = $this->classlike_files[$fq_classlike_name_lc];
                $this->files_to_scan[$file_path] = $file_path;
                unset($this->classes_to_deep_scan[$fq_classlike_name_lc]);
                $this->files_to_deep_scan[$file_path] = $file_path;
                $this->deep_scanned_classlike_files[$fq_classlike_name_lc] = true;
            }
        }
    }
    /**
     * @param  array<string, class-string<FileScanner>>  $filetype_scanners
     */
    private function scan_file(string $file_path, array $filetype_scanners, bool $will_analyze = false): void
    {
        $file_scanner = $this->get_scanner_for_path($file_path, $filetype_scanners, $will_analyze);
        if (isset($this->scanned_files[$file_path]) && (!$will_analyze || $this->scanned_files[$file_path])) {
            throw new UnexpectedValueException('Should not be rescanning ' . $file_path);
        }
        if (!$this->file_provider->file_exists($file_path) && $this->config->must_be_ignored($file_path)) {
            // this should not happen, but might if the file was temporary
            return;
        }
        $file_contents = $this->file_provider->get_contents($file_path);
        $from_cache = $this->file_storage_provider->has($file_path, $file_contents);
        if (!$from_cache) {
            $this->file_storage_provider->create($file_path);
        }
        $this->scanned_files[$file_path] = $will_analyze;
        $file_storage = $this->file_storage_provider->get($file_path);
        $file_scanner->scan($this->codebase, $file_storage, $from_cache, $this->progress);
        if (!$from_cache) {
            if (!$file_storage->has_visitor_issues && $this->file_storage_provider->cache) {
                $this->file_storage_provider->cache->write_to_cache($file_storage, $file_contents);
            }
        } else {
            $this->codebase->statements_provider->set_unchanged_file($file_path);
            foreach ($file_storage->required_file_paths as $required_file_path) {
                if ($will_analyze) {
                    $this->add_file_to_deep_scan($required_file_path);
                } else {
                    $this->add_file_to_shallow_scan($required_file_path);
                }
            }
            foreach ($file_storage->classlikes_in_file as $fq_classlike_name) {
                $this->codebase->exhume_class_like_storage($fq_classlike_name, $file_path);
            }
            foreach ($file_storage->required_classes as $fq_classlike_name) {
                $this->queue_class_like_for_scanning($fq_classlike_name, $will_analyze, false);
            }
            foreach ($file_storage->required_interfaces as $fq_classlike_name) {
                $this->queue_class_like_for_scanning($fq_classlike_name, false, false);
            }
            foreach ($file_storage->referenced_classlikes as $fq_classlike_name) {
                $this->queue_class_like_for_scanning($fq_classlike_name, false, false);
            }
            if ($this->codebase->register_autoload_files || $this->codebase->all_functions_global) {
                foreach ($file_storage->functions as $function_storage) {
                    if ($function_storage->cased_name && !$this->codebase->functions->has_stubbed_function($function_storage->cased_name)) {
                        $this->codebase->functions->add_global_function($function_storage->cased_name, $function_storage);
                    }
                }
            }
            if ($this->codebase->register_autoload_files || $this->codebase->all_constants_global) {
                foreach ($file_storage->constants as $name => $type) {
                    $this->codebase->add_global_constant_type($name, $type);
                }
            }
            foreach ($file_storage->classlike_aliases as $aliased_name => $unaliased_name) {
                $this->codebase->classlikes->add_class_alias($unaliased_name, $aliased_name);
            }
        }
    }
    /**
     * @param  array<string, class-string<FileScanner>>  $filetype_scanners
     */
    private function get_scanner_for_path(string $file_path, array $filetype_scanners, bool $will_analyze = false): File_Scanner
    {
        $path_parts = explode(DIRECTORY_SEPARATOR, $file_path);
        $file_name_parts = explode('.', array_pop($path_parts));
        $extension = count($file_name_parts) > 1 ? array_pop($file_name_parts) : '';
        $file_name = $this->config->shorten_file_name($file_path);
        if (isset($filetype_scanners[$extension])) {
            return new $filetype_scanners[$extension]($file_path, $file_name, $will_analyze);
        }
        return new File_Scanner($file_path, $file_name, $will_analyze);
    }
    /**
     * @return array<string, bool>
     */
    public function get_scanned_files(): array
    {
        return $this->scanned_files;
    }
    /**
     * Checks whether a class exists, and if it does then records what file it's in
     * for later checking
     */
    private function file_exists_for_class_like(Class_Likes $classlikes, string $fq_class_name): bool
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        if (isset($this->classlike_files[$fq_class_name_lc])) {
            return true;
        }
        if ($fq_class_name === 'self') {
            return false;
        }
        $composer_file_path = $this->config->get_composer_file_path_for_class_like($fq_class_name);
        if ($composer_file_path && file_exists($composer_file_path)) {
            $this->progress->debug('Using composer to locate file for ' . $fq_class_name . "\n");
            $classlikes->add_fully_qualified_class_like_name($fq_class_name_lc, (string) realpath($composer_file_path));
            return true;
        }
        foreach ($this->config->event_dispatcher->file_path_provider_interface as $provider) {
            /** @psalm-suppress ArgumentTypeCoercion */
            $file_path = $provider::get_class_file_path($fq_class_name);
            if ($file_path !== null && file_exists($file_path)) {
                $this->progress->debug('Using custom file path provider to locate file for ' . $fq_class_name . "\n");
                $classlikes->add_fully_qualified_class_like_name($fq_class_name_lc, (string) realpath($file_path));
                return true;
            }
        }
        $reflected_class = Error_Handler::run_with_exceptions_suppressed(function () use ($fq_class_name): ?ReflectionClass {
            $old_level = error_reporting();
            $this->progress->set_error_reporting();
            try {
                $this->progress->debug('Using reflection to locate file for ' . $fq_class_name . "\n");
                /** @psalm-suppress ArgumentTypeCoercion */
                return new ReflectionClass($fq_class_name);
            } catch (Throwable) {
                // do not cache any results here (as case-sensitive filenames can screw things up)
                return null;
            } finally {
                error_reporting($old_level);
            }
        });
        if (null === $reflected_class) {
            return false;
        }
        $file_path = (string) $reflected_class->get_file_name();
        // if the file was autoloaded but exists in evaled code only, return false
        if (!file_exists($file_path)) {
            return false;
        }
        $new_fq_class_name = $reflected_class->get_name();
        $new_fq_class_name_lc = strtolower($new_fq_class_name);
        if ($new_fq_class_name_lc !== $fq_class_name_lc) {
            $classlikes->add_class_alias($new_fq_class_name, $fq_class_name);
            $fq_class_name_lc = $new_fq_class_name_lc;
        }
        $fq_class_name = $new_fq_class_name;
        $classlikes->add_fully_qualified_class_like_name($fq_class_name_lc);
        if ($reflected_class->is_interface()) {
            $classlikes->add_fully_qualified_interface_name($fq_class_name, $file_path);
        } elseif ($reflected_class->is_trait()) {
            $classlikes->add_fully_qualified_trait_name($fq_class_name, $file_path);
        } else {
            $classlikes->add_fully_qualified_class_name($fq_class_name, $file_path);
        }
        return true;
    }
    /**
     * @return ThreadData
     */
    public function get_thread_data(): array
    {
        return [$this->files_to_scan, $this->files_to_deep_scan, $this->classes_to_scan, $this->classes_to_deep_scan, $this->store_scan_failure, $this->classlike_files, $this->deep_scanned_classlike_files, $this->scanned_files, $this->reflected_classlikes_lc];
    }
    /**
     * @param ThreadData $thread_data
     */
    public function add_thread_data(array $thread_data): void
    {
        [$files_to_scan, $files_to_deep_scan, $classes_to_scan, $classes_to_deep_scan, $store_scan_failure, $classlike_files, $deep_scanned_classlike_files, $scanned_files, $reflected_classlikes_lc] = $thread_data;
        $this->files_to_scan = array_merge($files_to_scan, $this->files_to_scan);
        $this->files_to_deep_scan = array_merge($files_to_deep_scan, $this->files_to_deep_scan);
        $this->classes_to_scan = array_merge($classes_to_scan, $this->classes_to_scan);
        $this->classes_to_deep_scan = array_merge($classes_to_deep_scan, $this->classes_to_deep_scan);
        $this->store_scan_failure = array_merge($store_scan_failure, $this->store_scan_failure);
        $this->classlike_files = array_merge($classlike_files, $this->classlike_files);
        $this->deep_scanned_classlike_files = array_merge($deep_scanned_classlike_files, $this->deep_scanned_classlike_files);
        $this->scanned_files = array_merge($scanned_files, $this->scanned_files);
        $this->reflected_classlikes_lc = array_merge($reflected_classlikes_lc, $this->reflected_classlikes_lc);
    }
    public function is_forked(): void
    {
        $this->is_forked = true;
    }
    public function scan_a_path(string $file_path): void
    {
        $this->scan_file($file_path, $this->config->get_filetype_scanners(), isset($this->files_to_deep_scan[$file_path]));
    }
}