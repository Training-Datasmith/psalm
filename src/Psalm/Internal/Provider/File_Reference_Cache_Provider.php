<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Psalm\Config;
use Psalm\Internal\Cache;
use Psalm\Internal\Codebase\Analyzer;
/**
 * Used to determine which files reference other files, necessary for using the --diff
 * option from the command line.
 *
 * @psalm-import-type FileMapType from Analyzer
 * @internal
 */
final class File_Reference_Cache_Provider
{
    private const REFERENCE_CACHE_NAME = 'references';
    private const CLASSLIKE_FILE_CACHE_NAME = 'classlike_files';
    private const NONMETHOD_CLASS_REFERENCE_CACHE_NAME = 'file_class_references';
    private const METHOD_CLASS_REFERENCE_CACHE_NAME = 'method_class_references';
    private const ANALYZED_METHODS_CACHE_NAME = 'analyzed_methods';
    private const CLASS_METHOD_CACHE_NAME = 'class_method_references';
    private const METHOD_DEPENDENCIES_CACHE_NAME = 'class_method_dependencies';
    private const CLASS_PROPERTY_CACHE_NAME = 'class_property_references';
    private const CLASS_METHOD_RETURN_CACHE_NAME = 'class_method_return_references';
    private const FILE_METHOD_RETURN_CACHE_NAME = 'file_method_return_references';
    private const FILE_CLASS_MEMBER_CACHE_NAME = 'file_class_member_references';
    private const FILE_CLASS_PROPERTY_CACHE_NAME = 'file_class_property_references';
    private const ISSUES_CACHE_NAME = 'issues';
    private const FILE_MAPS_CACHE_NAME = 'file_maps';
    private const TYPE_COVERAGE_CACHE_NAME = 'type_coverage';
    private const METHOD_MISSING_MEMBER_CACHE_NAME = 'method_missing_member';
    private const FILE_MISSING_MEMBER_CACHE_NAME = 'file_missing_member';
    private const UNKNOWN_MEMBER_CACHE_NAME = 'unknown_member_references';
    private const METHOD_PARAM_USE_CACHE_NAME = 'method_param_uses';
    /** @var Cache<array> */
    private readonly Cache $cache;
    public function __construct(Config $config, string $composer_lock, public readonly bool $persistent = true)
    {
        $this->cache = new Cache($config, 'file_reference', [$composer_lock], $persistent);
    }
    public function consolidate(): void
    {
        $this->cache->consolidate();
    }
    public function get_cached_file_references(): ?array
    {
        return $this->cache->get_item(self::REFERENCE_CACHE_NAME);
    }
    public function get_cached_class_like_files(): ?array
    {
        return $this->cache->get_item(self::CLASSLIKE_FILE_CACHE_NAME);
    }
    public function get_cached_non_method_class_references(): ?array
    {
        return $this->cache->get_item(self::NONMETHOD_CLASS_REFERENCE_CACHE_NAME);
    }
    public function get_cached_method_class_references(): ?array
    {
        return $this->cache->get_item(self::METHOD_CLASS_REFERENCE_CACHE_NAME);
    }
    public function get_cached_method_member_references(): ?array
    {
        return $this->cache->get_item(self::CLASS_METHOD_CACHE_NAME);
    }
    public function get_cached_method_dependencies(): ?array
    {
        return $this->cache->get_item(self::METHOD_DEPENDENCIES_CACHE_NAME);
    }
    public function get_cached_method_property_references(): ?array
    {
        return $this->cache->get_item(self::CLASS_PROPERTY_CACHE_NAME);
    }
    public function get_cached_method_method_return_references(): ?array
    {
        return $this->cache->get_item(self::CLASS_METHOD_RETURN_CACHE_NAME);
    }
    public function get_cached_method_missing_member_references(): ?array
    {
        return $this->cache->get_item(self::METHOD_MISSING_MEMBER_CACHE_NAME);
    }
    public function get_cached_file_member_references(): ?array
    {
        return $this->cache->get_item(self::FILE_CLASS_MEMBER_CACHE_NAME);
    }
    public function get_cached_file_property_references(): ?array
    {
        return $this->cache->get_item(self::FILE_CLASS_PROPERTY_CACHE_NAME);
    }
    public function get_cached_file_method_return_references(): ?array
    {
        return $this->cache->get_item(self::FILE_METHOD_RETURN_CACHE_NAME);
    }
    public function get_cached_file_missing_member_references(): ?array
    {
        return $this->cache->get_item(self::FILE_MISSING_MEMBER_CACHE_NAME);
    }
    public function get_cached_mixed_member_name_references(): ?array
    {
        return $this->cache->get_item(self::UNKNOWN_MEMBER_CACHE_NAME);
    }
    public function get_cached_method_param_uses(): ?array
    {
        return $this->cache->get_item(self::METHOD_PARAM_USE_CACHE_NAME);
    }
    public function get_cached_issues(): ?array
    {
        return $this->cache->get_item(self::ISSUES_CACHE_NAME);
    }
    public function set_cached_file_references(array $file_references): void
    {
        $this->cache->save_item(self::REFERENCE_CACHE_NAME, $file_references);
    }
    public function set_cached_class_like_files(array $file_references): void
    {
        $this->cache->save_item(self::CLASSLIKE_FILE_CACHE_NAME, $file_references);
    }
    public function set_cached_non_method_class_references(array $file_class_references): void
    {
        $this->cache->save_item(self::NONMETHOD_CLASS_REFERENCE_CACHE_NAME, $file_class_references);
    }
    public function set_cached_method_class_references(array $method_class_references): void
    {
        $this->cache->save_item(self::METHOD_CLASS_REFERENCE_CACHE_NAME, $method_class_references);
    }
    public function set_cached_method_member_references(array $member_references): void
    {
        $this->cache->save_item(self::CLASS_METHOD_CACHE_NAME, $member_references);
    }
    public function set_cached_method_dependencies(array $member_references): void
    {
        $this->cache->save_item(self::METHOD_DEPENDENCIES_CACHE_NAME, $member_references);
    }
    public function set_cached_method_property_references(array $property_references): void
    {
        $this->cache->save_item(self::CLASS_PROPERTY_CACHE_NAME, $property_references);
    }
    public function set_cached_method_method_return_references(array $method_return_references): void
    {
        $this->cache->save_item(self::CLASS_METHOD_RETURN_CACHE_NAME, $method_return_references);
    }
    public function set_cached_method_missing_member_references(array $member_references): void
    {
        $this->cache->save_item(self::METHOD_MISSING_MEMBER_CACHE_NAME, $member_references);
    }
    public function set_cached_file_member_references(array $member_references): void
    {
        $this->cache->save_item(self::FILE_CLASS_MEMBER_CACHE_NAME, $member_references);
    }
    public function set_cached_file_property_references(array $property_references): void
    {
        $this->cache->save_item(self::FILE_CLASS_PROPERTY_CACHE_NAME, $property_references);
    }
    public function set_cached_file_method_return_references(array $method_return_references): void
    {
        $this->cache->save_item(self::FILE_METHOD_RETURN_CACHE_NAME, $method_return_references);
    }
    public function set_cached_file_missing_member_references(array $member_references): void
    {
        $this->cache->save_item(self::FILE_MISSING_MEMBER_CACHE_NAME, $member_references);
    }
    public function set_cached_mixed_member_name_references(array $references): void
    {
        $this->cache->save_item(self::UNKNOWN_MEMBER_CACHE_NAME, $references);
    }
    public function set_cached_method_param_uses(array $uses): void
    {
        $this->cache->save_item(self::METHOD_PARAM_USE_CACHE_NAME, $uses);
    }
    public function set_cached_issues(array $issues): void
    {
        $this->cache->save_item(self::ISSUES_CACHE_NAME, $issues);
    }
    /**
     * @return array<string, array<string, int>>|false
     */
    public function get_analyzed_method_cache(): array|false
    {
        /** @var null|array<string, array<string, int>> $cache_item */
        $cache_item = $this->cache->get_item(self::ANALYZED_METHODS_CACHE_NAME);
        return $cache_item ?? false;
    }
    /**
     * @param array<string, array<string, int>> $analyzed_methods
     */
    public function set_analyzed_method_cache(array $analyzed_methods): void
    {
        $this->cache->save_item(self::ANALYZED_METHODS_CACHE_NAME, $analyzed_methods);
    }
    /**
     * @return array<string, FileMapType>|false
     */
    public function get_file_map_cache(): array|false
    {
        /** @var array<string, FileMapType>|null $cache_item */
        $cache_item = $this->cache->get_item(self::FILE_MAPS_CACHE_NAME);
        return $cache_item ?? false;
    }
    /**
     * @param array<string, FileMapType> $file_maps
     */
    public function set_file_map_cache(array $file_maps): void
    {
        $this->cache->save_item(self::FILE_MAPS_CACHE_NAME, $file_maps);
    }
    /**
     * @return array<string, array{int, int}>|false
     */
    public function get_type_coverage(): array|false
    {
        /** @var array<string, array{int, int}>|null $cache_item */
        $cache_item = $this->cache->get_item(self::TYPE_COVERAGE_CACHE_NAME);
        return $cache_item ?? false;
    }
    /**
     * @param array<string, array{int, int}> $mixed_counts
     */
    public function set_type_coverage(array $mixed_counts): void
    {
        $this->cache->save_item(self::TYPE_COVERAGE_CACHE_NAME, $mixed_counts);
    }
}