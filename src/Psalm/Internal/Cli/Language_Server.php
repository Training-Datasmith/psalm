<?php

declare (strict_types=1);
namespace Psalm\Internal\Cli;

use Language_Server_Protocol\Message_Type;
use Psalm\Config;
use Psalm\Internal\Cli_Utils;
use Psalm\Internal\Error_Handler;
use Psalm\Internal\Fork\Psalm_Restarter;
use Psalm\Internal\Include_Collector;
use Psalm\Internal\Language_Server\Client_Configuration;
use Psalm\Internal\Language_Server\Language_Server as LanguageServerLanguageServer;
use Psalm\Internal\Language_Server\Path_Mapper;
use Psalm\Report;
use function array_key_exists;
use function array_map;
use function array_search;
use function array_slice;
use function chdir;
use function error_log;
use function explode;
use function fwrite;
use function gc_disable;
use function getcwd;
use function getopt;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_replace;
use function realpath;
use function setlocale;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use const DIRECTORY_SEPARATOR;
use const LC_CTYPE;
use const PHP_EOL;
use const STDERR;
// phpcs:disable PSR1.Files.SideEffects
require_once __DIR__ . '/../ErrorHandler.php';
require_once __DIR__ . '/../CliUtils.php';
require_once __DIR__ . '/../Composer.php';
require_once __DIR__ . '/../IncludeCollector.php';
require_once __DIR__ . '/../LanguageServer/ClientConfiguration.php';
/**
 * @internal
 */
