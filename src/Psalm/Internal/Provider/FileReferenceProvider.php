<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Codebase\Analyzer;
use UnexpectedValueException;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_unique;
use function explode;
/**
 * Used to determine which files reference other files, necessary for using the --diff
 * option from the command line.
 *
 * @psalm-import-type FileMapType from Analyzer
 * @internal
 */
final class File_Reference_Provider
{
    private bool $loaded_from_cache = false;
    /**
     * A lookup table used for getting all the references to a class not inside a method
     * indexed by file
     *
     * @var array<string, array<string,bool>>
     */
    private static array $nonmethod_references_to_classes = [];
    /**
     * A lookup table used for getting all the methods that reference a class
     *
     * @var array<string, array<string,bool>>
     */
    private static array $method_references_to_classes = [];
    /**
     * A lookup table used for getting all the files that reference a class member
     *
     * @var array<string, array<string,bool>>
     */
    private static array $file_references_to_class_members = [];
    /**
     * A lookup table used for getting all the files that reference a class property
     *
     * @var array<string, array<string,bool>>
     */
    private static array $file_references_to_class_properties = [];
    /**
     * A lookup table used for getting all the files that reference a method's return value
     *
     * @var array<string, array<string,bool>>
     */
    private static array $file_references_to_method_returns = [];
    /**
     * A lookup table used for getting all the files that reference a missing class member
     *
     * @var array<string, array<string,bool>>
     */
    private static array $file_references_to_missing_class_members = [];
    /**
     * @var array<string, array<string, true>>
     */
    private static array $files_inheriting_classes = [];
    /**
     * A list of all files deleted since the last successful run
     *
     * @var array<int, string>|null
     */
    private static ?array $deleted_files = null;
    /**
     * A lookup table used for getting all the files referenced by a file
     *
     * @var array<string, array{a:array<int, string>, i:array<int, string>}>
     */
    private static array $file_references = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $method_references_to_class_members = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $method_dependencies = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $method_references_to_class_properties = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $method_references_to_method_returns = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $method_references_to_missing_class_members = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private static array $references_to_mixed_member_names = [];
    /**
     * @var array<string, array<int, CodeLocation>>
     */
    private static array $class_method_locations = [];
    /**
     * @var array<string, array<int, CodeLocation>>
     */
    private static array $class_property_locations = [];
    /**
     * @var array<string, array<int, CodeLocation>>
     */
    private static array $class_locations = [];
    /**
     * @var array<string, string>
     */
    private static array $classlike_files = [];
    /**
     * @var array<string, array<string, int>>
     */
    private static array $analyzed_methods = [];
    /**
     * @var array<string, array<int, IssueData>>
     */
    private static array $issues = [];
    /**
     * @var array<string, FileMapType>
     */
    private static array $file_maps = [];
    /**
     * @var array<string, array{int, int}>
     */
    private static array $mixed_counts = [];
    /**
     * @var array<string, array<int, array<string, bool>>>
     */
    private static array $method_param_uses = [];
    public function __construct(private readonly File_Provider $file_provider, public ?File_Reference_Cache_Provider $cache = null)
    {
    }
    /**
     * @return array<int, string>
     */
    public function get_deleted_referenced_files(): array
    {
        if (self::$deleted_files === null) {
            self::$deleted_files = array_filter(array_keys(self::$file_references), fn(string $file_name): bool => !$this->file_provider->file_exists($file_name));
        }
        return self::$deleted_files;
    }
    /**
     * @param lowercase-string $fq_class_name_lc
     */
    public function add_non_method_reference_to_class(string $source_file, string $fq_class_name_lc): void
    {
        self::$nonmethod_references_to_classes[$fq_class_name_lc][$source_file] = true;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_non_method_references_to_classes(): array
    {
        return self::$nonmethod_references_to_classes;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_non_method_references_to_classes(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$nonmethod_references_to_classes[$key])) {
                self::$nonmethod_references_to_classes[$key] = array_merge($reference, self::$nonmethod_references_to_classes[$key]);
            } else {
                self::$nonmethod_references_to_classes[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, string> $map
     */
    public function add_class_like_files(array $map): void
    {
        self::$classlike_files += $map;
    }
    public function add_file_reference_to_class_member(string $source_file, string $referenced_member_id, bool $inside_return): void
    {
        self::$file_references_to_class_members[$referenced_member_id][$source_file] = true;
        if ($inside_return) {
            self::$file_references_to_method_returns[$referenced_member_id][$source_file] = true;
        }
    }
    public function add_file_reference_to_class_property(string $source_file, string $referenced_property_id): void
    {
        self::$file_references_to_class_properties[$referenced_property_id][$source_file] = true;
    }
    public function add_file_reference_to_missing_class_member(string $source_file, string $referenced_member_id): void
    {
        self::$file_references_to_missing_class_members[$referenced_member_id][$source_file] = true;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_file_references_to_class_members(): array
    {
        return self::$file_references_to_class_members;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_file_references_to_class_properties(): array
    {
        return self::$file_references_to_class_properties;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_file_references_to_method_returns(): array
    {
        return self::$file_references_to_method_returns;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_file_references_to_missing_class_members(): array
    {
        return self::$file_references_to_missing_class_members;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_file_references_to_class_members(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$file_references_to_class_members[$key])) {
                self::$file_references_to_class_members[$key] = array_merge($reference, self::$file_references_to_class_members[$key]);
            } else {
                self::$file_references_to_class_members[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_file_references_to_class_properties(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$file_references_to_class_properties[$key])) {
                self::$file_references_to_class_properties[$key] = array_merge($reference, self::$file_references_to_class_properties[$key]);
            } else {
                self::$file_references_to_class_properties[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_file_references_to_method_returns(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$file_references_to_method_returns[$key])) {
                self::$file_references_to_method_returns[$key] = array_merge($reference, self::$file_references_to_method_returns[$key]);
            } else {
                self::$file_references_to_method_returns[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_file_references_to_missing_class_members(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$file_references_to_missing_class_members[$key])) {
                self::$file_references_to_missing_class_members[$key] = array_merge($reference, self::$file_references_to_missing_class_members[$key]);
            } else {
                self::$file_references_to_missing_class_members[$key] = $reference;
            }
        }
    }
    public function add_file_inheritance_to_class(string $source_file, string $fq_class_name_lc): void
    {
        self::$files_inheriting_classes[$fq_class_name_lc][$source_file] = true;
    }
    public function add_method_param_use(string $method_id, int $offset, string $referencing_method_id): void
    {
        self::$method_param_uses[$method_id][$offset][$referencing_method_id] = true;
    }
    /**
     * @return  array<int, string>
     */
    private function calculate_files_referencing_file(Codebase $codebase, string $file): array
    {
        $referenced_files = [];
        $file_classes = Class_Like_Analyzer::get_classes_for_file($codebase, $file);
        foreach ($file_classes as $file_class_lc => $_) {
            if (isset(self::$nonmethod_references_to_classes[$file_class_lc])) {
                $new_files = array_keys(self::$nonmethod_references_to_classes[$file_class_lc]);
                $referenced_files = [...$referenced_files, ...$new_files];
            }
            if (isset(self::$method_references_to_classes[$file_class_lc])) {
                $new_referencing_methods = array_keys(self::$method_references_to_classes[$file_class_lc]);
                foreach ($new_referencing_methods as $new_referencing_method_id) {
                    $fq_class_name_lc = explode('::', $new_referencing_method_id)[0];
                    try {
                        $referenced_files[] = $codebase->scanner->get_class_like_file_path($fq_class_name_lc);
                    } catch (UnexpectedValueException) {
                        if (isset(self::$classlike_files[$fq_class_name_lc])) {
                            $referenced_files[] = self::$classlike_files[$fq_class_name_lc];
                        }
                    }
                }
            }
        }
        return array_unique($referenced_files);
    }
    /**
     * @return  array<int, string>
     */
    private function calculate_files_inheriting_file(Codebase $codebase, string $file): array
    {
        $referenced_files = [];
        $file_classes = Class_Like_Analyzer::get_classes_for_file($codebase, $file);
        foreach ($file_classes as $file_class_lc => $_) {
            if (isset(self::$files_inheriting_classes[$file_class_lc])) {
                $referenced_files = [...$referenced_files, ...array_keys(self::$files_inheriting_classes[$file_class_lc])];
            }
        }
        return array_unique($referenced_files);
    }
    public function remove_deleted_files_from_references(): void
    {
        $deleted_files = $this->get_deleted_referenced_files();
        if ($deleted_files) {
            foreach ($deleted_files as $file) {
                unset(self::$file_references[$file]);
            }
            if ($this->cache) {
                $this->cache->set_cached_file_references(self::$file_references);
            }
        }
    }
    /**
     * @return array<int, string>
     */
    public function get_files_referencing_file(string $file): array
    {
        return self::$file_references[$file]['a'] ?? [];
    }
    /**
     * @return array<int, string>
     */
    public function get_files_inheriting_from_file(string $file): array
    {
        return self::$file_references[$file]['i'] ?? [];
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_references_to_class_members(): array
    {
        return self::$method_references_to_class_members;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_dependencies(): array
    {
        return self::$method_dependencies;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_references_to_class_properties(): array
    {
        return self::$method_references_to_class_properties;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_references_to_method_returns(): array
    {
        return self::$method_references_to_method_returns;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_references_to_classes(): array
    {
        return self::$method_references_to_classes;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_all_method_references_to_missing_class_members(): array
    {
        return self::$method_references_to_missing_class_members;
    }
    /**
     * @return array<string, array<string,bool>>
     */
    public function get_all_references_to_mixed_member_names(): array
    {
        return self::$references_to_mixed_member_names;
    }
    /**
     * @return array<string, array<int, array<string, bool>>>
     */
    public function get_all_method_param_uses(): array
    {
        return self::$method_param_uses;
    }
    /**
     * @psalm-suppress MixedPropertyTypeCoercion
     */
    public function load_reference_cache(bool $force_reload = true): bool
    {
        if ($this->cache && (!$this->loaded_from_cache || $force_reload)) {
            $this->loaded_from_cache = true;
            $file_references = $this->cache->get_cached_file_references();
            if ($file_references === null) {
                return false;
            }
            self::$file_references = $file_references;
            $nonmethod_references_to_classes = $this->cache->get_cached_non_method_class_references();
            if ($nonmethod_references_to_classes === null) {
                return false;
            }
            self::$nonmethod_references_to_classes = $nonmethod_references_to_classes;
            $method_references_to_classes = $this->cache->get_cached_method_class_references();
            if ($method_references_to_classes === null) {
                return false;
            }
            self::$method_references_to_classes = $method_references_to_classes;
            $method_references_to_class_members = $this->cache->get_cached_method_member_references();
            if ($method_references_to_class_members === null) {
                return false;
            }
            self::$method_references_to_class_members = $method_references_to_class_members;
            $method_dependencies = $this->cache->get_cached_method_dependencies();
            if ($method_dependencies === null) {
                return false;
            }
            self::$method_dependencies = $method_dependencies;
            $method_references_to_class_properties = $this->cache->get_cached_method_property_references();
            if ($method_references_to_class_properties === null) {
                return false;
            }
            self::$method_references_to_class_properties = $method_references_to_class_properties;
            $method_references_to_method_returns = $this->cache->get_cached_method_method_return_references();
            if ($method_references_to_method_returns === null) {
                return false;
            }
            self::$method_references_to_method_returns = $method_references_to_method_returns;
            $method_references_to_missing_class_members = $this->cache->get_cached_method_missing_member_references();
            if ($method_references_to_missing_class_members === null) {
                return false;
            }
            self::$method_references_to_missing_class_members = $method_references_to_missing_class_members;
            $file_references_to_class_members = $this->cache->get_cached_file_member_references();
            if ($file_references_to_class_members === null) {
                return false;
            }
            self::$file_references_to_class_members = $file_references_to_class_members;
            $file_references_to_class_properties = $this->cache->get_cached_file_property_references();
            if ($file_references_to_class_properties === null) {
                return false;
            }
            self::$file_references_to_class_properties = $file_references_to_class_properties;
            $file_references_to_method_returns = $this->cache->get_cached_file_method_return_references();
            if ($file_references_to_method_returns === null) {
                return false;
            }
            self::$file_references_to_method_returns = $file_references_to_method_returns;
            $file_references_to_missing_class_members = $this->cache->get_cached_file_missing_member_references();
            if ($file_references_to_missing_class_members === null) {
                return false;
            }
            self::$file_references_to_missing_class_members = $file_references_to_missing_class_members;
            $references_to_mixed_member_names = $this->cache->get_cached_mixed_member_name_references();
            if ($references_to_mixed_member_names === null) {
                return false;
            }
            self::$references_to_mixed_member_names = $references_to_mixed_member_names;
            $analyzed_methods = $this->cache->get_analyzed_method_cache();
            if ($analyzed_methods === false) {
                return false;
            }
            self::$analyzed_methods = $analyzed_methods;
            $issues = $this->cache->get_cached_issues();
            if ($issues === null) {
                return false;
            }
            self::$issues = $issues;
            $method_param_uses = $this->cache->get_cached_method_param_uses();
            if ($method_param_uses === null) {
                return false;
            }
            self::$method_param_uses = $method_param_uses;
            $mixed_counts = $this->cache->get_type_coverage();
            if ($mixed_counts === false) {
                return false;
            }
            self::$mixed_counts = $mixed_counts;
            $classlike_files = $this->cache->get_cached_class_like_files();
            if ($classlike_files === null) {
                return false;
            }
            self::$classlike_files = $classlike_files;
            self::$file_maps = $this->cache->get_file_map_cache() ?: [];
            return true;
        }
        return false;
    }
    /**
     * @param  array<string, string|bool>  $visited_files
     */
    public function update_reference_cache(Codebase $codebase, array $visited_files): void
    {
        foreach ($visited_files as $file => $_) {
            $all_file_references = array_unique(array_merge(self::$file_references[$file]['a'] ?? [], $this->calculate_files_referencing_file($codebase, $file)));
            $inheritance_references = array_unique(array_merge(self::$file_references[$file]['i'] ?? [], $this->calculate_files_inheriting_file($codebase, $file)));
            self::$file_references[$file] = ['a' => $all_file_references, 'i' => $inheritance_references];
        }
        if ($this->cache) {
            $this->cache->set_cached_file_references(self::$file_references);
            $this->cache->set_cached_method_class_references(self::$method_references_to_classes);
            $this->cache->set_cached_non_method_class_references(self::$nonmethod_references_to_classes);
            $this->cache->set_cached_method_member_references(self::$method_references_to_class_members);
            $this->cache->set_cached_method_dependencies(self::$method_dependencies);
            $this->cache->set_cached_method_property_references(self::$method_references_to_class_properties);
            $this->cache->set_cached_method_method_return_references(self::$method_references_to_method_returns);
            $this->cache->set_cached_file_member_references(self::$file_references_to_class_members);
            $this->cache->set_cached_file_property_references(self::$file_references_to_class_properties);
            $this->cache->set_cached_file_method_return_references(self::$file_references_to_method_returns);
            $this->cache->set_cached_method_missing_member_references(self::$method_references_to_missing_class_members);
            $this->cache->set_cached_file_missing_member_references(self::$file_references_to_missing_class_members);
            $this->cache->set_cached_mixed_member_name_references(self::$references_to_mixed_member_names);
            $this->cache->set_cached_method_param_uses(self::$method_param_uses);
            $this->cache->set_cached_issues(self::$issues);
            $this->cache->set_cached_class_like_files(self::$classlike_files);
            $this->cache->set_file_map_cache(self::$file_maps);
            $this->cache->set_type_coverage(self::$mixed_counts);
            $this->cache->set_analyzed_method_cache(self::$analyzed_methods);
        }
    }
    /**
     * @param lowercase-string $fq_class_name_lc
     */
    public function add_method_reference_to_class(string $calling_function_id, string $fq_class_name_lc): void
    {
        if (!isset(self::$method_references_to_classes[$fq_class_name_lc])) {
            self::$method_references_to_classes[$fq_class_name_lc] = [$calling_function_id => true];
        } else {
            self::$method_references_to_classes[$fq_class_name_lc][$calling_function_id] = true;
        }
    }
    public function add_method_reference_to_class_member(string $calling_function_id, string $referenced_member_id, bool $inside_return): void
    {
        if (!isset(self::$method_references_to_class_members[$referenced_member_id])) {
            self::$method_references_to_class_members[$referenced_member_id] = [$calling_function_id => true];
        } else {
            self::$method_references_to_class_members[$referenced_member_id][$calling_function_id] = true;
        }
        if ($inside_return) {
            if (!isset(self::$method_references_to_method_returns[$referenced_member_id])) {
                self::$method_references_to_method_returns[$referenced_member_id] = [$calling_function_id => true];
            } else {
                self::$method_references_to_method_returns[$referenced_member_id][$calling_function_id] = true;
            }
        }
    }
    public function add_method_dependency_to_class_member(string $calling_function_id, string $referenced_member_id): void
    {
        if (!isset(self::$method_dependencies[$referenced_member_id])) {
            self::$method_dependencies[$referenced_member_id] = [$calling_function_id => true];
        } else {
            self::$method_dependencies[$referenced_member_id][$calling_function_id] = true;
        }
    }
    public function add_method_reference_to_class_property(string $calling_function_id, string $referenced_property_id): void
    {
        if (!isset(self::$method_references_to_class_properties[$referenced_property_id])) {
            self::$method_references_to_class_properties[$referenced_property_id] = [$calling_function_id => true];
        } else {
            self::$method_references_to_class_properties[$referenced_property_id][$calling_function_id] = true;
        }
    }
    public function add_method_reference_to_missing_class_member(string $calling_function_id, string $referenced_member_id): void
    {
        if (!isset(self::$method_references_to_missing_class_members[$referenced_member_id])) {
            self::$method_references_to_missing_class_members[$referenced_member_id] = [$calling_function_id => true];
        } else {
            self::$method_references_to_missing_class_members[$referenced_member_id][$calling_function_id] = true;
        }
    }
    public function add_calling_location_for_class_method(Code_Location $code_location, string $referenced_member_id): void
    {
        if (!isset(self::$class_method_locations[$referenced_member_id])) {
            self::$class_method_locations[$referenced_member_id] = [$code_location];
        } else {
            self::$class_method_locations[$referenced_member_id][] = $code_location;
        }
    }
    public function add_calling_location_for_class_property(Code_Location $code_location, string $referenced_property_id): void
    {
        if (!isset(self::$class_property_locations[$referenced_property_id])) {
            self::$class_property_locations[$referenced_property_id] = [$code_location];
        } else {
            self::$class_property_locations[$referenced_property_id][] = $code_location;
        }
    }
    public function add_calling_location_for_class(Code_Location $code_location, string $referenced_class): void
    {
        if (!isset(self::$class_locations[$referenced_class])) {
            self::$class_locations[$referenced_class] = [$code_location];
        } else {
            self::$class_locations[$referenced_class][] = $code_location;
        }
    }
    public function is_class_method_referenced(string $method_id): bool
    {
        return !empty(self::$file_references_to_class_members[$method_id]) || !empty(self::$method_references_to_class_members[$method_id]);
    }
    public function is_class_property_referenced(string $property_id): bool
    {
        return !empty(self::$file_references_to_class_properties[$property_id]) || !empty(self::$method_references_to_class_properties[$property_id]);
    }
    public function is_method_return_referenced(string $method_id): bool
    {
        return !empty(self::$file_references_to_method_returns[$method_id]) || !empty(self::$method_references_to_method_returns[$method_id]);
    }
    public function is_class_referenced(string $fq_class_name_lc): bool
    {
        return isset(self::$method_references_to_classes[$fq_class_name_lc]) || isset(self::$nonmethod_references_to_classes[$fq_class_name_lc]);
    }
    public function is_method_param_used(string $method_id, int $offset): bool
    {
        return !empty(self::$method_param_uses[$method_id][$offset]);
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_non_method_references_to_classes(array $references): void
    {
        self::$nonmethod_references_to_classes = $references;
    }
    /**
     * @return array<string, array<int, CodeLocation>>
     */
    public function get_all_class_method_locations(): array
    {
        return self::$class_method_locations;
    }
    /**
     * @return array<string, array<int, CodeLocation>>
     */
    public function get_all_class_property_locations(): array
    {
        return self::$class_property_locations;
    }
    /**
     * @return array<string, array<int, CodeLocation>>
     */
    public function get_all_class_locations(): array
    {
        return self::$class_locations;
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function get_class_method_locations(string $method_id): array
    {
        return self::$class_method_locations[$method_id] ?? [];
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function get_class_property_locations(string $property_id): array
    {
        return self::$class_property_locations[$property_id] ?? [];
    }
    /**
     * @return array<int, CodeLocation>
     */
    public function get_class_locations(string $fq_class_name_lc): array
    {
        return self::$class_locations[$fq_class_name_lc] ?? [];
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_references_to_class_members(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_references_to_class_members[$key])) {
                self::$method_references_to_class_members[$key] = array_merge($reference, self::$method_references_to_class_members[$key]);
            } else {
                self::$method_references_to_class_members[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_dependencies(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_dependencies[$key])) {
                self::$method_dependencies[$key] = array_merge($reference, self::$method_dependencies[$key]);
            } else {
                self::$method_dependencies[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_references_to_class_properties(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_references_to_class_properties[$key])) {
                self::$method_references_to_class_properties[$key] = array_merge($reference, self::$method_references_to_class_properties[$key]);
            } else {
                self::$method_references_to_class_properties[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_references_to_method_returns(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_references_to_method_returns[$key])) {
                self::$method_references_to_method_returns[$key] = array_merge($reference, self::$method_references_to_method_returns[$key]);
            } else {
                self::$method_references_to_method_returns[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_references_to_classes(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_references_to_classes[$key])) {
                self::$method_references_to_classes[$key] = array_merge($reference, self::$method_references_to_classes[$key]);
            } else {
                self::$method_references_to_classes[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function add_method_references_to_missing_class_members(array $references): void
    {
        foreach ($references as $key => $reference) {
            if (isset(self::$method_references_to_missing_class_members[$key])) {
                self::$method_references_to_missing_class_members[$key] = array_merge($reference, self::$method_references_to_missing_class_members[$key]);
            } else {
                self::$method_references_to_missing_class_members[$key] = $reference;
            }
        }
    }
    /**
     * @param array<string, array<int, array<string, bool>>> $references
     */
    public function add_method_param_uses(array $references): void
    {
        foreach ($references as $method_id => $method_param_uses) {
            if (isset(self::$method_param_uses[$method_id])) {
                foreach ($method_param_uses as $offset => $reference_map) {
                    if (isset(self::$method_param_uses[$method_id][$offset])) {
                        self::$method_param_uses[$method_id][$offset] = array_merge(self::$method_param_uses[$method_id][$offset], $reference_map);
                    } else {
                        self::$method_param_uses[$method_id][$offset] = $reference_map;
                    }
                }
            } else {
                self::$method_param_uses[$method_id] = $method_param_uses;
            }
        }
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_calling_method_references_to_classes(array $references): void
    {
        self::$method_references_to_classes = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_calling_method_references_to_class_members(array $references): void
    {
        self::$method_references_to_class_members = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_method_dependencies(array $references): void
    {
        self::$method_dependencies = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_calling_method_references_to_class_properties(array $references): void
    {
        self::$method_references_to_class_properties = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_calling_method_references_to_method_returns(array $references): void
    {
        self::$method_references_to_method_returns = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_calling_method_references_to_missing_class_members(array $references): void
    {
        self::$method_references_to_missing_class_members = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_file_references_to_class_members(array $references): void
    {
        self::$file_references_to_class_members = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_file_references_to_class_properties(array $references): void
    {
        self::$file_references_to_class_properties = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_file_references_to_method_returns(array $references): void
    {
        self::$file_references_to_method_returns = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_file_references_to_missing_class_members(array $references): void
    {
        self::$file_references_to_missing_class_members = $references;
    }
    /**
     * @param array<string, array<string,bool>> $references
     */
    public function set_references_to_mixed_member_names(array $references): void
    {
        self::$references_to_mixed_member_names = $references;
    }
    /**
     * @param array<string, array<int, array<string, bool>>> $references
     */
    public function set_method_param_uses(array $references): void
    {
        self::$method_param_uses = $references;
    }
    /**
     * @param array<string, array<int, CodeLocation>> $references
     */
    public function add_class_method_locations(array $references): void
    {
        foreach ($references as $referenced_member_id => $locations) {
            if (isset(self::$class_method_locations[$referenced_member_id])) {
                self::$class_method_locations[$referenced_member_id] = [...self::$class_method_locations[$referenced_member_id], ...$locations];
            } else {
                self::$class_method_locations[$referenced_member_id] = $locations;
            }
        }
    }
    /**
     * @param array<string, array<int, CodeLocation>> $references
     */
    public function add_class_property_locations(array $references): void
    {
        foreach ($references as $referenced_member_id => $locations) {
            if (isset(self::$class_property_locations[$referenced_member_id])) {
                self::$class_property_locations[$referenced_member_id] = [...self::$class_property_locations[$referenced_member_id], ...$locations];
            } else {
                self::$class_property_locations[$referenced_member_id] = $locations;
            }
        }
    }
    /**
     * @param array<string, array<int, CodeLocation>> $references
     */
    public function add_class_locations(array $references): void
    {
        foreach ($references as $referenced_member_id => $locations) {
            if (isset(self::$class_locations[$referenced_member_id])) {
                self::$class_locations[$referenced_member_id] = [...self::$class_locations[$referenced_member_id], ...$locations];
            } else {
                self::$class_locations[$referenced_member_id] = $locations;
            }
        }
    }
    /**
     * @return array<string, array<int, IssueData>>
     */
    public function get_existing_issues(): array
    {
        return self::$issues;
    }
    public function clear_existing_issues_for_file(string $file_path): void
    {
        unset(self::$issues[$file_path]);
    }
    public function clear_existing_file_maps_for_file(string $file_path): void
    {
        unset(self::$file_maps[$file_path]);
    }
    public function add_issue(string $file_path, Issue_Data $issue): void
    {
        // don’t save parse errors ever, as they're not responsive to AST diffing
        if ($issue->type === 'ParseError') {
            return;
        }
        if (!isset(self::$issues[$file_path])) {
            self::$issues[$file_path] = [$issue];
        } else {
            self::$issues[$file_path][] = $issue;
        }
    }
    /**
     * @param array<string, array<string, int>> $analyzed_methods
     */
    public function set_analyzed_methods(array $analyzed_methods): void
    {
        self::$analyzed_methods = $analyzed_methods;
    }
    /**
     * @param array<string, FileMapType> $file_maps
     */
    public function set_file_maps(array $file_maps): void
    {
        self::$file_maps = $file_maps;
    }
    /**
     * @return array<string, array{int, int}>
     */
    public function get_type_coverage(): array
    {
        return self::$mixed_counts;
    }
    /**
     * @param array<string, array{int, int}> $mixed_counts
     */
    public function set_type_coverage(array $mixed_counts): void
    {
        self::$mixed_counts = [...self::$mixed_counts, ...$mixed_counts];
    }
    /**
     * @return array<string, array<string, int>>
     */
    public function get_analyzed_methods(): array
    {
        return self::$analyzed_methods;
    }
    /**
     * @return array<string, FileMapType>
     */
    public function get_file_maps(): array
    {
        return self::$file_maps;
    }
    public static function clear_cache(): void
    {
        self::$files_inheriting_classes = [];
        self::$deleted_files = null;
        self::$file_references = [];
        self::$file_references_to_class_members = [];
        self::$file_references_to_class_properties = [];
        self::$file_references_to_method_returns = [];
        self::$method_references_to_class_members = [];
        self::$method_dependencies = [];
        self::$method_references_to_class_properties = [];
        self::$method_references_to_method_returns = [];
        self::$method_references_to_classes = [];
        self::$nonmethod_references_to_classes = [];
        self::$file_references_to_missing_class_members = [];
        self::$method_references_to_missing_class_members = [];
        self::$references_to_mixed_member_names = [];
        self::$class_method_locations = [];
        self::$class_property_locations = [];
        self::$class_locations = [];
        self::$analyzed_methods = [];
        self::$issues = [];
        self::$file_maps = [];
        self::$method_param_uses = [];
        self::$classlike_files = [];
        self::$mixed_counts = [];
    }
}