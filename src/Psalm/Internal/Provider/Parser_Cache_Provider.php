<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Php_Parser;
use Psalm\Config;
use Psalm\Internal\Cache;
use function filemtime;
use const DIRECTORY_SEPARATOR;
/** @internal */
final class Parser_Cache_Provider
{
    private const PARSER_CACHE_DIRECTORY = 'php-parser';
    /** @var Cache<list<PhpParser\Node\Stmt>> */
    private readonly Cache $stmt_cache;
    public function __construct(Config $config, string $composer_lock, bool $persistent = true)
    {
        $deps = [$composer_lock, PHP_PARSER_VERSION, (string) filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'StatementsProvider.php')];
        $this->stmt_cache = new Cache($config, self::PARSER_CACHE_DIRECTORY, $deps, $persistent);
    }
    public function consolidate(): void
    {
        $this->stmt_cache->consolidate();
    }
    /**
     * @return list<PhpParser\Node\Stmt>|null
     */
    public function load_statements_from_cache(string $file_path, ?string $file_content_hash): ?array
    {
        return $this->stmt_cache->get_item($file_path, $file_content_hash);
    }
    public function get_hash(string $file_path): ?string
    {
        return $this->stmt_cache->get_hash($file_path);
    }
    /**
     * @param  list<PhpParser\Node\Stmt>        $stmts
     */
    public function save_statements_to_cache(string $file_path, string $file_content_hash, array $stmts): void
    {
        $this->stmt_cache->save_item($file_path, $stmts, $file_content_hash);
    }
}