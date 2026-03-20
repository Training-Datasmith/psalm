<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Psalm\Config;
use Psalm\Internal\Cache;
use Psalm\Storage\Class_Like_Storage;
use UnexpectedValueException;
use function array_merge;
use function dirname;
use function file_exists;
use function filemtime;
use function hash;
use function strtolower;
use const DIRECTORY_SEPARATOR;
/**
 * @internal
 */
final class Class_Like_Storage_Cache_Provider
{
    /** @var Cache<ClassLikeStorage> */
    private readonly Cache $cache;
    public function __construct(Config $config, string $composer_lock, bool $persistent = true)
    {
        $storage_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'Storage' . DIRECTORY_SEPARATOR;
        $dependent_files = [$storage_dir . 'FileStorage.php', $storage_dir . 'FunctionLikeStorage.php', $storage_dir . 'ClassLikeStorage.php', $storage_dir . 'MethodStorage.php'];
        if ($config->event_dispatcher->has_after_class_like_visit_handlers()) {
            $dependent_files = array_merge($dependent_files, $config->plugin_paths);
        }
        $dependencies = [$composer_lock];
        foreach ($dependent_files as $dependent_file_path) {
            if (!file_exists($dependent_file_path)) {
                throw new UnexpectedValueException($dependent_file_path . ' must exist');
            }
            $dependencies[] = filemtime($dependent_file_path);
        }
        $this->cache = new Cache($config, 'classlike_cache', $dependencies, $persistent);
    }
    public function consolidate(): void
    {
        $this->cache->consolidate();
    }
    public function write_to_cache(Class_Like_Storage $storage, string $file_path, string $file_contents): void
    {
        $fq_classlike_name_lc = strtolower($storage->name);
        $this->cache->save_item($file_path . "\x00" . $fq_classlike_name_lc, $storage, hash('xxh128', $file_contents));
    }
    /**
     * @param lowercase-string $fq_classlike_name_lc
     */
    public function get_latest_from_cache(string $fq_classlike_name_lc, ?string $file_path, string $file_contents): Class_Like_Storage
    {
        return $this->cache->get_item($file_path . "\x00" . $fq_classlike_name_lc, hash('xxh128', $file_contents));
    }
}