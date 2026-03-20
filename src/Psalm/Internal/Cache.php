<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Amp\Serialization\Serializer;
use AssertionError;
use Directory_Iterator;
use Psalm\Config;
use Psalm\Internal\Provider\Providers;
use RuntimeException;
use Webmozart\Assert\Assert;
use function assert;
use function fclose;
use function fflush;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function ftruncate;
use function fwrite;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function is_dir;
use function is_int;
use function mkdir;
use function pack;
use function stream_get_contents;
use function strlen;
use function substr;
use function substr_compare;
use function unlink;
use function unpack;
use function usleep;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_SH;
use const LOCK_UN;
/**
 * @internal
 * @template T as array|object|string
 */
final class Cache
{
    /** @psalm-suppress PropertyNotSetInConstructor intentional */
    private readonly string $dir;
    private readonly Serializer $serializer;
    /** @var array<string, list{string, T}> */
    private array $cache = [];
    /** @var resource */
    private readonly mixed $lock;
    private readonly bool $array_cache;
    public function __construct(Config $config, string $subdir, array $dependencies = [], private readonly bool $persistent = true)
    {
        $this->serializer = $config->get_cache_serializer();
        $this->array_cache = $config->array_cache;
        if (!$persistent) {
            return;
        }
        $dir = $config->get_cache_directory() . DIRECTORY_SEPARATOR . $subdir;
        $hash = hash_init('xxh128');
        foreach ($dependencies as $dep) {
            hash_update($hash, (string) $dep);
            hash_update($hash, "\x00");
        }
        hash_update($hash, $config->compute_hash());
        hash_update($hash, "\x00");
        hash_update($hash, $this->serializer->serialize($this->serializer));
        $dir .= DIRECTORY_SEPARATOR . hash_final($hash);
        $this->dir = $dir . DIRECTORY_SEPARATOR;
        try {
            if (mkdir($this->dir, 0777, true) === false) {
                // any other error than directory already exists/permissions issue
                throw new RuntimeException('Failed to create ' . $this->dir . ' cache directory for unknown reasons');
            }
        } catch (RuntimeException $e) {
            // Race condition (#4483)
            if (!is_dir($this->dir)) {
                // rethrow the error with default message
                // it contains the reason why creation failed
                throw $e;
            }
        }
        $lock = fopen($this->dir . 'lock', 'c');
        assert($lock !== false);
        flock($lock, LOCK_SH);
        $this->lock = $lock;
        if (file_exists($this->dir . 'consolidated') && $this->array_cache) {
            $this->cache = $this->serializer->unserialize(Providers::safe_file_get_contents($this->dir . 'consolidated'));
        }
    }
    public function consolidate(): void
    {
        flock($this->lock, LOCK_UN);
        flock($this->lock, LOCK_EX);
        foreach (new Directory_Iterator($this->dir) as $f) {
            if ($f->is_file() && !$f->is_dot() && $f->get_extension() === 'hash') {
                $key = file_get_contents($f->get_pathname());
                Assert::not_false($key);
                /** @var int */
                $hash_len = unpack('V', $key)[1];
                $hash = substr($key, 4, $hash_len);
                $key = substr($key, 4 + $hash_len);
                Assert::not_null($this->get_item($key, $hash));
                unlink($f->get_pathname());
                unlink(substr($f->get_pathname(), 0, -5));
            }
        }
        $consolidated = $this->serializer->serialize($this->cache);
        file_put_contents($this->dir . 'consolidated', $consolidated, LOCK_EX);
        flock($this->lock, LOCK_UN);
        flock($this->lock, LOCK_SH);
    }
    public function get_hash(string $key): ?string
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key][0];
        }
        if (!$this->persistent) {
            return null;
        }
        $path = $this->dir . hash('xxh128', $key);
        if (!file_exists($path)) {
            return null;
        }
        return Providers::safe_file_get_contents($path);
    }
    /** @return T */
    public function get_item(string $key, ?string $hash = ''): array|object|string|null
    {
        if (isset($this->cache[$key])) {
            $combined = $this->cache[$key];
            if ($hash === null || $combined[0] === $hash) {
                return $combined[1];
            }
        }
        if (!$this->persistent) {
            return null;
        }
        $path = $this->dir . hash('xxh128', $key);
        if (!file_exists("{$path}.hash") || !file_exists($path)) {
            return null;
        }
        $fp = fopen("{$path}.hash", 'r');
        if ($fp === false) {
            return null;
        }
        $max_wait_cycles = 5;
        $has_lock = false;
        while ($max_wait_cycles > 0) {
            if (flock($fp, LOCK_SH)) {
                $has_lock = true;
                break;
            }
            $max_wait_cycles--;
            usleep(50000);
        }
        if (!$has_lock) {
            fclose($fp);
            throw new RuntimeException("Could not acquire lock for {$path}.hash");
        }
        $file_hash = stream_get_contents($fp);
        if ($hash === null) {
            if ($file_hash === '') {
                fclose($fp);
                return null;
            }
            assert($file_hash !== false);
            $hash_len = unpack('V', $file_hash)[1];
            assert(is_int($hash_len));
            $hash = substr($file_hash, 4, $hash_len);
            if (substr_compare($file_hash, $key, 4 + $hash_len) !== 0) {
                throw new AssertionError("Hash collision on key {$key}");
            }
        } elseif (substr_compare($file_hash, $hash, 4, strlen($hash)) !== 0 || substr_compare($file_hash, $key, strlen($hash) + 4) !== 0 || strlen($file_hash) !== strlen($key) + strlen($hash) + 4) {
            fclose($fp);
            return null;
        }
        $content = file_get_contents($path);
        Assert::not_false($content);
        fclose($fp);
        /** @var T */
        $content = $this->serializer->unserialize($content);
        if ($this->array_cache) {
            $this->cache[$key] = [$hash, $content];
        }
        return $content;
    }
    /** @param T $item */
    public function save_item(string $key, array|object|string $item, ?string $hash = null): void
    {
        // Assume all threads will store the same contents.
        // If the assumption is wrong, it will get fixed on the next run.
        if ($hash === null) {
            $hash = '';
        } elseif (isset($this->cache[$key]) && $this->cache[$key][0] === $hash) {
            return;
        }
        if ($this->persistent) {
            $path = $this->dir . hash('xxh128', $key);
            $f = fopen("{$path}.hash", 'w');
            Assert::not_false($f);
            flock($f, LOCK_EX);
            ftruncate($f, 0);
            Assert::eq(fwrite($f, pack('V', strlen($hash))), 4);
            Assert::eq(fwrite($f, $hash), strlen($hash));
            Assert::eq(fwrite($f, $key), strlen($key));
            file_put_contents($path, $this->serializer->serialize($item));
            fflush($f);
            flock($f, LOCK_UN);
            fclose($f);
        }
        $this->cache[$key] = [$hash, $item];
    }
}