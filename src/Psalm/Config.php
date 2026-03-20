<?php

declare (strict_types=1);
namespace Psalm;

use Amp\Serialization\Native_Serializer;
use Amp\Serialization\Serializer;
use Composer\Autoload\Class_Loader;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Version_Parser;
use Dom_Attr;
use Dom_Document;
use Dom_Element;
use InvalidArgumentException;
use Json_Exception;
use LogicException;
use OutOfBoundsException;
use Psalm\Code_Location\Raw;
use Psalm\Config\Issue_Handler;
use Psalm\Config\Project_File_Filter;
use Psalm\Config\Taint_Analysis_File_Filter;
use Psalm\Exception\Config_Exception;
use Psalm\Exception\Config_Not_Found_Exception;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\File_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Cli_Utils;
use Psalm\Internal\Composer;
use Psalm\Internal\Event_Dispatcher;
use Psalm\Internal\Fork\Igbinary_Serializer;
use Psalm\Internal\Gzip_Serializer;
use Psalm\Internal\Include_Collector;
use Psalm\Internal\Lz4Serializer;
use Psalm\Internal\Provider\Add_Remove_Taints\Html_Function_Tainter;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Issue\Argument_Issue;
use Psalm\Issue\Class_Constant_Issue;
use Psalm\Issue\Class_Issue;
use Psalm\Issue\Code_Issue;
use Psalm\Issue\Config_Issue;
use Psalm\Issue\Function_Issue;
use Psalm\Issue\Method_Issue;
use Psalm\Issue\Property_Issue;
use Psalm\Issue\Variable_Issue;
use Psalm\Plugin\Plugin_Entry_Point_Interface;
use Psalm\Plugin\Plugin_File_Extensions_Interface;
use Psalm\Plugin\Plugin_Interface;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use RuntimeException;
use Simple_Xml_Element;
use Symfony\Component\Filesystem\Path;
use Throwable;
use UnexpectedValueException;
use Xdg_Base_Dir\Xdg;
use stdClass;
use function array_key_exists;
use function array_merge;
use function array_pad;
use function array_pop;
use function array_shift;
use function assert;
use function basename;
use function chdir;
use function class_exists;
use function clearstatcache;
use function count;
use function dirname;
use function explode;
use function fclose;
use function file_exists;
use function file_get_contents;
use function flock;
use function fopen;
use function function_exists;
use function get_defined_constants;
use function get_defined_functions;
use function getcwd;
use function glob;
use function implode;
use function in_array;
use function is_a;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use function max;
use function mkdir;
use function phpversion;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function realpath;
use function reset;
use function rmdir;
use function rtrim;
use function scandir;
use function sha1;
use function simplexml_import_dom;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;
use function usleep;
use function version_compare;
use const DIRECTORY_SEPARATOR;
use const GLOB_NOSORT;
use const JSON_THROW_ON_ERROR;
use const LIBXML_ERR_ERROR;
use const LIBXML_ERR_FATAL;
use const LIBXML_NONET;
use const LIBXML_NOWARNING;
use const LOCK_EX;
use const PHP_EOL;
use const PHP_VERSION_ID;
use const PSALM_VERSION;
use const SCANDIR_SORT_NONE;
/**
 * @psalm-suppress PropertyNotSetInConstructor
 * @psalm-consistent-constructor
 */
