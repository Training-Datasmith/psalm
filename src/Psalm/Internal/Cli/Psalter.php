<?php

declare (strict_types=1);
namespace Psalm\Internal\Cli;

use AssertionError;
use Psalm\Config;
use Psalm\Exception\Unsupported_Issue_To_Fix_Exception;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Cli_Utils;
use Psalm\Internal\Composer;
use Psalm\Internal\Error_Handler;
use Psalm\Internal\Fork\Psalm_Restarter;
use Psalm\Internal\Include_Collector;
use Psalm\Internal\Preloader;
use Psalm\Internal\Provider\Class_Like_Storage_Cache_Provider;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Storage_Cache_Provider;
use Psalm\Internal\Provider\Project_Cache_Provider;
use Psalm\Internal\Provider\Providers;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Issue_Buffer;
use Psalm\Progress\Debug_Progress;
use Psalm\Progress\Default_Progress;
use Psalm\Progress\Void_Progress;
use Psalm\Report;
use Psalm\Report\Report_Options;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_shift;
use function array_slice;
use function assert;
use function chdir;
use function count;
use function explode;
use function file_exists;
use function file_get_contents;
use function filter_var;
use function fwrite;
use function gc_collect_cycles;
use function gc_disable;
use function getcwd;
use function getopt;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function microtime;
use function pathinfo;
use function preg_last_error_msg;
use function preg_replace;
use function preg_split;
use function realpath;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use const DIRECTORY_SEPARATOR;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const PATHINFO_EXTENSION;
use const PHP_EOL;
use const STDERR;
// phpcs:disable PSR1.Files.SideEffects
require_once __DIR__ . '/../ErrorHandler.php';
require_once __DIR__ . '/../CliUtils.php';
require_once __DIR__ . '/../Composer.php';
require_once __DIR__ . '/../IncludeCollector.php';
require_once __DIR__ . '/../../IssueBuffer.php';
/**
 * @internal
 */
