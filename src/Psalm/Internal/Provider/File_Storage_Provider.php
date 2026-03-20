<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use InvalidArgumentException;
use Psalm\Storage\File_Storage;
use function strtolower;
/**
 * @internal
 */
final class File_Storage_Provider
{
    /**
     * A list of data useful to analyse files
     * Storing this statically is much faster (at least in PHP 7.2.1)
     *
     * @var array<lowercase-string, FileStorage>
     */
    private static array $storage = [];
    /**
     * A list of data useful to analyse new files
     * Storing this statically is much faster (at least in PHP 7.2.1)
     *
     * @var array<string, FileStorage>
     */
    private static array $new_storage = [];
    public function __construct(public ?File_Storage_Cache_Provider $cache = null)
    {
    }
    public function get(string $file_path): File_Storage
    {
        $file_path = strtolower($file_path);
        if (!isset(self::$storage[$file_path])) {
            throw new InvalidArgumentException('Could not get file storage for ' . $file_path);
        }
        return self::$storage[$file_path];
    }
    public function remove(string $file_path): void
    {
        unset(self::$storage[strtolower($file_path)]);
    }
    public function has(string $file_path, ?string $file_contents = null): bool
    {
        $file_path = strtolower($file_path);
        if (isset(self::$storage[$file_path])) {
            return true;
        }
        if ($file_contents === null) {
            return false;
        }
        if (!$this->cache) {
            return false;
        }
        $cached_value = $this->cache->get_latest_from_cache($file_path, $file_contents);
        if (!$cached_value) {
            return false;
        }
        self::$storage[$file_path] = $cached_value;
        self::$new_storage[$file_path] = $cached_value;
        return true;
    }
    /**
     * @return array<lowercase-string, FileStorage>
     */
    public static function get_all(): array
    {
        return self::$storage;
    }
    /**
     * @return array<string, FileStorage>
     */
    public function get_new(): array
    {
        return self::$new_storage;
    }
    /**
     * @param array<lowercase-string, FileStorage> $more
     */
    public function add_more(array $more): void
    {
        self::$new_storage = [...self::$new_storage, ...$more];
        self::$storage = [...self::$storage, ...$more];
    }
    public function create(string $file_path): File_Storage
    {
        $file_path_lc = strtolower($file_path);
        $storage = new File_Storage($file_path);
        self::$storage[$file_path_lc] = $storage;
        self::$new_storage[$file_path_lc] = $storage;
        return $storage;
    }
    public static function delete_all(): void
    {
        self::$storage = [];
    }
    public static function populated(): void
    {
        self::$new_storage = [];
    }
}