final class Config
{
    final public const DEFAULT_BASELINE_NAME = 'psalm-baseline.xml';
    private const DEFAULT_FILE_NAMES = ['psalm.xml', 'psalm.xml.dist', 'psalm.dist.xml'];
    final public const CONFIG_NAMESPACE = 'https://getpsalm.org/schema/config';
    final public const REPORT_INFO = 'info';
    final public const REPORT_ERROR = 'error';
    final public const REPORT_SUPPRESS = 'suppress';
    /**
     * @var array<string>
     */
    public static array $ERROR_LEVELS = [self::REPORT_INFO, self::REPORT_ERROR, self::REPORT_SUPPRESS];
    /**
     * @var array
     */
    private const MIXED_ISSUES = ['MixedArgument', 'MixedArrayAccess', 'MixedArrayAssignment', 'MixedArrayOffset', 'MixedArrayTypeCoercion', 'MixedAssignment', 'MixedFunctionCall', 'MixedMethodCall', 'MixedOperand', 'MixedPropertyFetch', 'MixedPropertyAssignment', 'MixedReturnStatement', 'MixedStringOffsetAssignment', 'MixedArgumentTypeCoercion', 'MixedPropertyTypeCoercion', 'MixedReturnTypeCoercion'];
    /**
     * These are special object classes that allow any and all properties to be get/set on them
     *
     * @var array<int, lowercase-string>
     */
    private array $universal_object_crates;
    private static self $instance;
    /**
     * Whether or not to use types as defined in docblocks
     */
    public bool $use_docblock_types = true;
    /**
     * Whether or not to use types as defined in property docblocks.
     * This is distinct from the above because you may want to use
     * property docblocks, but not function docblocks.
     */
    public bool $use_docblock_property_types = false;
    /**
     * Whether using property annotations in docblocks should implicitly seal properties
     */
    public bool $docblock_property_types_seal_properties = true;
    /**
     * Whether or not to throw an exception on first error
     */
    public bool $throw_exception = false;
    /**
     * The directory to store PHP Parser (and other) caches
     *
     * @internal
     */
    public ?string $cache_directory = null;
    public bool $array_cache = true;
    private bool $cache_directory_initialized = false;
    /**
     * The directory to store all Psalm project caches
     */
    public ?string $global_cache_directory = null;
    /**
     * Path to the autoader
     */
    public ?string $autoloader = null;
    protected ?Project_File_Filter $project_files = null;
    private ?Project_File_Filter $extra_files = null;
    /**
     * The base directory of this config file without trailing slash
     */
    public string $base_dir;
    public ?string $source_filename = null;
    /**
     * The PHP version to assume as declared in the config file
     */
    private ?string $configured_php_version = null;
    /**
     * @var array<int, string>
     */
    private array $file_extensions = ['php'];
    /**
     * @var array<string, class-string<FileScanner>>
     */
    private array $filetype_scanners = [];
    /**
     * @var array<string, class-string<FileAnalyzer>>
     */
    private array $filetype_analyzers = [];
    /**
     * @var array<string, string>
     */
    private array $filetype_scanner_paths = [];
    /**
     * @var array<string, string>
     */
    private array $filetype_analyzer_paths = [];
    /**
     * @var array<string, IssueHandler>
     */
    private array $issue_handlers = [];
    /**
     * @var array<int, string>
     */
    private array $mock_classes = [];
    /**
     * @var array<string, string>
     */
    private array $preloaded_stub_files = [];
    /**
     * @var array<string, string>
     */
    private array $stub_files = [];
    public bool $hide_external_errors = false;
    public bool $hide_all_errors_except_passed_files = false;
    public bool $allow_includes = true;
    public bool $ignore_include_side_effects = false;
    public bool $respect_include_once = false;
    /** @var 1|2|3|4|5|6|7|8 */
    public int $level = 1;
    public ?bool $show_mixed_issues = null;
    public bool $strict_binary_operands = true;
    public bool $allow_bool_to_literal_bool_comparison = true;
    public bool $remember_property_assignments_after_call = true;
    public bool $use_igbinary = false;
    /** @var 'lz4'|'deflate'|'off' */
    public string $compressor = 'off';
    public bool $allow_string_standin_for_class = false;
    public bool $disable_suppress_all = true;
    public bool $use_phpdoc_method_without_magic_or_parent = false;
    public bool $use_phpdoc_property_without_magic_or_parent = false;
    public bool $skip_checks_on_unresolvable_includes = false;
    public bool $seal_all_methods = true;
    public bool $seal_all_properties = true;
    public bool $memoize_method_calls = false;
    public bool $hoist_constants = false;
    public bool $add_param_default_to_docblock_type = false;
    public bool $disable_var_parsing = false;
    public bool $check_for_throws_docblock = false;
    public bool $check_for_throws_in_global_scope = false;
    public bool $ignore_internal_falsable_issues = false;
    public bool $ignore_internal_nullable_issues = false;
    /**
     * @var array<string, bool>
     */
    public array $ignored_exceptions = [];
    /**
     * @var array<string, bool>
     */
    public array $ignored_exceptions_in_global_scope = [];
    /**
     * @var array<string, bool>
     */
    public array $ignored_exceptions_and_descendants = [];
    /**
     * @var array<string, bool>
     */
    public array $ignored_exceptions_and_descendants_in_global_scope = [];
    public bool $infer_property_types_from_constructor = true;
    public bool $ensure_array_string_offsets_exist = false;
    public bool $ensure_array_int_offsets_exist = false;
    public bool $ensure_override_attribute = true;
    /**
     * @var array<lowercase-string, bool>
     */
    public array $forbidden_functions = [];
    /**
     * @var array<string, bool>
     */
    public array $forbidden_constants = [];
    public bool $find_unused_code = true;
    public bool $find_unused_variables = false;
    public bool $find_unused_psalm_suppress = false;
    public bool $find_unused_baseline_entry = true;
    public bool $find_unused_issue_handler_suppression = true;
    public bool $run_taint_analysis = false;
    public bool $use_phpstorm_meta_path = true;
    public bool $resolve_from_config_file = true;
    public bool $restrict_return_types = false;
    public bool $limit_method_complexity = false;
    public bool $literal_array_key_check = false;
    public bool $all_functions_global = false;
    public bool $all_constants_global = false;
    public bool $force_jit = false;
    public int $max_graph_size = 200;
    public int $max_avg_path_length = 70;
    public int $max_shaped_array_size = 100;
    public float $long_scan_warning = 10.0;
    /**
     * @var string[]
     */
    public array $plugin_paths = [];
    /**
     * @var array<array{class:string,config:?SimpleXMLElement}>
     */
    private array $plugin_classes = [];
    public bool $allow_internal_named_arg_calls = true;
    public bool $allow_named_arg_calls = true;
    /** @var array<string, mixed> */
    private array $predefined_constants = [];
    /** @var array<callable-string, bool> */
    private array $predefined_functions = [];
    private ?Class_Loader $composer_class_loader = null;
    public string $hash = '';
    public ?string $error_baseline = null;
    public bool $include_php_versions_in_error_baseline = false;
    /**
     * @internal
     */
    public string $shepherd_endpoint = 'https://shepherd.dev/hooks/psalm';
    /**
     * @var array<string, string>
     */
    public array $globals = [];
    public int $max_string_length = 1000;
    private ?Include_Collector $include_collector = null;
    private ?Taint_Analysis_File_Filter $taint_analysis_ignored_files = null;
    /**
     * @var bool whether to emit a backtrace of emitted issues to stderr
     */
    public bool $debug_emitted_issues = false;
    private bool $report_info = true;
    public Event_Dispatcher $event_dispatcher;
    /** @var list<ConfigIssue> */
    public array $config_issues = [];
    /**
     * @var 'default'|'never'|'always'
     */
    public string $trigger_error_exits = 'default';
    /**
     * @var string[]
     */
    public array $internal_stubs = [];
    /** @var ?int<1, max> */
    public ?int $threads = null;
    /** @var ?int<1, max> */
    public ?int $scan_threads = null;
    /**
     * A list of php extensions supported by Psalm.
     * Where key - extension name (without ext- prefix), value - whether to load extension’s stub.
     * Values:
     *  - true: ext enabled explicitly or bundled with PHP (should load stubs)
     *  - false: ext disabled explicitly (should not load stubs)
     *  - null: state is unknown (e.g. config not processed yet) or ext neither explicitly enabled or disabled.
     *
     * @psalm-readonly-allow-private-mutation
     * @var array<string, bool|null>
     */
    public array $php_extensions = ["amqp" => null, "apcu" => null, "decimal" => null, "dom" => null, "ds" => null, "ffi" => null, "geos" => null, "gmp" => null, "ibm_db2" => null, "mongodb" => null, "mysqli" => null, "pdo" => null, "random" => null, "rdkafka" => null, "redis" => null, "simplexml" => null, "soap" => null, "xdebug" => null];
    /**
     * A list of php extensions described in CallMap Psalm files
     * as opposite to stub files loaded by condition (see stubs/extensions dir).
     *
     * @see https://www.php.net/manual/en/extensions.membership.php
     * @var list<non-empty-string>
     * @readonly
     */
    public array $php_extensions_supported_by_psalm_callmaps = ['apache', 'bcmath', 'bzip2', 'calendar', 'ctype', 'curl', 'dom', 'enchant', 'exif', 'filter', 'gd', 'gettext', 'gmp', 'hash', 'ibm_db2', 'iconv', 'imap', 'intl', 'json', 'ldap', 'libxml', 'mbstring', 'mysqli', 'mysqlnd', 'mhash', 'oci8', 'opcache', 'openssl', 'pcntl', 'PDO', 'pdo_mysql', 'pdo-sqlite', 'pdo-pgsql', 'pgsql', 'pspell', 'phar', 'phpdbg', 'posix', 'redis', 'readline', 'session', 'sockets', 'sqlite3', 'snmp', 'soap', 'sodium', 'shmop', 'sysvsem', 'tidy', 'tokenizer', 'uodbc', 'xml', 'xmlreader', 'xmlwriter', 'xsl', 'zip', 'zlib'];
    /**
     * A list of php extensions required by the project that aren't fully supported by Psalm.
     *
     * @var array<string, true>
     */
    public array $php_extensions_not_supported = [];
    /**
     * @var array<class-string, PluginInterface>
     */
    private array $plugins = [];
    /** @var list<string> */
    public array $config_warnings = [];
    /** @internal */
    protected function __construct()
    {
        self::$instance = $this;
        $this->event_dispatcher = new Event_Dispatcher();
        $this->universal_object_crates = [strtolower(stdClass::class)];
    }
    /**
     * Gets a Config object from an XML file.
     *
     * Searches up a folder hierarchy for the most immediate config.
     *
     * @throws ConfigException if a config path is not found
     */
    public static function get_config_for_path(string $path, string $current_dir): Config
    {
        $config_path = self::locate_config_file($path);
        if (!$config_path) {
            throw new Config_Not_Found_Exception('Config not found for path ' . $path);
        }
        return self::load_from_xml_file($config_path, $current_dir);
    }
    /**
     * Searches up a folder hierarchy for the most immediate config.
     *
     * @throws ConfigException
     */
    public static function locate_config_file(string $path): ?string
    {
        $dir_path = realpath($path);
        if ($dir_path === false) {
            throw new Config_Not_Found_Exception('Config not found for path ' . $path);
        }
        if (!is_dir($dir_path)) {
            $dir_path = dirname($dir_path);
        }
        do {
            foreach (self::DEFAULT_FILE_NAMES as $default_file_name) {
                if (file_exists($maybe_path = $dir_path . DIRECTORY_SEPARATOR . $default_file_name)) {
                    return $maybe_path;
                }
            }
            $dir_path = dirname($dir_path);
        } while (dirname($dir_path) !== $dir_path);
        return null;
    }
    /**
     * Creates a new config object from the file
     */
    public static function load_from_xml_file(string $file_path, string $current_dir): Config
    {
        $file_contents = file_get_contents($file_path);
        $base_dir = dirname($file_path);
        if ($file_contents === false) {
            throw new InvalidArgumentException('Cannot open ' . $file_path);
        }
        if ($file_contents === '') {
            throw new InvalidArgumentException('Invalid empty file ' . $file_path);
        }
        try {
            $config = self::load_from_xml($base_dir, $file_contents, $current_dir, $file_path);
            $config->hash = sha1($file_contents . PSALM_VERSION);
        } catch (Config_Exception $e) {
            throw new Config_Exception('Problem parsing ' . $file_path . ":\n" . '  ' . $e->get_message());
        }
        return $config;
    }
    /**
     * Computes the hash to use for a cache folder from CLI flags and from the config file's xml contents
     */
    public function compute_hash(): string
    {
        return sha1($this->hash . ':' . $this->level);
    }
    /**
     * Creates a new config object from an XML string
     *
     * @param  string|null      $current_dir Current working directory, if different to $base_dir
     * @param  non-empty-string $file_contents
     * @throws ConfigException
     */
    public static function load_from_xml(string $base_dir, string $file_contents, ?string $current_dir = null, ?string $file_path = null): Config
    {
        if ($current_dir === null) {
            $current_dir = $base_dir;
        }
        self::validate_xml_config($base_dir, $file_contents);
        return self::from_xml_and_paths($base_dir, $file_contents, $current_dir, $file_path);
    }
    /**
     * @param non-empty-string $file_contents
     */
    private static function load_dom_document(string $base_dir, string $file_contents): Dom_Document
    {
        $dom_document = new Dom_Document();
        // there's no obvious way to set xml:base for a document when loading it from string
        // so instead we're changing the current directory instead to be able to process XIncludes
        $oldpwd = getcwd();
        chdir($base_dir);
        $dom_document->load_xml($file_contents, LIBXML_NONET);
        $dom_document->xinclude(LIBXML_NOWARNING | LIBXML_NONET);
        /** @psalm-suppress PossiblyFalseArgument */
        chdir($oldpwd);
        return $dom_document;
    }
    /**
     * @param non-empty-string $file_contents
     * @throws ConfigException
     */
    private static function validate_xml_config(string $base_dir, string $file_contents): void
    {
        $schema_path = dirname(__DIR__, 2) . '/config.xsd';
        if (!file_exists($schema_path)) {
            throw new Config_Exception('Cannot locate config schema');
        }
        // Enable user error handling
        $prev_xml_internal_errors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom_document = self::load_dom_document($base_dir, $file_contents);
        $psalm_nodes = $dom_document->get_elements_by_tag_name('psalm');
        $psalm_node = $psalm_nodes->item(0);
        if (!$psalm_node) {
            throw new Config_Exception('Missing psalm node');
        }
        if (!$psalm_node->has_attribute('xmlns')) {
            $psalm_node->set_attribute('xmlns', self::CONFIG_NAMESPACE);
            $old_dom_document = $dom_document;
            $old_file_contents = $old_dom_document->save_xml();
            assert($old_file_contents !== false && $old_file_contents !== '');
            $dom_document = self::load_dom_document($base_dir, $old_file_contents);
        }
        $dom_document->schema_validate($schema_path);
        // If it returns false it will generate errors handled below
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev_xml_internal_errors);
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL || $error->level === LIBXML_ERR_ERROR) {
                throw new Config_Exception('Error on line ' . $error->line . ":\n" . '    ' . $error->message);
            }
        }
    }
    /**
     * @param positive-int $line_number 1-based line number
     * @return int 0-based byte offset
     * @throws OutOfBoundsException
     */
    private static function line_number_to_byte_offset(string $string, int $line_number): int
    {
        if ($line_number === 1) {
            return 0;
        }
        $offset = 0;
        for ($i = 0; $i < $line_number - 1; $i++) {
            $newline_offset = strpos($string, "\n", $offset);
            if (false === $newline_offset) {
                throw new OutOfBoundsException('Line ' . $line_number . ' is not found in a string with ' . ($i + 1) . ' lines');
            }
            $offset = $newline_offset + 1;
        }
        if ($offset > strlen($string)) {
            throw new OutOfBoundsException('Line ' . $line_number . ' is not found');
        }
        return $offset;
    }
    private static function process_deprecated_attribute(Dom_Attr $attribute, string $file_contents, self $config, string $config_path): void
    {
        $line = $attribute->get_line_no();
        assert($line > 0);
        // getLineNo() always returns non-zero for nodes loaded from file
        $offset = self::line_number_to_byte_offset($file_contents, $line);
        $attribute_start = strrpos($file_contents, $attribute->name, $offset - strlen($file_contents)) ?: 0;
        $attribute_end = $attribute_start + strlen($attribute->name) - 1;
        $config->config_issues[] = new Config_Issue('Attribute "' . $attribute->name . '" is deprecated ' . 'and is going to be removed in the next major version', new Raw($file_contents, $config_path, basename($config_path), $attribute_start, $attribute_end));
    }
    private static function process_deprecated_element(Dom_Element $deprecated_element_xml, string $file_contents, self $config, string $config_path): void
    {
        $line = $deprecated_element_xml->get_line_no();
        assert($line > 0);
        $offset = self::line_number_to_byte_offset($file_contents, $line);
        $element_start = strpos($file_contents, (string) $deprecated_element_xml->local_name, $offset) ?: 0;
        $element_end = $element_start + strlen((string) $deprecated_element_xml->local_name) - 1;
        $config->config_issues[] = new Config_Issue('Element "' . $deprecated_element_xml->local_name . '" is deprecated ' . 'and is going to be removed in the next major version', new Raw($file_contents, $config_path, basename($config_path), $element_start, $element_end));
    }
    private static function process_config_deprecations(self $config, Dom_Document $dom_document, string $file_contents, string $config_path): void
    {
        $config->config_issues = [];
        // Attributes to be removed in Psalm 6
        $deprecated_attributes = [];
        /** @var list<string> */
        $deprecated_elements = [];
        $psalm_element_item = $dom_document->get_elements_by_tag_name('psalm')->item(0);
        assert($psalm_element_item !== null);
        $attributes = $psalm_element_item->attributes;
        foreach ($attributes as $attribute) {
            if (in_array($attribute->name, $deprecated_attributes, true)) {
                self::process_deprecated_attribute($attribute, $file_contents, $config, $config_path);
            }
        }
        foreach ($deprecated_elements as $deprecated_element) {
            $deprecated_elements_xml = $dom_document->get_elements_by_tag_name_ns(self::CONFIG_NAMESPACE, $deprecated_element);
            if ($deprecated_elements_xml->length) {
                $deprecated_element_xml = $deprecated_elements_xml->item(0);
                self::process_deprecated_element($deprecated_element_xml, $file_contents, $config, $config_path);
            }
        }
    }
    /**
     * @param non-empty-string $file_contents
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedPropertyFetch
     * @throws ConfigException
     */
    private static function from_xml_and_paths(string $base_dir, string $file_contents, string $current_dir, ?string $config_path): self
    {
        $config = new static();
        $dom_document = self::load_dom_document($base_dir, $file_contents);
        if (null !== $config_path) {
            self::process_config_deprecations($config, $dom_document, $file_contents, $config_path);
        }
        $config_xml = simplexml_import_dom($dom_document);
        $boolean_attributes = ['arrayCache' => 'array_cache', 'useDocblockTypes' => 'use_docblock_types', 'useDocblockPropertyTypes' => 'use_docblock_property_types', 'docblockPropertyTypesSealProperties' => 'docblock_property_types_seal_properties', 'throwExceptionOnError' => 'throw_exception', 'hideExternalErrors' => 'hide_external_errors', 'hideAllErrorsExceptPassedFiles' => 'hide_all_errors_except_passed_files', 'resolveFromConfigFile' => 'resolve_from_config_file', 'allowFileIncludes' => 'allow_includes', 'ignoreIncludeSideEffects' => 'ignore_include_side_effects', 'respectIncludeOnce' => 'respect_include_once', 'strictBinaryOperands' => 'strict_binary_operands', 'allowBoolToLiteralBoolComparison' => 'allow_bool_to_literal_bool_comparison', 'rememberPropertyAssignmentsAfterCall' => 'remember_property_assignments_after_call', 'disableVarParsing' => 'disable_var_parsing', 'allowStringToStandInForClass' => 'allow_string_standin_for_class', 'disableSuppressAll' => 'disable_suppress_all', 'usePhpDocMethodsWithoutMagicCall' => 'use_phpdoc_method_without_magic_or_parent', 'usePhpDocPropertiesWithoutMagicCall' => 'use_phpdoc_property_without_magic_or_parent', 'memoizeMethodCallResults' => 'memoize_method_calls', 'hoistConstants' => 'hoist_constants', 'addParamDefaultToDocblockType' => 'add_param_default_to_docblock_type', 'checkForThrowsDocblock' => 'check_for_throws_docblock', 'checkForThrowsInGlobalScope' => 'check_for_throws_in_global_scope', 'ignoreInternalFunctionFalseReturn' => 'ignore_internal_falsable_issues', 'ignoreInternalFunctionNullReturn' => 'ignore_internal_nullable_issues', 'includePhpVersionsInErrorBaseline' => 'include_php_versions_in_error_baseline', 'ensureArrayStringOffsetsExist' => 'ensure_array_string_offsets_exist', 'ensureArrayIntOffsetsExist' => 'ensure_array_int_offsets_exist', 'ensureOverrideAttribute' => 'ensure_override_attribute', 'reportMixedIssues' => 'show_mixed_issues', 'skipChecksOnUnresolvableIncludes' => 'skip_checks_on_unresolvable_includes', 'sealAllMethods' => 'seal_all_methods', 'sealAllProperties' => 'seal_all_properties', 'runTaintAnalysis' => 'run_taint_analysis', 'usePhpStormMetaPath' => 'use_phpstorm_meta_path', 'allowInternalNamedArgumentsCalls' => 'allow_internal_named_arg_calls', 'allowNamedArgumentCalls' => 'allow_named_arg_calls', 'findUnusedPsalmSuppress' => 'find_unused_psalm_suppress', 'findUnusedBaselineEntry' => 'find_unused_baseline_entry', 'findUnusedIssueHandlerSuppression' => 'find_unused_issue_handler_suppression', 'reportInfo' => 'report_info', 'restrictReturnTypes' => 'restrict_return_types', 'limitMethodComplexity' => 'limit_method_complexity'];
        foreach ($boolean_attributes as $xml_name => $internal_name) {
            if (isset($config_xml[$xml_name])) {
                $attribute_text = (string) $config_xml[$xml_name];
                $config->set_boolean_attribute($internal_name, $attribute_text === 'true' || $attribute_text === '1');
            }
        }
        $config->source_filename = $config_path;
        if ($config->resolve_from_config_file) {
            $config->base_dir = $base_dir;
        } else {
            $config->base_dir = $current_dir;
            $base_dir = $current_dir;
        }
        $composer_json_path = Composer::get_json_file_path($config->base_dir);
        $composer_json = null;
        if (file_exists($composer_json_path)) {
            $composer_json_contents = file_get_contents($composer_json_path);
            assert($composer_json_contents !== false);
            $composer_json = json_decode($composer_json_contents, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($composer_json)) {
                throw new UnexpectedValueException('Invalid composer.json at ' . $composer_json_path);
            }
        }
        $required_extensions = [];
        foreach ($composer_json["require"] ?? [] as $required => $_) {
            if (str_starts_with((string) $required, "ext-")) {
                $required_extensions[strtolower(substr((string) $required, 4))] = true;
            }
        }
        foreach ($required_extensions as $required_ext => $_) {
            if (array_key_exists($required_ext, $config->php_extensions)) {
                $config->php_extensions[$required_ext] = true;
            } else {
                $config->php_extensions_not_supported[$required_ext] = true;
            }
        }
        if (isset($config_xml->enable_extensions) && isset($config_xml->enable_extensions->extension)) {
            foreach ($config_xml->enable_extensions->extension as $extension) {
                assert(isset($extension["name"]));
                $extension_name = (string) $extension["name"];
                assert(array_key_exists($extension_name, $config->php_extensions));
                $config->php_extensions[$extension_name] = true;
            }
        }
        if (isset($config_xml->disable_extensions) && isset($config_xml->disable_extensions->extension)) {
            foreach ($config_xml->disable_extensions->extension as $extension) {
                assert(isset($extension["name"]));
                $extension_name = (string) $extension["name"];
                assert(array_key_exists($extension_name, $config->php_extensions));
                $config->php_extensions[$extension_name] = false;
            }
        }
        if (isset($config_xml['phpVersion'])) {
            $config->configured_php_version = (string) $config_xml['phpVersion'];
        }
        if (isset($config_xml['autoloader'])) {
            $autoloader = (string) $config_xml['autoloader'];
            $autoloader_path = $config->base_dir . DIRECTORY_SEPARATOR . $autoloader;
            if (!file_exists($autoloader_path)) {
                // in here for legacy reasons where people put absolute paths but psalm resolved it relative
                if ($autoloader[0] === '/') {
                    $autoloader_path = $autoloader;
                }
                if (!file_exists($autoloader_path)) {
                    throw new Config_Exception('Cannot locate autoloader');
                }
            }
            $config->autoloader = (string) realpath($autoloader_path);
        }
        $no_cache = false;
        if (isset($config_xml['noCache'])) {
            $no_cache = (string) $config_xml['noCache'];
            $no_cache = $no_cache === '1' || $no_cache === 'true';
        }
        if ($no_cache) {
            $config->cache_directory = null;
        } elseif (isset($config_xml['cacheDirectory'])) {
            $config->cache_directory = (string) $config_xml['cacheDirectory'];
        } elseif ($user_cache_dir = (new Xdg())->get_home_cache_dir()) {
            $config->cache_directory = $user_cache_dir . '/psalm';
        } else {
            $config->cache_directory = sys_get_temp_dir() . '/psalm';
        }
        $config->global_cache_directory = $config->cache_directory;
        if ($config->cache_directory !== null) {
            $config->cache_directory .= DIRECTORY_SEPARATOR . sha1($base_dir);
        }
        if (isset($config_xml['serializer'])) {
            $attribute_text = (string) $config_xml['serializer'];
            $config->use_igbinary = $attribute_text === 'igbinary';
            if ($config->use_igbinary && (!function_exists('igbinary_serialize') || !function_exists('igbinary_unserialize'))) {
                $config->use_igbinary = false;
                $config->config_warnings[] = '"serializer" set to "igbinary" but ext-igbinary seems to be missing on ' . 'the system. Using php\'s build-in serializer.';
            }
        } elseif ($igbinary_version = phpversion('igbinary')) {
            $config->use_igbinary = version_compare($igbinary_version, '2.0.5') >= 0;
        }
        if (isset($config_xml['compressor'])) {
            $compressor = (string) $config_xml['compressor'];
            if ($compressor === 'lz4') {
                if (function_exists('lz4_compress') && function_exists('lz4_uncompress')) {
                    $config->compressor = 'lz4';
                } else {
                    $config->config_warnings[] = '"compressor" set to "lz4" but ext-lz4 seems to be missing on the ' . 'system. Disabling cache compressor.';
                }
            } elseif ($compressor === 'deflate') {
                if (function_exists('gzinflate') && function_exists('gzdeflate')) {
                    $config->compressor = 'deflate';
                } else {
                    $config->config_warnings[] = '"compressor" set to "deflate" but zlib seems to be missing on the ' . 'system. Disabling cache compressor.';
                }
            }
        } elseif (function_exists('gzinflate') && function_exists('gzdeflate')) {
            $config->compressor = 'deflate';
        }
        if (isset($config_xml['findUnusedCode'])) {
            $attribute_text = (string) $config_xml['findUnusedCode'];
            $config->find_unused_code = $attribute_text === 'true' || $attribute_text === '1';
            $config->find_unused_variables = $config->find_unused_code;
        }
        if (isset($config_xml['disallowLiteralKeysOnUnshapedArrays'])) {
            $attribute_text = (string) $config_xml['disallowLiteralKeysOnUnshapedArrays'];
            $config->literal_array_key_check = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['allFunctionsGlobal'])) {
            $attribute_text = (string) $config_xml['allFunctionsGlobal'];
            $config->all_functions_global = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['allConstantsGlobal'])) {
            $attribute_text = (string) $config_xml['allConstantsGlobal'];
            $config->all_constants_global = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['forceJit'])) {
            $attribute_text = (string) $config_xml['forceJit'];
            $config->force_jit = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['findUnusedVariablesAndParams'])) {
            $attribute_text = (string) $config_xml['findUnusedVariablesAndParams'];
            $config->find_unused_variables = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['errorLevel'])) {
            $attribute_text = (int) $config_xml['errorLevel'];
            if (!in_array($attribute_text, [1, 2, 3, 4, 5, 6, 7, 8], true)) {
                throw new Config_Exception('Invalid error level ' . $config_xml['errorLevel']);
            }
            $config->level = $attribute_text;
        } else {
            $config->level = 2;
        }
        // turn on unused variable detection in level 1
        if (!isset($config_xml['findUnusedCode']) && !isset($config_xml['findUnusedVariablesAndParams']) && $config->level === 1 && $config->show_mixed_issues !== false) {
            $config->find_unused_variables = true;
        }
        if (isset($config_xml['errorBaseline'])) {
            $attribute_text = (string) $config_xml['errorBaseline'];
            $config->error_baseline = $attribute_text;
        }
        if (isset($config_xml['maxStringLength'])) {
            $attribute_text = (int) $config_xml['maxStringLength'];
            $config->max_string_length = $attribute_text;
        }
        if (isset($config_xml['maxShapedArraySize'])) {
            $attribute_text = (int) $config_xml['maxShapedArraySize'];
            $config->max_shaped_array_size = $attribute_text;
        }
        if (isset($config_xml['longScanWarning'])) {
            $attribute_text = (float) $config_xml['longScanWarning'];
            $config->long_scan_warning = $attribute_text;
        }
        if (isset($config_xml['inferPropertyTypesFromConstructor'])) {
            $attribute_text = (string) $config_xml['inferPropertyTypesFromConstructor'];
            $config->infer_property_types_from_constructor = $attribute_text === 'true' || $attribute_text === '1';
        }
        if (isset($config_xml['triggerErrorExits'])) {
            $attribute_text = (string) $config_xml['triggerErrorExits'];
            if ($attribute_text === 'always' || $attribute_text === 'never') {
                $config->trigger_error_exits = $attribute_text;
            }
        }
        if (isset($config_xml->project_files)) {
            $config->project_files = Project_File_Filter::load_from_xml_element($config_xml->project_files, $base_dir, true);
        }
        // any paths passed via CLI should be added to the projectFiles
        // as they're getting analyzed like if they are part of the project
        // ProjectAnalyzer::getInstance()->check_paths_files is not populated at this point in time
        $paths_to_check = null;
        global $argv;
        // Hack for Symfonys own argv resolution.
        // @see https://github.com/vimeo/psalm/issues/10465
        if (!isset($argv[0]) || basename($argv[0]) !== 'psalm-plugin') {
            $paths_to_check = Cli_Utils::get_paths_to_check(null);
        }
        if ($paths_to_check !== null) {
            $paths_to_add_to_project_files = [];
            foreach ($paths_to_check as $path) {
                // if we have an .xml arg here, the files passed are invalid
                // valid cases (in which we don't want to add CLI passed files to projectFiles though)
                // are e.g. if running phpunit tests for psalm itself
                if (str_ends_with($path, '.xml')) {
                    $paths_to_add_to_project_files = [];
                    break;
                }
                // we need an absolute path for checks
                if (Path::is_relative($path)) {
                    $prospective_path = $base_dir . DIRECTORY_SEPARATOR . $path;
                } else {
                    $prospective_path = $path;
                }
                // will report an error when config is loaded anyway
                if (!file_exists($prospective_path)) {
                    continue;
                }
                if ($config->is_in_project_dirs($prospective_path)) {
                    continue;
                }
                $paths_to_add_to_project_files[] = $prospective_path;
            }
            if ($paths_to_add_to_project_files !== [] && !isset($config_xml->project_files)) {
                if ($config_xml === null) {
                    $config_xml = new Simple_Xml_Element('<psalm/>');
                }
                $config_xml->add_child('projectFiles');
            }
            if ($paths_to_add_to_project_files !== [] && isset($config_xml->project_files)) {
                foreach ($paths_to_add_to_project_files as $path) {
                    if (is_dir($path)) {
                        $child = $config_xml->project_files->add_child('directory');
                    } else {
                        $child = $config_xml->project_files->add_child('file');
                    }
                    $child->add_attribute('name', $path);
                }
                $config->project_files = Project_File_Filter::load_from_xml_element($config_xml->project_files, $base_dir, true);
            }
        }
        if (isset($config_xml->extra_files)) {
            $config->extra_files = Project_File_Filter::load_from_xml_element($config_xml->extra_files, $base_dir, true);
        }
        if (isset($config_xml->taint_analysis->ignore_files)) {
            $config->taint_analysis_ignored_files = Taint_Analysis_File_Filter::load_from_xml_element($config_xml->taint_analysis->ignore_files, $base_dir, false);
        }
        if (isset($config_xml->file_extensions->extension)) {
            $config->file_extensions = [];
            $config->load_file_extensions($config_xml->file_extensions->extension);
        }
        if (isset($config_xml->mock_classes) && isset($config_xml->mock_classes->class)) {
            /** @var SimpleXMLElement $mock_class */
            foreach ($config_xml->mock_classes->class as $mock_class) {
                $config->mock_classes[] = strtolower((string) $mock_class['name']);
            }
        }
        if (isset($config_xml->universal_object_crates) && isset($config_xml->universal_object_crates->class)) {
            /** @var SimpleXMLElement $universal_object_crate */
            foreach ($config_xml->universal_object_crates->class as $universal_object_crate) {
                $class_string = (string) $universal_object_crate['name'];
                $config->add_universal_object_crate($class_string);
            }
        }
        if (isset($config_xml->ignore_exceptions)) {
            if (isset($config_xml->ignore_exceptions->class)) {
                foreach ($config_xml->ignore_exceptions->class as $exception_class) {
                    $exception_name = (string) $exception_class['name'];
                    $global_attribute_text = (string) $exception_class['onlyGlobalScope'];
                    if ($global_attribute_text !== 'true' && $global_attribute_text !== '1') {
                        $config->ignored_exceptions[$exception_name] = true;
                    }
                    $config->ignored_exceptions_in_global_scope[$exception_name] = true;
                }
            }
            if (isset($config_xml->ignore_exceptions->class_and_descendants)) {
                foreach ($config_xml->ignore_exceptions->class_and_descendants as $exception_class) {
                    $exception_name = (string) $exception_class['name'];
                    $global_attribute_text = (string) $exception_class['onlyGlobalScope'];
                    if ($global_attribute_text !== 'true' && $global_attribute_text !== '1') {
                        $config->ignored_exceptions_and_descendants[$exception_name] = true;
                    }
                    $config->ignored_exceptions_and_descendants_in_global_scope[$exception_name] = true;
                }
            }
        }
        if (isset($config_xml->forbidden_functions) && isset($config_xml->forbidden_functions->function)) {
            /** @var SimpleXMLElement $forbidden_function */
            foreach ($config_xml->forbidden_functions->function as $forbidden_function) {
                $config->forbidden_functions[strtolower((string) $forbidden_function['name'])] = true;
            }
        }
        if (isset($config_xml->forbidden_constants) && isset($config_xml->forbidden_constants->constant)) {
            /** @var SimpleXMLElement $forbidden_function */
            foreach ($config_xml->forbidden_constants->constant as $forbidden_function) {
                $config->forbidden_constants[(string) $forbidden_function['name']] = true;
            }
        }
        if (isset($config_xml->stubs) && isset($config_xml->stubs->file)) {
            /** @var SimpleXMLElement $stub_file */
            foreach ($config_xml->stubs->file as $stub_file) {
                $stub_file_name = (string) $stub_file['name'];
                if (!Path::is_absolute($stub_file_name)) {
                    $stub_file_name = $config->base_dir . DIRECTORY_SEPARATOR . $stub_file_name;
                }
                $file_path = realpath($stub_file_name);
                if (!$file_path) {
                    throw new Config_Exception('Cannot resolve stubfile path ' . $config->base_dir . DIRECTORY_SEPARATOR . $stub_file['name']);
                }
                if (isset($stub_file['preloadClasses'])) {
                    $preload_classes = (string) $stub_file['preloadClasses'];
                    if ($preload_classes === 'true' || $preload_classes === '1') {
                        $config->add_preloaded_stub_file($file_path);
                    } else {
                        $config->add_stub_file($file_path);
                    }
                } else {
                    $config->add_stub_file($file_path);
                }
            }
        }
        // this plugin loading system borrows heavily from etsy/phan
        if (isset($config_xml->plugins)) {
            if (isset($config_xml->plugins->plugin)) {
                foreach ($config_xml->plugins->plugin as $plugin) {
                    $plugin_file_name = (string) $plugin['filename'];
                    $path = Path::is_absolute($plugin_file_name) ? $plugin_file_name : $config->base_dir . DIRECTORY_SEPARATOR . $plugin_file_name;
                    $config->add_plugin_path($path);
                }
            }
            if (isset($config_xml->plugins->plugin_class)) {
                foreach ($config_xml->plugins->plugin_class as $plugin) {
                    $plugin_class_name = $plugin['class'];
                    // any child elements are used as plugin configuration
                    $plugin_config = null;
                    if ($plugin->count()) {
                        $plugin_config = $plugin->children();
                    }
                    $config->add_plugin_class((string) $plugin_class_name, $plugin_config);
                }
            }
        }
        if (isset($config_xml->issue_handlers)) {
            foreach ($config_xml->issue_handlers as $issue_handlers) {
                $issue_handler_children = $issue_handlers->children();
                if ($issue_handler_children) {
                    foreach ($issue_handler_children as $key => $issue_handler) {
                        if ($key === 'PluginIssue') {
                            $custom_class_name = (string) $issue_handler['name'];
                            $config->issue_handlers[$custom_class_name] = Issue_Handler::load_from_xml_element($issue_handler, $base_dir);
                        } else {
                            /** @var string $key */
                            $config->issue_handlers[$key] = Issue_Handler::load_from_xml_element($issue_handler, $base_dir);
                        }
                    }
                }
            }
        }
        if (isset($config_xml->globals) && isset($config_xml->globals->var)) {
            /** @var SimpleXMLElement $var */
            foreach ($config_xml->globals->var as $var) {
                $config->globals['$' . $var['name']] = (string) $var['type'];
            }
        }
        if (isset($config_xml['threads'])) {
            $config->threads = max(1, (int) $config_xml['threads']);
            $config->scan_threads = $config->threads;
        }
        if (isset($config_xml['scanThreads'])) {
            $config->scan_threads = max(1, (int) $config_xml['scanThreads']);
        }
        return $config;
    }
    public static function get_instance(): Config
    {
        if (self::$instance) {
            return self::$instance;
        }
        throw new UnexpectedValueException('No config initialized');
    }
    public function set_composer_class_loader(?Class_Loader $loader = null): void
    {
        $this->composer_class_loader = $loader;
    }
    /** @return array<string, IssueHandler> */
    public function get_issue_handlers(): array
    {
        return $this->issue_handlers;
    }
    public function set_advanced_error_level(string $issue_key, array $config, ?string $default_error_level = null): void
    {
        $this->issue_handlers[$issue_key] = new Issue_Handler();
        if ($default_error_level !== null) {
            $this->issue_handlers[$issue_key]->set_error_level($default_error_level);
        }
        $this->issue_handlers[$issue_key]->set_custom_levels($config, $this->base_dir);
    }
    public function safe_set_advanced_error_level(string $issue_key, array $config, ?string $default_error_level = null): void
    {
        if (!isset($this->issue_handlers[$issue_key])) {
            $this->set_advanced_error_level($issue_key, $config, $default_error_level);
        }
    }
    public function set_custom_error_level(string $issue_key, string $error_level): void
    {
        $this->issue_handlers[$issue_key] = new Issue_Handler();
        $this->issue_handlers[$issue_key]->set_error_level($error_level);
    }
    public function safe_set_custom_error_level(string $issue_key, string $error_level): void
    {
        if (!isset($this->issue_handlers[$issue_key])) {
            $this->set_custom_error_level($issue_key, $error_level);
        }
    }
    /**
     * @throws ConfigException if a Config file could not be found
     */
    private function load_file_extensions(Simple_Xml_Element $extensions): void
    {
        foreach ($extensions as $extension) {
            $extension_name = (string) preg_replace('/^\.?/', '', (string) $extension['name'], 1);
            $this->file_extensions[] = $extension_name;
            if (isset($extension['scanner'])) {
                $path = $this->base_dir . DIRECTORY_SEPARATOR . $extension['scanner'];
                if (!file_exists($path)) {
                    throw new Config_Exception('Error parsing config: cannot find file ' . $path);
                }
                $this->filetype_scanner_paths[$extension_name] = $path;
            }
            if (isset($extension['checker'])) {
                $path = $this->base_dir . DIRECTORY_SEPARATOR . $extension['checker'];
                if (!file_exists($path)) {
                    throw new Config_Exception('Error parsing config: cannot find file ' . $path);
                }
                $this->filetype_analyzer_paths[$extension_name] = $path;
            }
        }
    }
    public function add_plugin_path(string $path): void
    {
        if (!file_exists($path)) {
            throw new InvalidArgumentException('Cannot find plugin file ' . $path);
        }
        $this->plugin_paths[] = $path;
    }
    public function add_plugin_class(string $class_name, ?Simple_Xml_Element $plugin_config = null): void
    {
        $this->plugin_classes[] = ['class' => $class_name, 'config' => $plugin_config];
    }
    /** @return array<array{class:string, config:?SimpleXMLElement}> */
    public function get_plugin_classes(): array
    {
        return $this->plugin_classes;
    }
    public function process_plugin_file_extensions(Project_Analyzer $project_analyzer): void
    {
        $project_analyzer->progress->debug('Process plugin adjustments...' . PHP_EOL);
        $socket = new Plugin_File_Extensions_Socket($this);
        foreach ($this->plugin_classes as $plugin_class_entry) {
            $plugin_class_name = $plugin_class_entry['class'];
            $plugin_config = $plugin_class_entry['config'];
            $plugin = $this->load_plugin($project_analyzer, $plugin_class_name);
            if (!$plugin instanceof Plugin_File_Extensions_Interface) {
                continue;
            }
            try {
                $plugin->process_file_extensions($socket, $plugin_config);
            } catch (Throwable $t) {
                throw new Config_Exception('Failed to process plugin file extensions ' . $plugin_class_name, 1635800581, $t);
            }
            $project_analyzer->progress->debug('Initialized plugin ' . $plugin_class_name . ' successfully' . PHP_EOL);
        }
        // populate additional aspects after plugins have been initialized
        foreach ($socket->get_additional_file_extensions() as $file_extension) {
            $this->file_extensions[] = $file_extension;
        }
        foreach ($socket->get_additional_file_type_scanners() as $extension => $class_name) {
            $this->filetype_scanners[$extension] = $class_name;
        }
        foreach ($socket->get_additional_file_type_analyzers() as $extension => $class_name) {
            $this->filetype_analyzers[$extension] = $class_name;
        }
    }
    /**
     * Initialises all the plugins (done once the config is fully loaded)
     */
    public function initialize_plugins(Project_Analyzer $project_analyzer): void
    {
        $codebase = $project_analyzer->get_codebase();
        $project_analyzer->progress->debug('Initializing plugins...' . PHP_EOL);
        $socket = new Plugin_Registration_Socket($this, $codebase);
        // initialize plugin classes earlier to let them hook into subsequent load process
        foreach ($this->plugin_classes as $plugin_class_entry) {
            $plugin_class_name = $plugin_class_entry['class'];
            $plugin_config = $plugin_class_entry['config'];
            $plugin = $this->load_plugin($project_analyzer, $plugin_class_name);
            if (!$plugin instanceof Plugin_Entry_Point_Interface) {
                continue;
            }
            try {
                $plugin($socket, $plugin_config);
            } catch (Throwable $t) {
                throw new Config_Exception('Failed to invoke plugin ' . $plugin_class_name, 1635800582, $t);
            }
            $project_analyzer->progress->debug('Initialized plugin ' . $plugin_class_name . ' successfully' . PHP_EOL);
        }
        foreach ($this->filetype_scanner_paths as $extension => $path) {
            $fq_class_name = $this->get_plugin_class_for_path($codebase, $path, File_Scanner::class);
            self::require_path($path);
            $this->filetype_scanners[$extension] = $fq_class_name;
        }
        foreach ($this->filetype_analyzer_paths as $extension => $path) {
            $fq_class_name = $this->get_plugin_class_for_path($codebase, $path, File_Analyzer::class);
            self::require_path($path);
            $this->filetype_analyzers[$extension] = $fq_class_name;
        }
        foreach ($this->plugin_paths as $path) {
            try {
                $plugin = new File_Based_Plugin_Adapter($path, $this, $codebase);
                $plugin($socket);
            } catch (Throwable $e) {
                throw new Config_Exception('Failed to load plugin ' . $path, 0, $e);
            }
        }
        new Html_Function_Tainter();
        $socket->register_hooks_from_class(Html_Function_Tainter::class);
    }
    private function load_plugin(Project_Analyzer $project_analyzer, string $plugin_class_name): Plugin_Interface
    {
        if (isset($this->plugins[$plugin_class_name])) {
            return $this->plugins[$plugin_class_name];
        }
        try {
            // Below will attempt to load plugins from the project directory first.
            // Failing that, it will use registered autoload chain, which will load
            // plugins from Psalm directory or phar file. If that fails as well, it
            // will fall back to project autoloader. It may seem that the last step
            // will always fail, but it's only true if project uses Composer autoloader
            if ($this->composer_class_loader && $pluginclas_class_path = $this->composer_class_loader->find_file($plugin_class_name)) {
                $project_analyzer->progress->debug('Loading plugin ' . $plugin_class_name . ' via require' . PHP_EOL);
                self::require_path($pluginclas_class_path);
            } else if (!class_exists($plugin_class_name)) {
                throw new UnexpectedValueException($plugin_class_name . ' is not a known class');
            }
            if (!is_a($plugin_class_name, Plugin_Interface::class, true)) {
                throw new UnexpectedValueException($plugin_class_name . ' is not a PluginInterface implementation');
            }
            $this->plugins[$plugin_class_name] = new $plugin_class_name();
            $project_analyzer->progress->debug('Loaded plugin ' . $plugin_class_name . PHP_EOL);
            return $this->plugins[$plugin_class_name];
        } catch (Throwable $e) {
            throw new Config_Exception('Failed to load plugin ' . $plugin_class_name, 0, $e);
        }
    }
    private static function require_path(string $path): void
    {
        /** @psalm-suppress UnresolvableInclude */
        require_once $path;
    }
    /**
     * @template T
     * @param  T::class $must_extend
     * @return class-string<T>
     */
    private function get_plugin_class_for_path(Codebase $codebase, string $path, string $must_extend): string
    {
        $file_storage = $codebase->create_file_storage_for_path($path);
        $file_to_scan = new File_Scanner($path, $this->shorten_file_name($path), true);
        $file_to_scan->scan($codebase, $file_storage);
        $declared_classes = Class_Like_Analyzer::get_classes_for_file($codebase, $path);
        if (!count($declared_classes)) {
            throw new InvalidArgumentException('Plugins must have at least one class in the file - ' . $path . ' has ' . count($declared_classes));
        }
        $fq_class_name = reset($declared_classes);
        if (!$codebase->classlikes->class_extends($fq_class_name, $must_extend)) {
            throw new InvalidArgumentException('This plugin must extend ' . $must_extend . ' - ' . $path . ' does not');
        }
        return $fq_class_name;
    }
    public function shorten_file_name(string $to): string
    {
        if (!is_file($to)) {
            return (string) preg_replace('/^' . preg_quote(rtrim($this->base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, '/') . '/', '', $to, 1);
        }
        $from = $this->base_dir;
        // some compatibility fixes for Windows paths
        $from = is_dir($from) ? rtrim($from, '\/') . '/' : $from;
        $to = is_dir($to) ? rtrim($to, '\/') . '/' : $to;
        $from = str_replace('\\', '/', $from);
        $to = str_replace('\\', '/', $to);
        $from = explode('/', $from);
        $to = explode('/', $to);
        $rel_path = $to;
        foreach ($from as $depth => $dir) {
            // find first non-matching dir
            if ($dir === $to[$depth]) {
                // ignore this directory
                array_shift($rel_path);
            } else {
                // get number of remaining dirs to $from
                $remaining = count($from) - $depth;
                if ($remaining > 1) {
                    // add traversals up to first matching dir
                    $pad_length = (count($rel_path) + $remaining - 1) * -1;
                    $rel_path = array_pad($rel_path, $pad_length, '..');
                    break;
                }
            }
        }
        return implode('/', $rel_path);
    }
    public function report_issue_in_file(string $issue_type, string $file_path): bool
    {
        if (($this->level < 3 && $this->show_mixed_issues === false || $this->level > 2 && $this->show_mixed_issues !== true) && in_array($issue_type, self::MIXED_ISSUES, true)) {
            return false;
        }
        if ($this->must_be_ignored($file_path)) {
            return false;
        }
        $dependent_files = [strtolower($file_path) => $file_path];
        $project_analyzer = Project_Analyzer::get_instance();
        // if the option is set and at least one file is passed via CLI
        if ($this->hide_all_errors_except_passed_files && $project_analyzer->check_paths_files && !in_array($file_path, $project_analyzer->check_paths_files, true)) {
            return false;
        }
        $codebase = $project_analyzer->get_codebase();
        if (!$this->hide_external_errors) {
            try {
                $file_storage = $codebase->file_storage_provider->get($file_path);
                $dependent_files += $file_storage->required_by_file_paths;
            } catch (InvalidArgumentException) {
                // do nothing
            }
        }
        $any_file_path_matched = false;
        foreach ($dependent_files as $dependent_file_path) {
            if ((!$project_analyzer->full_run && $codebase->analyzer->can_report_issues($dependent_file_path) || $project_analyzer->can_report_issues($dependent_file_path)) && ($file_path === $dependent_file_path || !$this->must_be_ignored($dependent_file_path))) {
                $any_file_path_matched = true;
                break;
            }
        }
        if (!$any_file_path_matched) {
            return false;
        }
        if ($this->get_reporting_level_for_file($issue_type, $file_path) === self::REPORT_SUPPRESS) {
            return false;
        }
        return true;
    }
    public function is_in_project_dirs(string $file_path): bool
    {
        return $this->project_files && $this->project_files->allows($file_path);
    }
    public function is_in_extra_dirs(string $file_path): bool
    {
        return $this->extra_files && $this->extra_files->allows($file_path);
    }
    public function must_be_ignored(string $file_path): bool
    {
        return $this->project_files && $this->project_files->forbids($file_path);
    }
    public function track_taints_in_path(string $file_path): bool
    {
        return !$this->taint_analysis_ignored_files || $this->taint_analysis_ignored_files->allows($file_path);
    }
    public function get_reporting_level_for_issue(Code_Issue $e): string
    {
        $fqcn_parts = explode('\\', $e::class);
        $issue_type = array_pop($fqcn_parts);
        $reporting_level = null;
        if ($e instanceof Class_Issue) {
            $reporting_level = $this->get_reporting_level_for_class($issue_type, $e->fq_classlike_name);
        } elseif ($e instanceof Method_Issue) {
            $reporting_level = $this->get_reporting_level_for_method($issue_type, $e->method_id);
        } elseif ($e instanceof Function_Issue) {
            $reporting_level = $this->get_reporting_level_for_function($issue_type, $e->function_id);
        } elseif ($e instanceof Property_Issue) {
            $reporting_level = $this->get_reporting_level_for_property($issue_type, $e->property_id);
        } elseif ($e instanceof Class_Constant_Issue) {
            $reporting_level = $this->get_reporting_level_for_class_constant($issue_type, $e->const_id);
        } elseif ($e instanceof Argument_Issue && $e->function_id) {
            $reporting_level = $this->get_reporting_level_for_argument($issue_type, $e->function_id);
        } elseif ($e instanceof Variable_Issue) {
            $reporting_level = $this->get_reporting_level_for_variable($issue_type, $e->var_name);
        }
        if ($reporting_level === null) {
            $reporting_level = $this->get_reporting_level_for_file($issue_type, $e->get_file_path());
        }
        if (!$this->report_info && $reporting_level === self::REPORT_INFO) {
            $reporting_level = self::REPORT_SUPPRESS;
        }
        $parent_issue_type = self::get_parent_issue_type($issue_type);
        if ($parent_issue_type && $reporting_level === self::REPORT_ERROR) {
            $parent_reporting_level = $this->get_reporting_level_for_file($parent_issue_type, $e->get_file_path());
            if ($parent_reporting_level !== $reporting_level) {
                return $parent_reporting_level;
            }
        }
        return $reporting_level;
    }
    /**
     * @psalm-pure
     */
    public static function get_parent_issue_type(string $issue_type): ?string
    {
        if ($issue_type === 'PossiblyUndefinedIntArrayOffset' || $issue_type === 'PossiblyUndefinedStringArrayOffset') {
            return 'PossiblyUndefinedArrayOffset';
        }
        if ($issue_type === 'PossiblyNullReference') {
            return 'NullReference';
        }
        if ($issue_type === 'PossiblyFalseReference') {
            return null;
        }
        if ($issue_type === 'PossiblyUndefinedArrayOffset') {
            return null;
        }
        if (str_starts_with($issue_type, 'Possibly')) {
            $stripped_issue_type = (string) preg_replace('/^Possibly(False|Null)?/', '', $issue_type, 1);
            if (!str_contains($stripped_issue_type, 'Invalid') && !str_starts_with($stripped_issue_type, 'Un')) {
                return 'Invalid' . $stripped_issue_type;
            }
            return $stripped_issue_type;
        }
        if (str_starts_with($issue_type, 'Tainted')) {
            return 'TaintedInput';
        }
        if (preg_match('/^(False|Null)[A-Z]/', $issue_type) && !strpos($issue_type, 'Reference')) {
            return preg_replace('/^(False|Null)/', 'Invalid', $issue_type, 1);
        }
        if ($issue_type === 'UndefinedInterfaceMethod') {
            return 'UndefinedMethod';
        }
        if ($issue_type === 'UndefinedMagicPropertyFetch') {
            return 'UndefinedPropertyFetch';
        }
        if ($issue_type === 'UndefinedMagicPropertyAssignment') {
            return 'UndefinedPropertyAssignment';
        }
        if ($issue_type === 'UndefinedMagicMethod') {
            return 'UndefinedMethod';
        }
        if ($issue_type === 'PossibleRawObjectIteration') {
            return 'RawObjectIteration';
        }
        if ($issue_type === 'UninitializedProperty') {
            return 'PropertyNotSetInConstructor';
        }
        if ($issue_type === 'InvalidDocblockParamName') {
            return 'InvalidDocblock';
        }
        if ($issue_type === 'UnusedClosureParam') {
            return 'UnusedParam';
        }
        if ($issue_type === 'UnusedConstructor') {
            return 'UnusedMethod';
        }
        if ($issue_type === 'StringIncrement') {
            return 'InvalidOperand';
        }
        if ($issue_type === 'InvalidLiteralArgument') {
            return 'InvalidArgument';
        }
        if ($issue_type === 'RedundantConditionGivenDocblockType') {
            return 'RedundantCondition';
        }
        if ($issue_type === 'RedundantFunctionCallGivenDocblockType') {
            return 'RedundantFunctionCall';
        }
        if ($issue_type === 'RedundantCastGivenDocblockType') {
            return 'RedundantCast';
        }
        if ($issue_type === 'TraitMethodSignatureMismatch') {
            return 'MethodSignatureMismatch';
        }
        if ($issue_type === 'ImplementedParamTypeMismatch') {
            return 'MoreSpecificImplementedParamType';
        }
        if ($issue_type === 'UndefinedDocblockClass') {
            return 'UndefinedClass';
        }
        if ($issue_type === 'UnusedForeachValue') {
            return 'UnusedVariable';
        }
        return null;
    }
    /** @return array{type: string, index: int, count: int}[] */
    public function get_issue_handler_suppressions(): array
    {
        $suppressions = [];
        foreach ($this->issue_handlers as $key => $handler) {
            foreach ($handler->get_filters() as $index => $filter) {
                $suppressions[] = ['type' => $key, 'index' => $index, 'count' => $filter->suppressions];
            }
        }
        return $suppressions;
    }
    /** @param array{type: string, index: int, count: int}[] $filters */
    public function combine_issue_handler_suppressions(array $filters): void
    {
        foreach ($filters as $filter) {
            $this->issue_handlers[$filter['type']]->get_filters()[$filter['index']]->suppressions += $filter['count'];
        }
    }
    public function get_reporting_level_for_file(string $issue_type, string $file_path): string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_file($file_path);
        }
        // this string is replaced by scoper for Phars, so be careful
        $issue_class = 'Psalm\Issue\\' . $issue_type;
        if (!class_exists($issue_class) || !is_a($issue_class, Code_Issue::class, true)) {
            return self::REPORT_ERROR;
        }
        $issue_level = $issue_class::ERROR_LEVEL;
        if ($issue_level > 0 && $issue_level < $this->level) {
            return self::REPORT_INFO;
        }
        return self::REPORT_ERROR;
    }
    public function get_reporting_level_for_class(string $issue_type, string $fq_classlike_name): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_class($fq_classlike_name);
        }
        return null;
    }
    public function get_reporting_level_for_method(string $issue_type, string $method_id): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_method($method_id);
        }
        return null;
    }
    public function get_reporting_level_for_function(string $issue_type, string $function_id): ?string
    {
        $level = null;
        if (isset($this->issue_handlers[$issue_type])) {
            $level = $this->issue_handlers[$issue_type]->get_reporting_level_for_function($function_id);
            if ($level === null && $issue_type === 'UndefinedFunction') {
                // undefined functions trigger global namespace fallback
                // so we should also check reporting levels for the symbol in global scope
                $root_function_id = (string) preg_replace('/.*\\\\/', '', $function_id);
                if ($root_function_id !== $function_id) {
                    /** @psalm-suppress PossiblyUndefinedStringArrayOffset https://github.com/vimeo/psalm/issues/7656 */
                    $level = $this->issue_handlers[$issue_type]->get_reporting_level_for_function($root_function_id);
                }
            }
        }
        return $level;
    }
    public function get_reporting_level_for_argument(string $issue_type, string $function_id): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_argument($function_id);
        }
        return null;
    }
    public function get_reporting_level_for_property(string $issue_type, string $property_id): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_property($property_id);
        }
        return null;
    }
    public function get_reporting_level_for_class_constant(string $issue_type, string $constant_id): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_class_constant($constant_id);
        }
        return null;
    }
    public function get_reporting_level_for_variable(string $issue_type, string $var_name): ?string
    {
        if (isset($this->issue_handlers[$issue_type])) {
            return $this->issue_handlers[$issue_type]->get_reporting_level_for_variable($var_name);
        }
        return null;
    }
    /**
     * @return array<string>
     */
    public function get_project_directories(): array
    {
        if (!$this->project_files) {
            return [];
        }
        return $this->project_files->get_directories();
    }
    /**
     * @return array<string>
     */
    public function get_project_files(): array
    {
        if (!$this->project_files) {
            return [];
        }
        return $this->project_files->get_files();
    }
    /**
     * @return array<string>
     */
    public function get_extra_directories(): array
    {
        if (!$this->extra_files) {
            return [];
        }
        return $this->extra_files->get_directories();
    }
    public function report_type_stats_for_file(string $file_path): bool
    {
        return $this->project_files && $this->project_files->allows($file_path) && $this->project_files->report_type_stats($file_path);
    }
    public function use_strict_types_for_file(string $file_path): bool
    {
        return $this->project_files && $this->project_files->use_strict_types($file_path);
    }
    /**
     * @return array<int, string>
     */
    public function get_file_extensions(): array
    {
        return $this->file_extensions;
    }
    /**
     * @return array<string, class-string<FileScanner>>
     */
    public function get_filetype_scanners(): array
    {
        return $this->filetype_scanners;
    }
    /**
     * @return array<string, class-string<FileAnalyzer>>
     */
    public function get_filetype_analyzers(): array
    {
        return $this->filetype_analyzers;
    }
    /**
     * @return array<int, string>
     */
    public function get_mock_classes(): array
    {
        return $this->mock_classes;
    }
    public function visit_preloaded_stub_files(Codebase $codebase, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $core_generic_files = [];
        if (PHP_VERSION_ID < 80000 && $codebase->analysis_php_version_id >= 80000) {
            $stringable_path = dirname(__DIR__, 2) . '/stubs/Php80.phpstub';
            if (!file_exists($stringable_path)) {
                throw new UnexpectedValueException('Cannot locate PHP 8.0 classes');
            }
            $core_generic_files[] = $stringable_path;
        }
        if (PHP_VERSION_ID < 80100 && $codebase->analysis_php_version_id >= 80100) {
            $stringable_path = dirname(__DIR__, 2) . '/stubs/Php81.phpstub';
            if (!file_exists($stringable_path)) {
                throw new UnexpectedValueException('Cannot locate PHP 8.1 classes');
            }
            $core_generic_files[] = $stringable_path;
        }
        if (PHP_VERSION_ID < 80200 && $codebase->analysis_php_version_id >= 80200) {
            $stringable_path = dirname(__DIR__, 2) . '/stubs/Php82.phpstub';
            if (!file_exists($stringable_path)) {
                throw new UnexpectedValueException('Cannot locate PHP 8.2 classes');
            }
            $core_generic_files[] = $stringable_path;
        }
        if (PHP_VERSION_ID < 80400 && $codebase->analysis_php_version_id >= 80400) {
            $stringable_path = dirname(__DIR__, 2) . '/stubs/Php84.phpstub';
            if (!file_exists($stringable_path)) {
                throw new UnexpectedValueException('Cannot locate PHP 8.4 classes');
            }
            $core_generic_files[] = $stringable_path;
        }
        if (PHP_VERSION_ID < 80500 && $codebase->analysis_php_version_id >= 80500) {
            $stringable_path = dirname(__DIR__, 2) . '/stubs/Php85.phpstub';
            if (!file_exists($stringable_path)) {
                throw new UnexpectedValueException('Cannot locate PHP 8.5 classes');
            }
            $core_generic_files[] = $stringable_path;
        }
        $stub_files = array_merge($core_generic_files, $this->preloaded_stub_files);
        if (!$stub_files) {
            return;
        }
        foreach ($stub_files as $file_path) {
            $file_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
            // fix mangled phar paths on Windows
            if (str_starts_with($file_path, 'phar:\\\\')) {
                $file_path = 'phar://' . substr($file_path, 7);
            }
            $codebase->scanner->add_file_to_deep_scan($file_path);
        }
        $progress->debug('Registering preloaded stub files' . "\n");
        $codebase->register_stub_files = true;
        $codebase->scan_files();
        $codebase->register_stub_files = false;
        $progress->debug('Finished registering preloaded stub files' . "\n");
    }
    public function visit_stub_files(Codebase $codebase, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $codebase->register_stub_files = true;
        $dir_lvl_2 = dirname(__DIR__, 2);
        $stubs_dir = $dir_lvl_2 . DIRECTORY_SEPARATOR . 'stubs' . DIRECTORY_SEPARATOR;
        $this->internal_stubs = [$stubs_dir . 'CoreGenericFunctions.phpstub', $stubs_dir . 'CoreGenericClasses.phpstub', $stubs_dir . 'CoreGenericIterators.phpstub', $stubs_dir . 'CoreImmutableClasses.phpstub', $stubs_dir . 'Reflection.phpstub', $stubs_dir . 'SPL.phpstub', $stubs_dir . 'CoreGenericAttributes.phpstub'];
        if ($codebase->analysis_php_version_id >= 70400) {
            $this->internal_stubs[] = $stubs_dir . 'Php74.phpstub';
        }
        if ($codebase->analysis_php_version_id >= 80000) {
            $this->internal_stubs[] = $stubs_dir . 'Php80.phpstub';
        }
        if ($codebase->analysis_php_version_id >= 80100) {
            $this->internal_stubs[] = $stubs_dir . 'Php81.phpstub';
        }
        if ($codebase->analysis_php_version_id >= 80200) {
            $this->internal_stubs[] = $stubs_dir . 'Php82.phpstub';
            $this->php_extensions['random'] = true;
            // random is a part of the PHP core starting from PHP 8.2
        }
        if ($codebase->analysis_php_version_id >= 80400) {
            $this->internal_stubs[] = $stubs_dir . 'Php84.phpstub';
        }
        if ($codebase->analysis_php_version_id >= 80500) {
            $this->internal_stubs[] = $stubs_dir . 'Php85.phpstub';
        }
        $ext_stubs_dir = $dir_lvl_2 . DIRECTORY_SEPARATOR . "stubs" . DIRECTORY_SEPARATOR . "extensions";
        foreach ($this->php_extensions as $ext => $enabled) {
            if ($enabled) {
                $this->internal_stubs[] = $ext_stubs_dir . DIRECTORY_SEPARATOR . "{$ext}.phpstub";
            }
        }
        foreach ($this->internal_stubs as $stub_path) {
            if (!file_exists($stub_path)) {
                throw new UnexpectedValueException('Cannot locate ' . $stub_path);
            }
        }
        $stub_files = array_merge($this->internal_stubs, $this->stub_files);
        $phpstorm_meta_path = $this->base_dir . DIRECTORY_SEPARATOR . '.phpstorm.meta.php';
        if ($this->use_phpstorm_meta_path) {
            if (is_file($phpstorm_meta_path)) {
                $stub_files[] = $phpstorm_meta_path;
            } elseif (is_dir($phpstorm_meta_path)) {
                $phpstorm_meta_path = (string) realpath($phpstorm_meta_path);
                $phpstorm_meta_files = glob($phpstorm_meta_path . '/*.meta.php', GLOB_NOSORT);
                foreach ($phpstorm_meta_files ?: [] as $glob) {
                    if (is_file($glob) && realpath(dirname($glob)) === $phpstorm_meta_path) {
                        $stub_files[] = $glob;
                    }
                }
            }
        }
        foreach ($stub_files as $file_path) {
            $file_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
            // fix mangled phar paths on Windows
            if (str_starts_with($file_path, 'phar:\\\\')) {
                $file_path = 'phar://' . substr($file_path, 7);
            }
            $codebase->scanner->add_file_to_deep_scan($file_path);
        }
        $progress->debug('Registering stub files' . "\n");
        $codebase->scan_files();
        $progress->debug('Finished registering stub files' . "\n");
        $codebase->register_stub_files = false;
    }
    public function get_cache_directory(): ?string
    {
        if ($this->cache_directory === null) {
            return null;
        }
        if ($this->cache_directory_initialized) {
            return $this->cache_directory;
        }
        $cwd = null;
        if ($this->resolve_from_config_file) {
            $cwd = getcwd();
            chdir($this->base_dir);
        }
        try {
            if (!is_dir($this->cache_directory)) {
                try {
                    if (mkdir($this->cache_directory, 0777, true) === false) {
                        // any other error than directory already exists/permissions issue
                        throw new RuntimeException('Failed to create Psalm cache directory for unknown reasons');
                    }
                } catch (RuntimeException $e) {
                    if (!is_dir($this->cache_directory)) {
                        // rethrow the error with default message
                        // it contains the reason why creation failed
                        throw $e;
                    }
                }
            }
        } finally {
            if ($cwd) {
                chdir($cwd);
            }
        }
        $this->cache_directory_initialized = true;
        return $this->cache_directory;
    }
    public function get_global_cache_directory(): ?string
    {
        return $this->global_cache_directory;
    }
    /**
     * @return array<string, mixed>
     */
    public function get_predefined_constants(): array
    {
        return $this->predefined_constants;
    }
    public function collect_predefined_constants(): void
    {
        $this->predefined_constants = get_defined_constants();
    }
    /**
     * @return array<callable-string, bool>
     */
    public function get_predefined_functions(): array
    {
        return $this->predefined_functions;
    }
    public function collect_predefined_functions(): void
    {
        $defined_functions = get_defined_functions();
        foreach ($defined_functions['user'] as $function_name) {
            $this->predefined_functions[$function_name] = true;
        }
        foreach ($defined_functions['internal'] as $function_name) {
            $this->predefined_functions[$function_name] = true;
        }
    }
    public function set_include_collector(Include_Collector $include_collector): void
    {
        $this->include_collector = $include_collector;
    }
    public function visit_composer_autoload_files(Project_Analyzer $project_analyzer, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        if (!$this->include_collector) {
            throw new LogicException("IncludeCollector should be set at this point");
        }
        $vendor_autoload_files_path = $this->base_dir . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR . 'autoload_files.php';
        if (file_exists($vendor_autoload_files_path)) {
            $this->include_collector->run_and_collect(static fn(): array => require $vendor_autoload_files_path);
        }
        $codebase = $project_analyzer->get_codebase();
        $this->collect_predefined_functions();
        if ($this->autoloader) {
            // somee classes that we think are missing may not actually be missing
            // as they might be autoloadable once we require the autoloader below
            $codebase->classlikes->forget_missing_class_likes();
            $this->include_collector->run_and_collect($this->require_autoloader(...));
        }
        $this->collect_predefined_constants();
        $autoload_included_files = $this->include_collector->get_filtered_included_files();
        if ($autoload_included_files) {
            $codebase->register_autoload_files = true;
            $progress->debug('Registering autoloaded files' . "\n");
            foreach ($autoload_included_files as $file_path) {
                $file_path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file_path);
                $progress->debug('   ' . $file_path . "\n");
                $codebase->scanner->add_file_to_deep_scan($file_path);
            }
            $codebase->scanner->scan_files($codebase->classlikes);
            $progress->debug('Finished registering autoloaded files' . "\n");
            $codebase->register_autoload_files = false;
        }
    }
    /** @return string|false */
    public function get_composer_file_path_for_class_like(string $fq_classlike_name): string|bool
    {
        if (!$this->composer_class_loader) {
            return false;
        }
        return $this->composer_class_loader->find_file($fq_classlike_name);
    }
    public function get_potential_composer_file_path_for_class_like(string $class): ?string
    {
        if (!$this->composer_class_loader) {
            return null;
        }
        $psr4_prefixes = $this->composer_class_loader->get_prefixes_psr4();
        // PSR-4 lookup
        $logical_path_psr4 = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
        $candidate_path = null;
        $max_depth = 0;
        $sub_path = $class;
        while (false !== $last_pos = strrpos($sub_path, '\\')) {
            $sub_path = substr($sub_path, 0, $last_pos);
            $search = $sub_path . '\\';
            if (isset($psr4_prefixes[$search])) {
                $depth = substr_count($search, '\\');
                $path_end = DIRECTORY_SEPARATOR . substr($logical_path_psr4, $last_pos + 1);
                foreach ($psr4_prefixes[$search] as $dir) {
                    $dir = realpath($dir);
                    if ($dir && $depth > $max_depth && $this->is_in_project_dirs($dir . DIRECTORY_SEPARATOR . 'testdummy.php')) {
                        $max_depth = $depth;
                        $candidate_path = realpath($dir) . $path_end;
                    }
                }
            }
        }
        return $candidate_path;
    }
    public static function remove_cache_directory(string $dir): void
    {
        clearstatcache(true, $dir);
        if (is_dir($dir)) {
            $objects = scandir($dir, SCANDIR_SORT_NONE);
            if ($objects === false) {
                throw new UnexpectedValueException('Not expecting false here');
            }
            foreach ($objects as $object) {
                if ($object === '.') {
                    continue;
                }
                if ($object === '..') {
                    continue;
                }
                $full_path = $dir . '/' . $object;
                // if it was deleted in the meantime/race condition with other psalm process
                clearstatcache(true, $full_path);
                if (!file_exists($full_path)) {
                    continue;
                }
                if (is_dir($full_path)) {
                    self::remove_cache_directory($full_path);
                } else {
                    $fp = fopen($full_path, 'c');
                    if ($fp === false) {
                        continue;
                    }
                    $max_wait_cycles = 5;
                    $has_lock = false;
                    while ($max_wait_cycles > 0) {
                        if (flock($fp, LOCK_EX)) {
                            $has_lock = true;
                            break;
                        }
                        $max_wait_cycles--;
                        usleep(50000);
                    }
                    try {
                        if (!$has_lock) {
                            throw new RuntimeException('Could not acquire lock for deletion of ' . $full_path);
                        }
                        unlink($full_path);
                        fclose($fp);
                    } catch (RuntimeException $e) {
                        if (is_resource($fp)) {
                            fclose($fp);
                        }
                        clearstatcache(true, $full_path);
                        if (file_exists($full_path)) {
                            // rethrow the error with default message
                            // it contains the reason why deletion failed
                            throw $e;
                        }
                    }
                }
            }
            // may have been removed in the meantime
            clearstatcache(true, $dir);
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }
    public function set_server_mode(): void
    {
        if ($this->cache_directory !== null) {
            $this->cache_directory .= '-s';
        }
    }
    public function add_stub_file(string $stub_file): void
    {
        $this->stub_files[$stub_file] = $stub_file;
    }
    public function has_stub_file(string $stub_file): bool
    {
        return isset($this->stub_files[$stub_file]);
    }
    /**
     * @return array<string, string>
     */
    public function get_stub_files(): array
    {
        return $this->stub_files;
    }
    public function add_preloaded_stub_file(string $stub_file): void
    {
        $this->preloaded_stub_files[$stub_file] = $stub_file;
    }
    public function get_php_version(): ?string
    {
        return $this->get_php_version_from_config() ?? $this->get_php_version_from_composer_json();
    }
    public function get_php_version_from_config(): ?string
    {
        return $this->configured_php_version;
    }
    private function set_boolean_attribute(string $name, bool $value): void
    {
        $this->{$name} = $value;
    }
    /**
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function get_php_version_from_composer_json(): ?string
    {
        $composer_json_path = Composer::get_json_file_path($this->base_dir);
        if (file_exists($composer_json_path)) {
            try {
                $composer_json_contents = file_get_contents($composer_json_path);
                assert($composer_json_contents !== false);
                $composer_json = json_decode($composer_json_contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (Json_Exception) {
                $composer_json = null;
            }
            if (!$composer_json) {
                throw new UnexpectedValueException('Invalid composer.json at ' . $composer_json_path);
            }
            $php_version = $composer_json['require']['php'] ?? null;
            if (is_string($php_version)) {
                $version_parser = new Version_Parser();
                $constraint = $version_parser->parse_constraints($php_version);
                $php_versions = ['5.4', '5.5', '5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
                foreach ($php_versions as $candidate) {
                    if ($constraint->matches(new Constraint('<=', "{$candidate}.0.0-dev")) || $constraint->matches(new Constraint('<=', "{$candidate}.999"))) {
                        return $candidate;
                    }
                }
            }
        }
        return null;
    }
    public function add_universal_object_crate(string $class): void
    {
        if (!class_exists($class)) {
            throw new UnexpectedValueException($class . ' is not a known class');
        }
        $this->universal_object_crates[] = strtolower($class);
    }
    /**
     * @return array<int, lowercase-string>
     */
    public function get_universal_object_crates(): array
    {
        return $this->universal_object_crates;
    }
    /** @internal */
    public function get_cache_serializer(): Serializer
    {
        $s = $this->use_igbinary ? new Igbinary_Serializer() : new Native_Serializer();
        return match ($this->compressor) {
            'deflate' => new Gzip_Serializer($s),
            'lz4' => new Lz4Serializer($s),
            'off' => $s,
        };
    }
    /** @internal */
    public function require_autoloader(): void
    {
        /** @psalm-suppress UnresolvableInclude */
        require $this->autoloader;
    }
}