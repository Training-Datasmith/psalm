<?php

declare (strict_types=1);
namespace Psalm\Config;

use Filesystem_Iterator;
use Psalm\Exception\Config_Exception;
use Recursive_Directory_Iterator;
use Recursive_Iterator_Iterator;
use Simple_Xml_Element;
use Symfony\Component\Filesystem\Path;
use function array_filter;
use function array_map;
use function array_merge;
use function array_shift;
use function assert;
use function count;
use function explode;
use function glob;
use function in_array;
use function is_dir;
use function is_iterable;
use function is_string;
use function preg_match;
use function preg_replace;
use function preg_split;
use function readlink;
use function realpath;
use function restore_error_handler;
use function rtrim;
use function set_error_handler;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function stripos;
use function strtolower;
use const DIRECTORY_SEPARATOR;
use const E_WARNING;
use const GLOB_NOSORT;
use const GLOB_ONLYDIR;
/**
 * @psalm-consistent-constructor
 */
class File_Filter
{
    /**
     * @var array<string>
     */
    protected array $directories = [];
    /**
     * @var array<string>
     */
    protected array $files = [];
    /**
     * @var array<string>
     */
    protected array $fq_classlike_names = [];
    /**
     * @var array<non-empty-string>
     */
    protected array $fq_classlike_patterns = [];
    /**
     * @var array<non-empty-string>
     */
    protected array $method_ids = [];
    /**
     * @var array<string>
     */
    protected array $property_ids = [];
    /**
     * @var array<string>
     */
    protected array $class_constant_ids = [];
    /**
     * @var array<string>
     */
    protected array $var_names = [];
    /**
     * @var array<string>
     */
    protected array $files_lowercase = [];
    /**
     * @var array<string, bool>
     */
    protected array $ignore_type_stats = [];
    /**
     * @var array<string, bool>
     */
    protected array $declare_strict_types = [];
    public function __construct(protected bool $inclusive)
    {
    }
    public static function load_from_array(array $config, string $base_dir, bool $inclusive): static
    {
        $allow_missing_files = ($config['allowMissingFiles'] ?? false) === true;
        $filter = new static($inclusive);
        if (isset($config['directory']) && is_iterable($config['directory'])) {
            /** @var array $directory */
            foreach ($config['directory'] as $directory) {
                $directory_path = (string) ($directory['name'] ?? '');
                $ignore_type_stats = (bool) ($directory['ignoreTypeStats'] ?? false);
                $resolve_symlinks = (bool) ($directory['resolveSymlinks'] ?? false);
                $declare_strict_types = (bool) ($directory['useStrictTypes'] ?? false);
                if (Path::is_absolute($directory_path)) {
                    /** @var non-empty-string */
                    $prospective_directory_path = $directory_path;
                } else {
                    $prospective_directory_path = $base_dir . DIRECTORY_SEPARATOR . $directory_path;
                }
                if (str_contains($prospective_directory_path, '*')) {
                    // Strip meaningless trailing recursive wildcard like "path/**/" or "path/**"
                    $prospective_directory_path = (string) preg_replace('#(\/\*\*)+\/?$#', '/', $prospective_directory_path);
                    // Split by /**/, allow duplicated wildcards like "path/**/**/path" and any leading dir separator.
                    /** @var non-empty-list<non-empty-string> $path_parts */
                    $path_parts = preg_split('#(\/|\\\\)(\*\*\/)+#', $prospective_directory_path);
                    $globs = self::recursive_glob($path_parts, true);
                    if (empty($globs)) {
                        if ($allow_missing_files) {
                            continue;
                        }
                        throw new Config_Exception('Could not resolve config path to ' . $base_dir . DIRECTORY_SEPARATOR . $directory_path);
                    }
                    foreach ($globs as $glob_index => $glob_directory_path) {
                        if (!$glob_directory_path) {
                            if ($allow_missing_files) {
                                continue;
                            }
                            throw new Config_Exception('Could not resolve config path to ' . $base_dir . DIRECTORY_SEPARATOR . $directory_path . ':' . $glob_index);
                        }
                        if ($ignore_type_stats && $filter instanceof Project_File_Filter) {
                            $filter->ignore_type_stats[$glob_directory_path] = true;
                        }
                        if ($declare_strict_types && $filter instanceof Project_File_Filter) {
                            $filter->declare_strict_types[$glob_directory_path] = true;
                        }
                        $filter->add_directory($glob_directory_path);
                    }
                    continue;
                }
                $directory_path = realpath($prospective_directory_path);
                if (!$directory_path) {
                    if ($allow_missing_files) {
                        continue;
                    }
                    throw new Config_Exception('Could not resolve config path to ' . $prospective_directory_path);
                }
                if (!is_dir($directory_path)) {
                    throw new Config_Exception($base_dir . DIRECTORY_SEPARATOR . $directory_path . ' is not a directory');
                }
                if ($resolve_symlinks) {
                    /** @var RecursiveDirectoryIterator */
                    $iterator = new Recursive_Iterator_Iterator(new Recursive_Directory_Iterator($directory_path, Filesystem_Iterator::SKIP_DOTS));
                    $iterator->rewind();
                    while ($iterator->valid()) {
                        if ($iterator->is_link()) {
                            $linked_path = (string) readlink($iterator->get_pathname());
                            if (stripos($linked_path, $directory_path) !== 0) {
                                if ($ignore_type_stats && $filter instanceof Project_File_Filter) {
                                    $filter->ignore_type_stats[$directory_path] = true;
                                }
                                if ($declare_strict_types && $filter instanceof Project_File_Filter) {
                                    $filter->declare_strict_types[$directory_path] = true;
                                }
                                if (is_dir($linked_path)) {
                                    $filter->add_directory($linked_path);
                                }
                            }
                        }
                        $iterator->next();
                    }
                    $iterator->next();
                }
                if ($ignore_type_stats && $filter instanceof Project_File_Filter) {
                    $filter->ignore_type_stats[$directory_path] = true;
                }
                if ($declare_strict_types && $filter instanceof Project_File_Filter) {
                    $filter->declare_strict_types[$directory_path] = true;
                }
                $filter->add_directory($directory_path);
            }
        }
        if (isset($config['file']) && is_iterable($config['file'])) {
            /** @var array $file */
            foreach ($config['file'] as $file) {
                $file_path = (string) ($file['name'] ?? '');
                if (Path::is_absolute($file_path)) {
                    /** @var non-empty-string */
                    $prospective_file_path = $file_path;
                } else {
                    $prospective_file_path = $base_dir . DIRECTORY_SEPARATOR . $file_path;
                }
                if (str_contains($prospective_file_path, '*')) {
                    // Split by /**/, allow duplicated wildcards like "path/**/**/path" and any leading dir separator.
                    /** @var non-empty-list<non-empty-string> $path_parts */
                    $path_parts = preg_split('#(\/|\\\\)(\*\*\/)+#', $prospective_file_path);
                    $globs = self::recursive_glob($path_parts, false);
                    if (empty($globs)) {
                        if ($allow_missing_files) {
                            continue;
                        }
                        throw new Config_Exception('Could not resolve config path to ' . $base_dir . DIRECTORY_SEPARATOR . $file_path);
                    }
                    foreach ($globs as $glob_index => $glob_file_path) {
                        if (!$glob_file_path) {
                            if ($allow_missing_files) {
                                continue;
                            }
                            throw new Config_Exception('Could not resolve config path to ' . $base_dir . DIRECTORY_SEPARATOR . $file_path . ':' . $glob_index);
                        }
                        $filter->add_file($glob_file_path);
                    }
                    continue;
                }
                $file_path = (string) realpath($prospective_file_path);
                if (!$file_path) {
                    if ($allow_missing_files) {
                        continue;
                    }
                    throw new Config_Exception('Could not resolve config path to ' . $prospective_file_path);
                }
                $filter->add_file($file_path);
            }
        }
        if (isset($config['referencedClass']) && is_iterable($config['referencedClass'])) {
            /** @var array $referenced_class */
            foreach ($config['referencedClass'] as $referenced_class) {
                $class_name = strtolower((string) ($referenced_class['name'] ?? ''));
                if (str_contains($class_name, '*')) {
                    $regex = '/' . str_replace('*', '.*', str_replace('\\', '\\\\', $class_name)) . '/i';
                    $filter->fq_classlike_patterns[] = $regex;
                } else {
                    $filter->fq_classlike_names[] = $class_name;
                }
            }
        }
        if (isset($config['referencedMethod']) && is_iterable($config['referencedMethod'])) {
            /** @var array $referenced_method */
            foreach ($config['referencedMethod'] as $referenced_method) {
                $method_id = $referenced_method['name'] ?? '';
                if (!is_string($method_id) || !preg_match('/^[^:]+::[^:]+$/', $method_id) && !static::is_regular_expression($method_id)) {
                    throw new Config_Exception('Invalid referencedMethod ' . $method_id);
                }
                if ($method_id === '') {
                    continue;
                }
                $filter->method_ids[] = strtolower($method_id);
            }
        }
        if (isset($config['referencedFunction']) && is_iterable($config['referencedFunction'])) {
            /** @var array $referenced_function */
            foreach ($config['referencedFunction'] as $referenced_function) {
                $function_id = $referenced_function['name'] ?? '';
                if (!is_string($function_id) || !preg_match('/^[a-zA-Z_\x80-\xff](?:[\\\\]?[a-zA-Z0-9_\x80-\xff]+)*$/', $function_id) && !preg_match('/^[^:]+::[^:]+$/', $function_id) && !static::is_regular_expression($function_id)) {
                    throw new Config_Exception('Invalid referencedFunction ' . $function_id);
                }
                if ($function_id === '') {
                    continue;
                }
                $filter->method_ids[] = strtolower($function_id);
            }
        }
        if (isset($config['referencedProperty']) && is_iterable($config['referencedProperty'])) {
            /** @var array $referenced_property */
            foreach ($config['referencedProperty'] as $referenced_property) {
                $filter->property_ids[] = strtolower((string) ($referenced_property['name'] ?? ''));
            }
        }
        if (isset($config['referencedConstant']) && is_iterable($config['referencedConstant'])) {
            /** @var array $referenced_constant */
            foreach ($config['referencedConstant'] as $referenced_constant) {
                $filter->class_constant_ids[] = strtolower((string) ($referenced_constant['name'] ?? ''));
            }
        }
        if (isset($config['referencedVariable']) && is_iterable($config['referencedVariable'])) {
            /** @var array $referenced_variable */
            foreach ($config['referencedVariable'] as $referenced_variable) {
                $filter->var_names[] = strtolower((string) ($referenced_variable['name'] ?? ''));
            }
        }
        return $filter;
    }
    public static function load_from_xml_element(Simple_Xml_Element $e, string $base_dir, bool $inclusive): static
    {
        $config = [];
        $config['allowMissingFiles'] = (string) $e['allowMissingFiles'] === 'true';
        if ($e->directory) {
            $config['directory'] = [];
            foreach ($e->directory as $directory) {
                $config['directory'][] = ['name' => (string) $directory['name'], 'ignoreTypeStats' => strtolower((string) ($directory['ignoreTypeStats'] ?? '')) === 'true', 'resolveSymlinks' => strtolower((string) ($directory['resolveSymlinks'] ?? '')) === 'true', 'useStrictTypes' => strtolower((string) ($directory['useStrictTypes'] ?? '')) === 'true'];
            }
        }
        if ($e->file) {
            $config['file'] = [];
            foreach ($e->file as $file) {
                $config['file'][]['name'] = (string) $file['name'];
            }
        }
        if ($e->referenced_class) {
            $config['referencedClass'] = [];
            foreach ($e->referenced_class as $referenced_class) {
                $config['referencedClass'][]['name'] = strtolower((string) $referenced_class['name']);
            }
        }
        if ($e->referenced_method) {
            $config['referencedMethod'] = [];
            foreach ($e->referenced_method as $referenced_method) {
                $config['referencedMethod'][]['name'] = (string) $referenced_method['name'];
            }
        }
        if ($e->referenced_function) {
            $config['referencedFunction'] = [];
            foreach ($e->referenced_function as $referenced_function) {
                $config['referencedFunction'][]['name'] = strtolower((string) $referenced_function['name']);
            }
        }
        if ($e->referenced_property) {
            $config['referencedProperty'] = [];
            foreach ($e->referenced_property as $referenced_property) {
                $config['referencedProperty'][]['name'] = strtolower((string) $referenced_property['name']);
            }
        }
        if ($e->referenced_constant) {
            $config['referencedConstant'] = [];
            foreach ($e->referenced_constant as $referenced_constant) {
                $config['referencedConstant'][]['name'] = strtolower((string) $referenced_constant['name']);
            }
        }
        if ($e->referenced_variable) {
            $config['referencedVariable'] = [];
            foreach ($e->referenced_variable as $referenced_variable) {
                $config['referencedVariable'][]['name'] = strtolower((string) $referenced_variable['name']);
            }
        }
        return self::load_from_array($config, $base_dir, $inclusive);
    }
    /**
     * @psalm-assert-if-true non-empty-string $string
     */
    private static function is_regular_expression(string $string): bool
    {
        if ($string === '') {
            return false;
        }
        set_error_handler(static fn(): bool => true, E_WARNING);
        $is_regexp = preg_match($string, '') !== false;
        restore_error_handler();
        return $is_regexp;
    }
    /**
     * @mutation-free
     * @param non-empty-list<non-empty-string> $parts
     * @return array<string|false>
     */
    private static function recursive_glob(array $parts, bool $only_dir): array
    {
        if (count($parts) < 2) {
            if ($only_dir) {
                $list = glob($parts[0], GLOB_ONLYDIR | GLOB_NOSORT) ?: [];
            } else {
                $list = array_filter(glob($parts[0], GLOB_NOSORT) ?: [], file_exists(...));
            }
            return array_map(realpath(...), $list);
        }
        $first_dir = self::slashify($parts[0]);
        $paths = glob($first_dir . '*', GLOB_ONLYDIR | GLOB_NOSORT);
        assert($paths !== false);
        $result = [];
        foreach ($paths as $path) {
            $parts[0] = $path;
            $result = array_merge($result, self::recursive_glob($parts, $only_dir));
        }
        array_shift($parts);
        $parts[0] = $first_dir . $parts[0];
        return array_merge($result, self::recursive_glob($parts, $only_dir));
    }
    /**
     * @psalm-pure
     */
    protected static function slashify(string $str): string
    {
        return rtrim($str, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
    public function allows(string $file_name, bool $case_sensitive = false): bool
    {
        if ($this->inclusive) {
            foreach ($this->directories as $include_dir) {
                if ($case_sensitive) {
                    if (str_starts_with($file_name, $include_dir)) {
                        return true;
                    }
                } else if (stripos($file_name, $include_dir) === 0) {
                    return true;
                }
            }
            if ($case_sensitive) {
                if (in_array($file_name, $this->files, true)) {
                    return true;
                }
            } else if (in_array(strtolower($file_name), $this->files_lowercase, true)) {
                return true;
            }
            return false;
        }
        // exclusive
        foreach ($this->directories as $exclude_dir) {
            if ($case_sensitive) {
                if (str_starts_with($file_name, $exclude_dir)) {
                    return false;
                }
            } else if (stripos($file_name, $exclude_dir) === 0) {
                return false;
            }
        }
        if ($case_sensitive) {
            if (in_array($file_name, $this->files, true)) {
                return false;
            }
        } else if (in_array(strtolower($file_name), $this->files_lowercase, true)) {
            return false;
        }
        return true;
    }
    public function allows_class(string $fq_classlike_name): bool
    {
        foreach ($this->fq_classlike_patterns as $pattern) {
            if (preg_match($pattern, $fq_classlike_name)) {
                return true;
            }
        }
        return in_array(strtolower($fq_classlike_name), $this->fq_classlike_names, true);
    }
    public function allows_method(string $method_id): bool
    {
        if (!$this->method_ids) {
            return false;
        }
        if (preg_match('/^[^:]+::[^:]+$/', $method_id)) {
            $method_stub = '*::' . explode('::', $method_id)[1];
            foreach ($this->method_ids as $config_method_id) {
                if ($config_method_id === $method_id) {
                    return true;
                }
                if ($config_method_id === $method_stub) {
                    return true;
                }
                if ($config_method_id[0] === '/' && preg_match($config_method_id, $method_id)) {
                    return true;
                }
            }
            return false;
        }
        return in_array($method_id, $this->method_ids, true);
    }
    public function allows_property(string $property_id): bool
    {
        return in_array(strtolower($property_id), $this->property_ids, true);
    }
    public function allows_class_constant(string $constant_id): bool
    {
        return in_array(strtolower($constant_id), $this->class_constant_ids, true);
    }
    public function allows_variable(string $var_name): bool
    {
        return in_array(strtolower($var_name), $this->var_names, true);
    }
    /**
     * @return array<string>
     */
    public function get_directories(): array
    {
        return $this->directories;
    }
    /**
     * @return array<string>
     */
    public function get_files(): array
    {
        return $this->files;
    }
    public function add_file(string $file_name): void
    {
        $this->files[] = $file_name;
        $this->files_lowercase[] = strtolower($file_name);
    }
    public function add_directory(string $dir_name): void
    {
        $this->directories[] = self::slashify($dir_name);
    }
}