final class Psalter
{
    private const SHORT_OPTIONS = ['f:', 'm', 'h', 'r:', 'c:'];
    private const LONG_OPTIONS = ['help', 'debug', 'debug-by-line', 'debug-emitted-issues', 'config:', 'file:', 'root:', 'plugin:', 'issues:', 'list-supported-issues', 'php-version:', 'dry-run', 'safe-types', 'find-unused-code', 'threads:', 'scan-threads:', 'codeowner:', 'allow-backwards-incompatible-changes:', 'add-newline-between-docblock-annotations:', 'no-cache', 'no-progress', 'memory-limit:'];
    /** @param array<int,string> $argv */
    public static function run(array $argv): void
    {
        Cli_Utils::check_runtime_requirements();
        gc_collect_cycles();
        gc_disable();
        Error_Handler::install($argv);
        $args = array_slice($argv, 1);
        // get options from command line
        $options = getopt(implode('', self::SHORT_OPTIONS), self::LONG_OPTIONS);
        if ($options === false) {
            fwrite(STDERR, 'Failed to parse cli options' . PHP_EOL);
            exit(1);
        }
        self::validate_cli_arguments($args);
        Cli_Utils::set_memory_limit($options);
        self::sync_short_options($options);
        if (isset($options['c']) && is_array($options['c'])) {
            fwrite(STDERR, 'Too many config files provided' . PHP_EOL);
            exit(1);
        }
        if (array_key_exists('h', $options)) {
            echo <<<HELP
            Usage:
                psalter [options] [file...]
            
            Options:
                -h, --help
                    Display this help message
            
                --debug, --debug-by-line, --debug-emitted-issues
                    Debug information
            
                -c, --config=psalm.xml
                    Path to a psalm.xml configuration file. Run psalm --init to create one.
            
                -m, --monochrome
                    Enable monochrome output
            
                --no-progress
                    Disable the progress indicator
            
                -r, --root
                    If running Psalm globally you'll need to specify a project root. Defaults to cwd
            
                --plugin=PATH
                    Executes a plugin, an alternative to using the Psalm config
            
                --dry-run
                    Shows a diff of all the changes, without making them
            
                --safe-types
                    Only update PHP types when the new type information comes from other PHP types,
                    as opposed to type information that just comes from docblocks
            
                --php-version=PHP_MAJOR_VERSION.PHP_MINOR_VERSION
            
                --issues=IssueType1,IssueType2
                    If any issues can be fixed automatically, Psalm will update the codebase. To fix as many issues as
                    possible, use --issues=all
            
                --list-supported-issues
                    Display the list of issues that psalter knows how to fix
            
                --find-unused-code
                    Include unused code as a candidate for removal
            
                --threads=INT
                    If greater than one, Psalm will run analysis on multiple threads, speeding things up.
            
                --codeowner=[codeowner]
                    You can specify a GitHub code ownership group, and only that owner's code will be updated.
            
                --allow-backwards-incompatible-changes=BOOL
                    Allow Psalm modify method signatures that could break code outside the project. Defaults to true.
            
                --add-newline-between-docblock-annotations=BOOL
                    Whether to add or not add a new line between docblock annotations. Defaults to true.
            
                --no-cache
                    Runs Psalm without using cache
            
            HELP;
            exit;
        }
        if (!isset($options['issues']) && !isset($options['list-supported-issues']) && (!isset($options['plugin']) || $options['plugin'] === false)) {
            fwrite(STDERR, 'Please specify the issues you want to fix with --issues=IssueOne,IssueTwo or --issues=all, ' . 'or provide a plugin that has its own manipulations with --plugin=path/to/plugin.php' . PHP_EOL);
            exit(1);
        }
        $current_dir = (string) getcwd();
        if (isset($options['r']) && is_string($options['r'])) {
            $root_path = realpath($options['r']);
            if ($root_path === false) {
                fwrite(STDERR, 'Could not locate root directory ' . $current_dir . DIRECTORY_SEPARATOR . $options['r'] . PHP_EOL);
                exit(1);
            }
            $current_dir = $root_path;
        }
        $vendor_dir = Cli_Utils::get_vendor_dir($current_dir);
        // capture environment before registering autoloader (it may destroy it)
        Issue_Buffer::capture_server($_SERVER);
        $include_collector = new Include_Collector();
        $first_autoloader = $include_collector->run_and_collect(
            // we ignore the FQN because of a hack in scoper.inc that needs full path
            // phpcs:ignore SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFullyQualifiedName
            static fn(): ?\Composer\Autoload\Class_Loader => Cli_Utils::require_autoloaders($current_dir, isset($options['r']), $vendor_dir)
        );
        $ini_handler = new Psalm_Restarter('PSALTER');
        $ini_handler->disable_extensions([
            'grpc',
            'uopz',
            'pcov',
            'blackfire',
            // Issues w/ parallel forking
            'uv',
        ]);
        // If Xdebug is enabled, restart without it
        $ini_handler->check();
        Preloader::preload();
        $paths_to_check = Cli_Utils::get_paths_to_check($options['f'] ?? null);
        $path_to_config = Cli_Utils::get_path_to_config($options);
        $config = Cli_Utils::initialize_config($path_to_config, $current_dir, Report::TYPE_CONSOLE, $first_autoloader);
        if (isset($options['no-cache'])) {
            $config->cache_directory = null;
        }
        $config->set_include_collector($include_collector);
        if ($config->resolve_from_config_file) {
            $current_dir = $config->base_dir;
            chdir($current_dir);
        }
        $in_ci = Cli_Utils::running_in_ci();
        $threads = Psalm::get_threads($options, $config, $in_ci, false);
        $scan_threads = Psalm::get_threads($options, $config, $in_ci, true);
        if ($config->cache_directory === null) {
            $providers = new Providers(new File_Provider());
        } else {
            $providers = new Providers(new File_Provider(), null, new File_Storage_Cache_Provider($config, Composer::get_lock_file($current_dir)), new Class_Like_Storage_Cache_Provider($config, Composer::get_lock_file($current_dir)), null, new Project_Cache_Provider());
        }
        if (array_key_exists('list-supported-issues', $options)) {
            echo implode(',', Project_Analyzer::get_supported_issues_to_fix()) . PHP_EOL;
            exit;
        }
        $debug = array_key_exists('debug', $options) || array_key_exists('debug-by-line', $options);
        if ($debug) {
            $progress = new Debug_Progress();
        } elseif (isset($options['no-progress'])) {
            $progress = new Void_Progress();
        } else {
            $progress = new Default_Progress();
        }
        $stdout_report_options = new Report_Options();
        $stdout_report_options->use_color = !array_key_exists('m', $options);
        $project_analyzer = new Project_Analyzer($config, $providers, $stdout_report_options, [], $threads, $scan_threads, $progress);
        if (array_key_exists('debug-by-line', $options)) {
            $project_analyzer->debug_lines = true;
        }
        if (array_key_exists('debug-emitted-issues', $options)) {
            $config->debug_emitted_issues = true;
        }
        if (array_key_exists('issues', $options)) {
            if (!is_string($options['issues']) || !$options['issues']) {
                fwrite(STDERR, 'Expecting a comma-separated list of issues' . PHP_EOL);
                exit(1);
            }
            $issues = explode(',', $options['issues']);
            $keyed_issues = [];
            foreach ($issues as $issue) {
                $keyed_issues[$issue] = true;
            }
        } else {
            $keyed_issues = [];
        }
        Cli_Utils::init_php_version($options, $config, $project_analyzer);
        if (isset($options['codeowner'])) {
            $codeowner_files = self::load_codeowners($providers);
            $desired_codeowners = is_array($options['codeowner']) ? $options['codeowner'] : [$options['codeowner']];
            $files_for_codeowners = self::load_codeowners_files($desired_codeowners, $codeowner_files);
            $paths_to_check = is_array($paths_to_check) ? [...$paths_to_check, ...$files_for_codeowners] : $files_for_codeowners;
        }
        if (isset($options['allow-backwards-incompatible-changes'])) {
            $allow_backwards_incompatible_changes = filter_var($options['allow-backwards-incompatible-changes'], FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
            if ($allow_backwards_incompatible_changes === null) {
                fwrite(STDERR, '--allow-backwards-incompatible-changes expects a boolean value [true|false|1|0]' . PHP_EOL);
                exit(1);
            }
            $project_analyzer->get_codebase()->allow_backwards_incompatible_changes = $allow_backwards_incompatible_changes;
        }
        if (isset($options['add-newline-between-docblock-annotations'])) {
            $doc_block_add_new_line_before_return = filter_var($options['add-newline-between-docblock-annotations'], FILTER_VALIDATE_BOOLEAN, ['flags' => FILTER_NULL_ON_FAILURE]);
            if ($doc_block_add_new_line_before_return === null) {
                fwrite(STDERR, '--add-newline-between-docblock-annotations expects a boolean value [true|false|1|0]' . PHP_EOL);
                exit(1);
            }
            Parsed_Docblock::add_new_line_between_annotations($doc_block_add_new_line_before_return);
        }
        $plugins = [];
        if (isset($options['plugin'])) {
            $plugins = $options['plugin'];
            if (!is_array($plugins)) {
                $plugins = [$plugins];
            }
        }
        /** @var string $plugin_path */
        foreach ($plugins as $plugin_path) {
            Config::get_instance()->add_plugin_path($current_dir . $plugin_path);
        }
        $find_unused_code = array_key_exists('find-unused-code', $options);
        foreach ($keyed_issues as $issue_name => $_) {
            // MissingParamType requires the scanning of all files to inform possible params
            if (str_contains($issue_name, 'Unused') || $issue_name === 'MissingParamType' || $issue_name === 'UnnecessaryVarAnnotation' || $issue_name === 'all') {
                $find_unused_code = true;
                break;
            }
        }
        if ($find_unused_code) {
            $project_analyzer->get_codebase()->report_unused_code();
        }
        $project_analyzer->alter_code_after_completion(array_key_exists('dry-run', $options), array_key_exists('safe-types', $options));
        if ($keyed_issues === ['all' => true]) {
            $project_analyzer->set_all_issues_to_fix();
        } else {
            try {
                $project_analyzer->set_issues_to_fix($keyed_issues);
            } catch (Unsupported_Issue_To_Fix_Exception $e) {
                fwrite(STDERR, $e->get_message() . PHP_EOL);
                exit(1);
            }
        }
        $start_time = microtime(true);
        if ($paths_to_check === null || count($paths_to_check) > 1 || $find_unused_code) {
            if ($paths_to_check) {
                $files_to_update = [];
                foreach ($paths_to_check as $path_to_check) {
                    if (!is_dir($path_to_check)) {
                        $files_to_update[] = (string) realpath($path_to_check);
                    } else {
                        foreach ($providers->file_provider->get_files_in_dir($path_to_check, ['php']) as $php_file_path) {
                            $files_to_update[] = $php_file_path;
                        }
                    }
                }
                $project_analyzer->get_codebase()->analyzer->set_files_to_update($files_to_update);
            }
            $project_analyzer->check($current_dir);
        } elseif ($paths_to_check) {
            foreach ($paths_to_check as $path_to_check) {
                if (is_dir($path_to_check)) {
                    $project_analyzer->check_dir($path_to_check);
                } else {
                    $project_analyzer->check_file($path_to_check);
                }
            }
        }
        Issue_Buffer::finish($project_analyzer, false, $start_time);
    }
    /** @param array<int,string> $args */
    private static function validate_cli_arguments(array $args): void
    {
        array_map(static function (string $arg): void {
            if (str_starts_with($arg, '--') && $arg !== '--') {
                $arg_name = (string) preg_replace('/=.*$/', '', substr($arg, 2), 1);
                if ($arg_name === 'alter') {
                    // valid option for psalm, ignored by psalter
                    return;
                }
                if (!in_array($arg_name, self::LONG_OPTIONS) && !in_array($arg_name . ':', self::LONG_OPTIONS) && !in_array($arg_name . '::', self::LONG_OPTIONS)) {
                    fwrite(STDERR, 'Unrecognised argument "--' . $arg_name . '"' . PHP_EOL . 'Type --help to see a list of supported arguments' . PHP_EOL);
                    exit(1);
                }
            }
        }, $args);
    }
    /**
     * @param array<string, false|list<mixed>|string> $options
     * @param-out array<string, false|list<mixed>|string> $options
     */
    private static function sync_short_options(array &$options): void
    {
        if (array_key_exists('help', $options)) {
            $options['h'] = false;
        }
        if (array_key_exists('monochrome', $options)) {
            $options['m'] = false;
        }
        if (isset($options['config'])) {
            $options['c'] = $options['config'];
        }
        if (isset($options['root'])) {
            $options['r'] = $options['root'];
        }
    }
    /** @return array<string, array<int, string>> */
    private static function load_codeowners(Providers $providers): array
    {
        if (file_exists('CODEOWNERS')) {
            $codeowners_file_path = (string) realpath('CODEOWNERS');
        } elseif (file_exists('.github/CODEOWNERS')) {
            $codeowners_file_path = (string) realpath('.github/CODEOWNERS');
        } elseif (file_exists('docs/CODEOWNERS')) {
            $codeowners_file_path = (string) realpath('docs/CODEOWNERS');
        } else {
            fwrite(STDERR, 'Cannot use --codeowner without a CODEOWNERS file' . PHP_EOL);
            exit(1);
        }
        $codeowners_file = file_get_contents($codeowners_file_path);
        assert($codeowners_file != false);
        $codeowner_lines = array_map(static function (string $line): array {
            $line_parts = preg_split('/\s+/', $line);
            if ($line_parts === false) {
                throw new AssertionError("An error occurred: " . preg_last_error_msg());
            }
            $file_selector = substr(array_shift($line_parts), 1);
            return [$file_selector, $line_parts];
        }, array_filter(explode("\n", $codeowners_file), static function (string $line): bool {
            $line = trim($line);
            // currently we don’t match wildcard files or files that could appear anywhere
            // in the repo
            return $line && $line[0] === '/' && !str_contains($line, '*');
        }));
        $codeowner_files = [];
        foreach ($codeowner_lines as [$path, $owners]) {
            if (!file_exists($path)) {
                continue;
            }
            foreach ($owners as $i => $owner) {
                $owners[$i] = strtolower($owner);
            }
            if (!is_dir($path)) {
                if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
                    $codeowner_files[$path] = $owners;
                }
            } else {
                foreach ($providers->file_provider->get_files_in_dir($path, ['php']) as $php_file_path) {
                    $codeowner_files[$php_file_path] = $owners;
                }
            }
        }
        if (!$codeowner_files) {
            fwrite(STDERR, 'Could not find any available entries in CODEOWNERS' . PHP_EOL);
            exit(1);
        }
        return $codeowner_files;
    }
    /**
     * @param array<string, array<int, string>> $codeowner_files
     * @return list<string>
     */
    private static function load_codeowners_files(array $desired_codeowners, array $codeowner_files): array
    {
        $paths_to_check = [];
        /** @psalm-suppress MixedAssignment */
        foreach ($desired_codeowners as $desired_codeowner) {
            if (!is_string($desired_codeowner)) {
                fwrite(STDERR, 'Invalid --codeowner ' . $desired_codeowner . PHP_EOL);
                exit(1);
            }
            if ($desired_codeowner[0] !== '@') {
                fwrite(STDERR, '--codeowner option must start with @' . PHP_EOL);
                exit(1);
            }
            $matched_file = false;
            foreach ($codeowner_files as $file_path => $owners) {
                if (in_array(strtolower($desired_codeowner), $owners)) {
                    $paths_to_check[] = $file_path;
                    $matched_file = true;
                }
            }
            if (!$matched_file) {
                fwrite(STDERR, 'User/group ' . $desired_codeowner . ' does not own any PHP files' . PHP_EOL);
                exit(1);
            }
        }
        return $paths_to_check;
    }
}