final class Language_Server
{
    /**
     * @param array<int,string> $argv
     * @psalm-suppress ComplexMethod
     */
    public static function run(array $argv): void
    {
        Cli_Utils::check_runtime_requirements();
        $client_configuration = new Client_Configuration();
        gc_disable();
        Error_Handler::install($argv);
        $valid_short_options = ['h', 'v', 'c:', 'r:'];
        $valid_long_options = ['config:', 'find-dead-code', 'help', 'root:', 'map-folder::', 'use-ini-defaults', 'version', 'tcp:', 'tcp-server', 'disable-on-change::', 'use-baseline:', 'enable-autocomplete::', 'enable-code-actions::', 'enable-provide-diagnostics::', 'enable-provide-hover::', 'enable-provide-signature-help::', 'enable-provide-definition::', 'show-diagnostic-warnings::', 'in-memory::', 'disable-xdebug::', 'on-change-debounce-ms::', 'on-open-debounce-ms::', 'use-extended-diagnostic-codes', 'verbose'];
        $args = array_slice($argv, 1);
        $psalm_proxy = array_search('--language-server', $args, true);
        if ($psalm_proxy !== false) {
            unset($args[$psalm_proxy]);
        }
        array_map(static function (string $arg) use ($valid_long_options): void {
            if (str_starts_with($arg, '--') && $arg !== '--') {
                $arg_name = (string) preg_replace('/=.*$/', '', substr($arg, 2), 1);
                if (!in_array($arg_name, $valid_long_options, true) && !in_array($arg_name . ':', $valid_long_options, true) && !in_array($arg_name . '::', $valid_long_options, true)) {
                    fwrite(STDERR, 'Unrecognised argument "--' . $arg_name . '"' . PHP_EOL . 'Type --help to see a list of supported arguments' . PHP_EOL);
                    error_log('Bad argument');
                    exit(1);
                }
            }
        }, $args);
        // get options from command line
        $options = getopt(implode('', $valid_short_options), $valid_long_options);
        if ($options === false) {
            // shouldn't really happen, but just in case
            fwrite(STDERR, 'Failed to get CLI args' . PHP_EOL);
            exit(1);
        }
        Cli_Utils::set_memory_limit($options, '1');
        if (array_key_exists('help', $options)) {
            $options['h'] = false;
        }
        if (array_key_exists('version', $options)) {
            $options['v'] = false;
        }
        if (isset($options['config'])) {
            $options['c'] = $options['config'];
        }
        if (isset($options['c']) && is_array($options['c'])) {
            fwrite(STDERR, 'Too many config files provided' . PHP_EOL);
            exit(1);
        }
        if (array_key_exists('h', $options)) {
            echo <<<HELP
            Usage:
                psalm-language-server [options]
            
            Options:
                -h, --help
                    Display this help message
            
                -v, --version
                    Display the Psalm version
            
                -c, --config=psalm.xml
                    Path to a psalm.xml configuration file. Run psalm --init to create one.
            
                -r, --root
                    If running Psalm globally you'll need to specify a project root. Defaults to cwd
            
                --map-folder[=SERVER_FOLDER:CLIENT_FOLDER]
                    Specify folder to map between the client and the server. Use this when the client
                    and server have different views of the filesystem (e.g. in a docker container).
                    Defaults to mapping the rootUri provided by the client to the server's cwd,
                    or `-r` if provided.
            
                    No mapping is done when this option is not specified.
            
                --find-dead-code
                    Look for dead code
            
                --use-ini-defaults
                    Use PHP-provided ini defaults for memory and error display
            
                --use-baseline=PATH
                    Allows you to use a baseline other than the default baseline provided in your config
            
                --tcp=url
                    Use TCP mode (by default Psalm uses STDIO)
            
                --tcp-server
                    Use TCP in server mode (default is client)
            
                --disable-on-change[=line-number-threshold]
                    If added, the language server will not respond to onChange events.
                    You can also specify a line count over which Psalm will not run on-change events.
            
                --enable-code-actions[=BOOL]
                    Enables or disables code actions. Default is true.
            
                --enable-provide-diagnostics[=BOOL]
                    Enables or disables providing diagnostics. Default is true.
            
                --enable-autocomplete[=BOOL]
                    Enables or disables autocomplete on methods and properties. Default is true.
            
                --enable-provide-hover[=BOOL]
                    Enables or disables providing hover. Default is true.
            
                --enable-provide-signature-help[=BOOL]
                    Enables or disables providing signature help. Default is true.
            
                --enable-provide-definition[=BOOL]
                    Enables or disables providing definition. Default is true.
            
                --show-diagnostic-warnings[=BOOL]
                    Enables or disables showing diagnostic warnings. Default is true.
            
                --use-extended-diagnostic-codes (DEPRECATED)
                    Enables sending help uri links with the code in diagnostic messages.
            
                --on-change-debounce-ms=[INT]
                    The number of milliseconds to debounce onChange events.
            
                --on-open-debounce-ms=[INT]
                    The number of milliseconds to debounce onOpen events.
            
                --disable-xdebug[=BOOL]
                    Disable xdebug for performance reasons. Enable for debugging
            
                --in-memory[=BOOL]
                    Use in-memory mode. Default is false. Experimental.
            
                --verbose
                    Will send log messages to the client with information.
            
            HELP;
            exit;
        }
        if (getcwd() === false) {
            fwrite(STDERR, 'Cannot get current working directory' . PHP_EOL);
            exit(1);
        }
        if (isset($options['root'])) {
            $options['r'] = $options['root'];
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
        $include_collector = new Include_Collector();
        $first_autoloader = $include_collector->run_and_collect(
            // we ignore the FQN because of a hack in scoper.inc that needs full path
            // phpcs:ignore SlevomatCodingStandard.Namespaces.ReferenceUsedNamesOnly.ReferenceViaFullyQualifiedName
            static fn(): ?\Composer\Autoload\Class_Loader => Cli_Utils::require_autoloaders($current_dir, isset($options['r']), $vendor_dir)
        );
        if (array_key_exists('v', $options)) {
            echo 'Psalm ' . PSALM_VERSION . PHP_EOL;
            exit;
        }
        $ini_handler = new Psalm_Restarter('PSALM');
        $ini_handler->disable_extensions([
            'grpc',
            'uopz',
            // extensions bellow are incompatible with JIT
            'pcov',
            'blackfire',
            // Issues w/ parallel forking
            'uv',
        ]);
        $disable_xdebug = !isset($options['disable-xdebug']) || !is_string($options['disable-xdebug']) || strtolower($options['disable-xdebug']) !== 'false';
        // If Xdebug is enabled, restart without it based on cli
        if ($disable_xdebug) {
            $ini_handler->check();
        }
        setlocale(LC_CTYPE, 'C');
        $path_mapper = self::create_path_mapper($options, $current_dir);
        $path_to_config = Cli_Utils::get_path_to_config($options);
        if (isset($options['tcp']) && !is_string($options['tcp'])) {
            fwrite(STDERR, 'tcp url should be a string' . PHP_EOL);
            exit(1);
        }
        $config = Cli_Utils::initialize_config($path_to_config, $current_dir, Report::TYPE_CONSOLE, $first_autoloader);
        $config->set_include_collector($include_collector);
        if ($config->resolve_from_config_file) {
            $current_dir = $config->base_dir;
            chdir($current_dir);
        }
        $config->set_server_mode();
        $in_memory = isset($options['in-memory']) && is_string($options['in-memory']) && strtolower($options['in-memory']) === 'true';
        if ($in_memory) {
            $config->cache_directory = null;
        } else {
            $cache_directory = $config->get_cache_directory();
            if ($cache_directory !== null) {
                Config::remove_cache_directory($cache_directory);
            }
        }
        if (isset($options['use-baseline']) && is_string($options['use-baseline'])) {
            $client_configuration->baseline = $options['use-baseline'];
        }
        if (isset($options['disable-on-change']) && is_numeric($options['disable-on-change'])) {
            $client_configuration->onchange_line_limit = (int) $options['disable-on-change'];
        }
        if (isset($options['on-change-debounce-ms']) && is_numeric($options['on-change-debounce-ms'])) {
            $client_configuration->on_change_debounce_ms = (int) $options['on-change-debounce-ms'];
        }
        if (isset($options['on-open-debounce-ms']) && is_numeric($options['on-open-debounce-ms'])) {
            $client_configuration->on_open_debounce_ms = (int) $options['on-open-debounce-ms'];
        }
        $client_configuration->provide_definition = !isset($options['enable-provide-definition']) || !is_string($options['enable-provide-definition']) || strtolower($options['enable-provide-definition']) !== 'false';
        $client_configuration->provide_signature_help = !isset($options['enable-provide-signature-help']) || !is_string($options['enable-provide-signature-help']) || strtolower($options['enable-provide-signature-help']) !== 'false';
        $client_configuration->provide_hover = !isset($options['enable-provide-hover']) || !is_string($options['enable-provide-hover']) || strtolower($options['enable-provide-hover']) !== 'false';
        $client_configuration->provide_diagnostics = !isset($options['enable-provide-diagnostics']) || !is_string($options['enable-provide-diagnostics']) || strtolower($options['enable-provide-diagnostics']) !== 'false';
        $client_configuration->provide_code_actions = !isset($options['enable-code-actions']) || !is_string($options['enable-code-actions']) || strtolower($options['enable-code-actions']) !== 'false';
        $client_configuration->provide_completion = !isset($options['enable-autocomplete']) || !is_string($options['enable-autocomplete']) || strtolower($options['enable-autocomplete']) !== 'false';
        $client_configuration->hide_warnings = !(!isset($options['show-diagnostic-warnings']) || !is_string($options['show-diagnostic-warnings']) || strtolower($options['show-diagnostic-warnings']) !== 'false');
        /**
         *         if ($config->find_unused_variables) {
         *   $project_analyzer->getCodebase()->reportUnusedVariables();
         * }
         */
        $find_unused_code = isset($options['find-dead-code']) ? 'auto' : null;
        if ($config->find_unused_code) {
            $find_unused_code = 'auto';
        }
        if ($find_unused_code) {
            $client_configuration->find_unused_code = $find_unused_code;
        }
        if (isset($options['verbose'])) {
            $client_configuration->log_level = $options['verbose'] ? Message_Type::LOG : Message_Type::INFO;
        } else {
            $client_configuration->log_level = Message_Type::INFO;
        }
        $client_configuration->tcp_server_address = $options['tcp'] ?? null;
        $client_configuration->tcp_server_mode = isset($options['tcp-server']);
        Language_Server_Language_Server::run($config, $client_configuration, $current_dir, $path_mapper, $in_memory);
    }
    /** @param array<string,string|false|list<string|false>> $options */
    private static function create_path_mapper(array $options, string $server_start_dir): Path_Mapper
    {
        if (!isset($options['map-folder'])) {
            // dummy no-op mapper
            return new Path_Mapper('/', '/');
        }
        $map_folder = $options['map-folder'];
        if ($map_folder === false) {
            // autoconfigured mapper
            return new Path_Mapper($server_start_dir);
        }
        if (is_string($map_folder)) {
            if (!str_contains($map_folder, ':')) {
                fwrite(STDERR, 'invalid format for --map-folder option' . PHP_EOL);
                exit(1);
            }
            /** @psalm-suppress PossiblyUndefinedArrayOffset we just checked that we have the separator*/
            [$server_dir, $client_dir] = explode(':', $map_folder, 2);
            if (!strlen($server_dir) || !strlen($client_dir)) {
                fwrite(STDERR, 'invalid format for --map-folder option, ' . 'neither SERVER_FOLDER nor CLIENT_FOLDER can be empty' . PHP_EOL);
                exit(1);
            }
            return new Path_Mapper($server_dir, $client_dir);
        }
        fwrite(STDERR, '--map-folder option can only be specified once' . PHP_EOL);
        exit(1);
    }
}