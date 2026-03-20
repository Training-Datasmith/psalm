<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider;

use Php_Parser;
use Php_Parser\Error_Handler\Collecting;
use Php_Parser\Node\Stmt;
use Php_Parser\Parser;
use Php_Parser\Php_Version;
use Psalm\Code_Location\Parse_Error_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Diff\File_Differ;
use Psalm\Internal\Diff\File_Statements_Differ;
use Psalm\Internal\Php_Traverser\Custom_Traverser;
use Psalm\Internal\Php_Visitor\Cloning_Visitor;
use Psalm\Internal\Php_Visitor\Partial_Parser_Visitor;
use Psalm\Internal\Php_Visitor\Simple_Name_Resolver;
use Psalm\Issue\ParseError;
use Psalm\Issue_Buffer;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use Throwable;
use function abs;
use function array_fill_keys;
use function array_intersect_key;
use function array_map;
use function array_merge;
use function count;
use function md5;
use function str_starts_with;
use function strlen;
use function strpos;
/**
 * @internal
 */
final class Statements_Provider
{
    /**
     * @var array<string, array<string, bool>>
     */
    private array $unchanged_members = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private array $unchanged_signature_members = [];
    /**
     * @var array<string, array<string, bool>>
     */
    private array $changed_members = [];
    /**
     * @var array<string, bool>
     */
    private array $errors = [];
    /**
     * @var array<string, array<int, array{int, int, int, int}>>
     */
    private array $diff_map = [];
    /**
     * @var array<string, array<int, array{int, int}>>
     */
    private array $deletion_ranges = [];
    private static ?Parser $parser = null;
    public function __construct(private readonly File_Provider $file_provider, public ?Parser_Cache_Provider $parser_cache_provider = null)
    {
    }
    /**
     * @return list<Stmt>
     */
    public function get_statements_for_file(string $file_path, int $analysis_php_version_id, bool $do_diff, ?Progress $progress = null): array
    {
        unset($this->errors[$file_path]);
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $file_contents = $this->file_provider->get_contents($file_path);
        $config = Config::get_instance();
        if (!$this->parser_cache_provider || !$config->is_in_project_dirs($file_path) && strpos($file_path, 'vendor')) {
            $progress->debug('Parsing ' . $file_path . " because we cannot use cache\n");
            $has_errors = false;
            return self::parse_statements($file_contents, $analysis_php_version_id, $has_errors, $file_path);
        }
        $stmts = $this->parser_cache_provider->load_statements_from_cache($file_path, $file_contents);
        if ($stmts !== null) {
            $this->diff_map[$file_path] = [];
            $this->deletion_ranges[$file_path] = [];
            return $stmts;
        }
        $progress->debug("Parsing {$file_path} because the cache is absent or outdated\n");
        $existing_statements = null;
        $existing_file_contents = null;
        $existing_statements_copy = null;
        $file_changes = null;
        if ($do_diff) {
            $existing_file_contents = $this->parser_cache_provider->get_hash($file_path);
            $existing_statements = $this->parser_cache_provider->load_statements_from_cache($file_path, $existing_file_contents);
            // Race condition, another thread wrote the same data we already have
            if ($existing_file_contents === $file_contents && $existing_statements !== null) {
                $this->diff_map[$file_path] = [];
                $this->deletion_ranges[$file_path] = [];
                return $existing_statements;
            }
            $file_changes = null;
            $existing_statements_copy = null;
            if ($existing_statements !== null && $existing_file_contents !== null && abs(strlen($existing_file_contents) - strlen($file_contents)) < 5000) {
                $file_changes = File_Differ::get_diff($existing_file_contents, $file_contents);
                if (count($file_changes) < 10) {
                    $traverser = new Php_Parser\Node_Traverser();
                    $traverser->add_visitor(new Cloning_Visitor());
                    // performs a deep clone
                    /** @var list<PhpParser\Node\Stmt> */
                    $existing_statements_copy = $traverser->traverse($existing_statements);
                } else {
                    $file_changes = null;
                }
            }
        }
        $has_errors = false;
        $stmts = self::parse_statements($file_contents, $analysis_php_version_id, $has_errors, $file_path, $existing_file_contents, $existing_statements_copy, $file_changes);
        if ($existing_file_contents !== null && $existing_statements !== null && (!$has_errors || $stmts)) {
            [$unchanged_members, $unchanged_signature_members, $changed_members, $diff_map, $deletion_ranges] = File_Statements_Differ::diff($existing_statements, $stmts, $existing_file_contents, $file_contents);
            $unchanged_members = array_fill_keys($unchanged_members, true);
            $unchanged_signature_members = array_fill_keys($unchanged_signature_members, true);
            // do NOT change this to hash, it will fail on Windows for whatever reason
            $file_path_hash = md5($file_path);
            $changed_members = array_map(static function (string $key) use ($file_path_hash): string {
                if (str_starts_with($key, 'use:')) {
                    return $key . ':' . $file_path_hash;
                }
                return $key;
            }, $changed_members);
            $changed_members = array_fill_keys($changed_members, true);
            if (isset($this->unchanged_members[$file_path])) {
                $this->unchanged_members[$file_path] = array_intersect_key($this->unchanged_members[$file_path], $unchanged_members);
            } else {
                $this->unchanged_members[$file_path] = $unchanged_members;
            }
            if (isset($this->unchanged_signature_members[$file_path])) {
                $this->unchanged_signature_members[$file_path] = array_intersect_key($this->unchanged_signature_members[$file_path], $unchanged_signature_members);
            } else {
                $this->unchanged_signature_members[$file_path] = $unchanged_signature_members;
            }
            if (isset($this->changed_members[$file_path])) {
                $this->changed_members[$file_path] = array_merge($this->changed_members[$file_path], $changed_members);
            } else {
                $this->changed_members[$file_path] = $changed_members;
            }
            $this->diff_map[$file_path] = $diff_map;
            $this->deletion_ranges[$file_path] = $deletion_ranges;
        } elseif ($has_errors && !$stmts) {
            $this->errors[$file_path] = true;
        }
        $this->parser_cache_provider->save_statements_to_cache($file_path, $file_contents, $stmts);
        return $stmts;
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_changed_members(): array
    {
        return $this->changed_members;
    }
    /**
     * @param array<string, array<string, bool>> $more_changed_members
     */
    public function add_changed_members(array $more_changed_members): void
    {
        $this->changed_members = [...$more_changed_members, ...$this->changed_members];
    }
    /**
     * @return array<string, array<string, bool>>
     */
    public function get_unchanged_signature_members(): array
    {
        return $this->unchanged_signature_members;
    }
    /**
     * @param array<string, array<string, bool>> $more_unchanged_members
     */
    public function add_unchanged_signature_members(array $more_unchanged_members): void
    {
        $this->unchanged_signature_members = [...$more_unchanged_members, ...$this->unchanged_signature_members];
    }
    /**
     * @return array<string, bool>
     */
    public function get_errors(): array
    {
        return $this->errors;
    }
    /**
     * @param array<string, bool> $errors
     */
    public function add_errors(array $errors): void
    {
        $this->errors += $errors;
    }
    public function set_unchanged_file(string $file_path): void
    {
        if (!isset($this->diff_map[$file_path])) {
            $this->diff_map[$file_path] = [];
        }
        if (!isset($this->deletion_ranges[$file_path])) {
            $this->deletion_ranges[$file_path] = [];
        }
    }
    /**
     * @return array<string, array<int, array{int, int, int, int}>>
     */
    public function get_diff_map(): array
    {
        return $this->diff_map;
    }
    /**
     * @return array<string, array<int, array{int, int}>>
     */
    public function get_deletion_ranges(): array
    {
        return $this->deletion_ranges;
    }
    /**
     * @param array<string, array<int, array{int, int, int, int}>> $diff_map
     */
    public function add_diff_map(array $diff_map): void
    {
        $this->diff_map = [...$diff_map, ...$this->diff_map];
    }
    /**
     * @param array<string, array<int, array{int, int}>> $deletion_ranges
     */
    public function add_deletion_ranges(array $deletion_ranges): void
    {
        $this->deletion_ranges = [...$deletion_ranges, ...$this->deletion_ranges];
    }
    public function reset_diffs(): void
    {
        $this->changed_members = [];
        $this->unchanged_members = [];
        $this->unchanged_signature_members = [];
        $this->diff_map = [];
        $this->deletion_ranges = [];
    }
    /**
     * @param  list<Stmt> $existing_statements
     * @param  array<int, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: string}> $file_changes
     * @return list<Stmt>
     */
    public static function parse_statements(string $file_contents, int $analysis_php_version_id, bool &$has_errors, ?string $file_path = null, ?string $existing_file_contents = null, ?array $existing_statements = null, ?array $file_changes = null): array
    {
        if (!self::$parser) {
            $major_version = Codebase::transform_php_version_id($analysis_php_version_id, 10000);
            $minor_version = Codebase::transform_php_version_id($analysis_php_version_id % 10000, 100);
            $php_version = Php_Version::from_components($major_version, $minor_version);
            self::$parser = (new Php_Parser\Parser_Factory())->create_for_version($php_version);
        }
        $used_cached_statements = false;
        $error_handler = new Collecting();
        if ($existing_statements && $file_changes && $existing_file_contents) {
            $clashing_traverser = new Custom_Traverser();
            $offset_analyzer = new Partial_Parser_Visitor(self::$parser, $error_handler, $file_changes, $existing_file_contents, $file_contents);
            $clashing_traverser->add_visitor($offset_analyzer);
            $clashing_traverser->traverse($existing_statements);
            if (!$offset_analyzer->must_rescan()) {
                $used_cached_statements = true;
                $stmts = $existing_statements;
            } else {
                try {
                    /** @var list<Stmt> */
                    $stmts = self::$parser->parse($file_contents, $error_handler) ?: [];
                } catch (Throwable) {
                    $stmts = [];
                    // hope this got caught below
                }
            }
        } else {
            try {
                /** @var list<Stmt> */
                $stmts = self::$parser->parse($file_contents, $error_handler) ?: [];
            } catch (Throwable) {
                $stmts = [];
                // hope this got caught below
            }
        }
        if ($error_handler->has_errors() && $file_path) {
            $config = Config::get_instance();
            $has_errors = true;
            foreach ($error_handler->get_errors() as $error) {
                if ($error->has_column_info()) {
                    Issue_Buffer::maybe_add(new ParseError($error->get_message(), new Parse_Error_Location($error, $file_contents, $file_path, $config->shorten_file_name($file_path))));
                }
            }
        }
        $error_handler->clear_errors();
        $resolving_traverser = new Php_Parser\Node_Traverser();
        $name_resolver = new Simple_Name_Resolver($error_handler, $used_cached_statements ? $file_changes : []);
        $resolving_traverser->add_visitor($name_resolver);
        $resolving_traverser->traverse($stmts);
        return $stmts;
    }
    public static function clear_parser(): void
    {
        self::$parser = null;
    }
}