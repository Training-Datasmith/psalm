<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use RuntimeException;
use function fclose;
use function flock;
use function fopen;
use function stream_get_contents;
use function usleep;
use const LOCK_SH;
/**
 * @internal
 */
final class Providers
{
    public File_Storage_Provider $file_storage_provider;
    public Class_Like_Storage_Provider $classlike_storage_provider;
    public Statements_Provider $statements_provider;
    public File_Reference_Provider $file_reference_provider;
    public function __construct(public File_Provider $file_provider, public ?Parser_Cache_Provider $parser_cache_provider = null, ?File_Storage_Cache_Provider $file_storage_cache_provider = null, ?Class_Like_Storage_Cache_Provider $classlike_storage_cache_provider = null, ?File_Reference_Cache_Provider $file_reference_cache_provider = null, public ?Project_Cache_Provider $project_cache_provider = null)
    {
        $this->file_storage_provider = new File_Storage_Provider($file_storage_cache_provider);
        $this->classlike_storage_provider = new Class_Like_Storage_Provider($classlike_storage_cache_provider);
        $this->statements_provider = new Statements_Provider($file_provider, $parser_cache_provider);
        $this->file_reference_provider = new File_Reference_Provider($file_provider, $file_reference_cache_provider);
    }
    public static function safe_file_get_contents(string $path): string
    {
        // no readable validation as that must be done in the caller
        $fp = fopen($path, 'r');
        if ($fp === false) {
            return '';
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
            throw new RuntimeException('Could not acquire lock for ' . $path);
        }
        $content = stream_get_contents($fp);
        fclose($fp);
        return $content;
    }
}