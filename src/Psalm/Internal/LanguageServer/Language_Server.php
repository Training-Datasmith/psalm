<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Advanced_Json_Rpc\Dispatcher;
use Advanced_Json_Rpc\Error;
use Advanced_Json_Rpc\Error_Code;
use Advanced_Json_Rpc\Error_Response;
use Advanced_Json_Rpc\Request;
use Advanced_Json_Rpc\Response;
use Advanced_Json_Rpc\Success_Response;
use InvalidArgumentException;
use Json_Mapper;
use Language_Server_Protocol\Client_Capabilities;
use Language_Server_Protocol\Client_Info;
use Language_Server_Protocol\Code_Description;
use Language_Server_Protocol\Completion_Options;
use Language_Server_Protocol\Diagnostic;
use Language_Server_Protocol\Diagnostic_Severity;
use Language_Server_Protocol\Initialize_Result;
use Language_Server_Protocol\Initialize_Result_Server_Info;
use Language_Server_Protocol\Log_Message;
use Language_Server_Protocol\Message_Type;
use Language_Server_Protocol\Position;
use Language_Server_Protocol\Range;
use Language_Server_Protocol\Save_Options;
use Language_Server_Protocol\Server_Capabilities;
use Language_Server_Protocol\Signature_Help_Options;
use Language_Server_Protocol\Text_Document_Sync_Kind;
use Language_Server_Protocol\Text_Document_Sync_Options;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Error_Baseline;
use Psalm\Internal\Analyzer\Issue_Data;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Composer;
use Psalm\Internal\Language_Server\Provider\In_Memory_Project_Cache_Provider;
use Psalm\Internal\Language_Server\Server\Text_Document as ServerTextDocument;
use Psalm\Internal\Language_Server\Server\Workspace as ServerWorkspace;
use Psalm\Internal\Provider\Class_Like_Storage_Cache_Provider;
use Psalm\Internal\Provider\File_Provider;
use Psalm\Internal\Provider\File_Reference_Cache_Provider;
use Psalm\Internal\Provider\File_Storage_Cache_Provider;
use Psalm\Internal\Provider\Parser_Cache_Provider;
use Psalm\Internal\Provider\Project_Cache_Provider;
use Psalm\Internal\Provider\Providers;
use Psalm\Issue_Buffer;
use Revolt\Event_Loop;
use Throwable;
use function array_combine;
use function array_filter;
use function array_keys;
use function array_map;
use function array_reduce;
use function array_search;
use function array_shift;
use function array_splice;
use function array_unshift;
use function array_values;
use function cli_set_process_title;
use function count;
use function explode;
use function fwrite;
use function implode;
use function json_encode;
use function max;
use function parse_url;
use function rawurlencode;
use function realpath;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_server;
use function substr;
use function trim;
use function uniqid;
use function urldecode;
use const ARRAY_FILTER_USE_KEY;
use const JSON_PRETTY_PRINT;
use const STDERR;
use const STDIN;
use const STDOUT;
/**
 * @psalm-api
 * @internal
 */
final class Language_Server extends Dispatcher
{
    /**
     * Handles textDocument/* method calls
     */
    public ?Server_Text_Document $text_document = null;
    /**
     * Handles workspace/* method calls
     */
    public ?Server_Workspace $workspace = null;
    public ?Client_Info $client_info = null;
    public Language_Client $client;
    public ?Client_Capabilities $client_capabilities = null;
    public ?string $trace = null;
    /**
     * The AMP Delay token
     */
    private string $versioned_analysis_delay_token = '';
    /** @var array<string,array<string,array{o:int, s: list<string>}>> */
    private array $issue_baseline = [];
    /**
     * This should actually be a private property on `parent`
     *
     * @psalm-suppress UnusedProperty
     */
    protected Json_Mapper $mapper;
    public function __construct(protected Protocol_Reader $protocol_reader, protected Protocol_Writer $protocol_writer, protected Project_Analyzer $project_analyzer, protected Codebase $codebase, Client_Configuration $client_configuration, Progress $progress, protected Path_Mapper $path_mapper)
    {
        parent::__construct($this, '/');
        $progress->set_server($this);
        $this->protocol_reader->on('close', function (): never {
            $this->shutdown();
            $this->exit();
        });
        $this->protocol_reader->on('message', function (Message $msg): void {
            if (!$msg->body) {
                return;
            }
            // Ignore responses, this is the handler for requests and notifications
            if (Response::is_response($msg->body)) {
                return;
            }
            $result = null;
            $error = null;
            try {
                // Invoke the method handler to get a result
                $result = $this->dispatch($msg->body);
            } catch (Error $e) {
                // If a ResponseError is thrown, send it back in the Response
                $error = $e;
            } catch (Throwable $e) {
                // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                $error = new Error((string) $e, Error_Code::INTERNAL_ERROR, null, $e);
            }
            if ($error !== null) {
                $this->log_error($error->message);
            }
            // Only send a Response for a Request
            // Notifications do not send Responses
            /**
             * @psalm-suppress UndefinedPropertyFetch
             * @psalm-suppress MixedArgument
             */
            if (Request::is_request($msg->body)) {
                if ($error !== null) {
                    $response_body = new Error_Response($msg->body->id, $error);
                } else {
                    $response_body = new Success_Response($msg->body->id, $result);
                }
                $this->protocol_writer->write(new Message($response_body));
            }
        });
        $this->protocol_reader->on('readMessageGroup', static function (): void {
            //$this->verboseLog('Received message group');
            //$this->doAnalysis();
        });
        $this->client = new Language_Client($protocol_reader, $protocol_writer, $this, $client_configuration);
        $this->log_info("Psalm Language Server " . PSALM_VERSION . " has started.");
    }
    /**
     * Start the Server
     */
    public static function run(Config $config, Client_Configuration $client_configuration, string $base_dir, Path_Mapper $path_mapper, bool $in_memory = false): void
    {
        $progress = new Progress();
        if ($in_memory) {
            $providers = new Providers(new File_Provider(), new Parser_Cache_Provider($config, Composer::get_lock_file($base_dir), false), new File_Storage_Cache_Provider($config, Composer::get_lock_file($base_dir), false), new Class_Like_Storage_Cache_Provider($config, Composer::get_lock_file($base_dir), false), new File_Reference_Cache_Provider($config, Composer::get_lock_file($base_dir), false), new In_Memory_Project_Cache_Provider());
        } else {
            $providers = new Providers(new File_Provider(), new Parser_Cache_Provider($config, Composer::get_lock_file($base_dir)), new File_Storage_Cache_Provider($config, Composer::get_lock_file($base_dir)), new Class_Like_Storage_Cache_Provider($config, Composer::get_lock_file($base_dir)), new File_Reference_Cache_Provider($config, Composer::get_lock_file($base_dir)), new Project_Cache_Provider());
        }
        $codebase = new Codebase($config, $providers, $progress);
        $codebase->language_server = true;
        if ($config->find_unused_variables) {
            $codebase->report_unused_variables();
        }
        if ($client_configuration->find_unused_code) {
            $codebase->report_unused_code($client_configuration->find_unused_code);
        }
        $project_analyzer = new Project_Analyzer($config, $providers, null, [], 1, 1, $progress, $codebase);
        if ($client_configuration->onchange_line_limit) {
            $project_analyzer->onchange_line_limit = $client_configuration->onchange_line_limit;
        }
        //Setup Project Analyzer
        $project_analyzer->provide_completion = (bool) $client_configuration->provide_completion;
        @cli_set_process_title('Psalm ' . PSALM_VERSION . ' - PHP Language Server');
        if (!$client_configuration->tcp_server_mode && $client_configuration->tcp_server_address) {
            // Connect to a TCP server
            $socket = stream_socket_client('tcp://' . $client_configuration->tcp_server_address, $errno, $errstr);
            if ($socket === false) {
                fwrite(STDERR, "Could not connect to language client. Error {$errno}\n{$errstr}");
                exit(1);
            }
            stream_set_blocking($socket, false);
            new self(new Protocol_Stream_Reader($socket), new Protocol_Stream_Writer($socket), $project_analyzer, $codebase, $client_configuration, $progress, $path_mapper);
            Event_Loop::run();
        } elseif ($client_configuration->tcp_server_mode && $client_configuration->tcp_server_address) {
            // Run a TCP Server
            $tcp_server = stream_socket_server('tcp://' . $client_configuration->tcp_server_address, $errno, $errstr);
            if ($tcp_server === false) {
                fwrite(STDERR, "Could not listen on {$client_configuration->tcp_server_address}. Error {$errno}\n{$errstr}");
                exit(1);
            }
            fwrite(STDOUT, "Server listening on {$client_configuration->tcp_server_address}\n");
            while ($socket = stream_socket_accept($tcp_server, -1)) {
                fwrite(STDOUT, "Connection accepted\n");
                stream_set_blocking($socket, false);
                //we only accept one connection.
                //An exit notification will terminate the server
                new Language_Server(new Protocol_Stream_Reader($socket), new Protocol_Stream_Writer($socket), $project_analyzer, $codebase, $client_configuration, $progress, $path_mapper);
                Event_Loop::run();
            }
        } else {
            // Use STDIO
            stream_set_blocking(STDIN, false);
            new Language_Server(new Protocol_Stream_Reader(STDIN), new Protocol_Stream_Writer(STDOUT), $project_analyzer, $codebase, $client_configuration, $progress, $path_mapper);
            Event_Loop::run();
        }
    }
    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * Is null if the process has not been started by another process. If the parent process is
     * not alive then the server should exit (see exit notification) its process.
     * @param ClientInfo|null $clientInfo Information about the client
     * @param string|null $trace The initial trace setting. If omitted trace is disabled ('off').
     * @param string|null $workDoneToken The token to be used to report progress during init.
     * @psalm-return InitializeResult
     */
    public function initialize(Client_Capabilities $capabilities, ?Client_Info $client_info = null, ?string $root_uri = null, ?string $trace = null, ?string $work_done_token = null): Initialize_Result
    {
        $this->client_info = $client_info;
        $this->client_capabilities = $capabilities;
        $this->trace = $trace;
        if ($root_uri !== null) {
            $this->path_mapper->configure_client_root($this->get_path_part($root_uri));
        }
        $progress = $this->client->make_progress($work_done_token ?? uniqid('tkn', true));
        $this->log_info("Initializing...");
        $progress->begin('Psalm', 'initializing');
        $this->project_analyzer->server_mode($this);
        $this->log_info("Initializing: Getting code base...");
        $progress->update('getting code base');
        $this->log_info("Initializing: Scanning files ({$this->project_analyzer->scan_threads} Threads)...");
        $progress->update('scanning files');
        $this->codebase->scan_files($this->project_analyzer->scan_threads);
        $this->log_info("Initializing: Registering stub files...");
        $progress->update('registering stub files');
        $this->codebase->config->visit_stub_files($this->codebase, $this->project_analyzer->progress);
        if ($this->text_document === null) {
            $this->text_document = new Server_Text_Document($this, $this->codebase, $this->project_analyzer);
        }
        if ($this->workspace === null) {
            $this->workspace = new Server_Workspace($this, $this->codebase, $this->project_analyzer);
        }
        $server_capabilities = new Server_Capabilities();
        $text_document_sync_options = new Text_Document_Sync_Options();
        //Open and close notifications are sent to the server.
        $text_document_sync_options->open_close = true;
        $save_options = new Save_Options();
        //The client is supposed to include the content on save.
        $save_options->include_text = true;
        $text_document_sync_options->save = $save_options;
        /**
         * Change notifications are sent to the server. See
         * TextDocumentSyncKind.None, TextDocumentSyncKind.Full and
         * TextDocumentSyncKind.Incremental. If omitted it defaults to
         * TextDocumentSyncKind.None.
         */
        if ($this->project_analyzer->onchange_line_limit === 0) {
            /**
             * Documents should not be synced at all.
             */
            $text_document_sync_options->change = Text_Document_Sync_Kind::NONE;
        } else {
            /**
             * Documents are synced by always sending the full content
             * of the document.
             */
            $text_document_sync_options->change = Text_Document_Sync_Kind::FULL;
        }
        /**
         * Defines how text documents are synced. Is either a detailed structure
         * defining each notification or for backwards compatibility the
         * TextDocumentSyncKind number. If omitted it defaults to
         * `TextDocumentSyncKind.None`.
         */
        $server_capabilities->text_document_sync = $text_document_sync_options;
        /**
         * The server provides document symbol support.
         * Support "Find all symbols"
         */
        $server_capabilities->document_symbol_provider = false;
        /**
         * The server provides workspace symbol support.
         * Support "Find all symbols in workspace"
         */
        $server_capabilities->workspace_symbol_provider = false;
        /**
         * The server provides goto definition support.
         * Support "Go to definition"
         */
        $server_capabilities->definition_provider = true;
        /**
         * The server provides find references support.
         * Support "Find all references"
         */
        $server_capabilities->references_provider = false;
        /**
         * The server provides hover support.
         * Support "Hover"
         */
        $server_capabilities->hover_provider = true;
        /**
         * The server provides completion support.
         * Support "Completion"
         */
        if ($this->project_analyzer->provide_completion) {
            $server_capabilities->completion_provider = new Completion_Options();
            /**
             * The server provides support to resolve additional
             * information for a completion item.
             */
            $server_capabilities->completion_provider->resolve_provider = false;
            /**
             * Most tools trigger completion request automatically without explicitly
             * requesting it using a keyboard shortcut (e.g. Ctrl+Space). Typically they
             * do so when the user starts to type an identifier. For example if the user
             * types `c` in a JavaScript file code complete will automatically pop up
             * present `console` besides others as a completion item. Characters that
             * make up identifiers don't need to be listed here.
             *
             * If code complete should automatically be trigger on characters not being
             * valid inside an identifier (for example `.` in JavaScript) list them in
             * `triggerCharacters`.
             */
            $server_capabilities->completion_provider->trigger_characters = ['$', '>', ':', "[", "(", ",", " "];
        }
        /**
         * The server provides document symbol support.
         * Support "Find all symbols"
         */
        $server_capabilities->document_symbol_provider = false;
        /**
         * The server provides workspace symbol support.
         * Support "Find all symbols in workspace"
         */
        $server_capabilities->workspace_symbol_provider = false;
        /**
         * The server provides goto definition support.
         * Support "Go to definition"
         */
        $server_capabilities->definition_provider = true;
        /**
         * The server provides find references support.
         * Support "Find all references"
         */
        $server_capabilities->references_provider = false;
        /**
         * The server provides hover support.
         * Support "Hover"
         */
        $server_capabilities->hover_provider = true;
        /**
         * The server does not support documentHighlight-ing
         * Ref: https://github.com/vimeo/psalm/issues/10397
         */
        $server_capabilities->document_highlight_provider = false;
        /**
         * Whether code action supports the `data` property which is
         * preserved between a `textDocument/codeAction` and a
         * `codeAction/resolve` request.
         *
         * Support "Code Actions" if we support data
         *
         * @since LSP 3.16.0
         */
        if ($this->client_capabilities->text_document->publish_diagnostics->data_support ?? false) {
            $server_capabilities->code_action_provider = true;
        }
        /**
         * The server provides signature help support.
         */
        $server_capabilities->signature_help_provider = new Signature_Help_Options(['(', ',']);
        if ($this->client->client_configuration->baseline !== null) {
            $this->log_info('Utilizing Baseline: ' . $this->client->client_configuration->baseline);
            $this->issue_baseline = Error_Baseline::read(new File_Provider(), $this->client->client_configuration->baseline);
        }
        $this->log_info("Initializing: Complete.");
        $progress->end('initialized');
        /**
         * Information about the server.
         *
         * @since LSP 3.15.0
         */
        $initialize_result_server_info = new Initialize_Result_Server_Info('Psalm Language Server', PSALM_VERSION);
        return new Initialize_Result($server_capabilities, $initialize_result_server_info);
    }
    /**
     * The initialized notification is sent from the client to the server after the client received the result of the
     * initialize request but before the client is sending any other request or notification to the server.
     * The server can use the initialized notification for example to dynamically register capabilities.
     * The initialized notification may only be sent once.
     */
    public function initialized(): void
    {
        try {
            $this->client->refresh_configuration();
        } catch (Throwable $e) {
            $this->log_error((string) $e);
        }
        $this->client_status('running');
    }
    /**
     * Queue Change File Analysis
     */
    public function queue_change_file_analysis(string $file_path, string $uri, ?int $version = null): void
    {
        $this->do_versioned_analysis_on_change_debounce([$file_path => $uri], $version);
    }
    /**
     * Queue Open File Analysis
     */
    public function queue_open_file_analysis(string $file_path, string $uri, ?int $version = null): void
    {
        $this->do_versioned_analysis_on_open_debounce([$file_path => $uri], $version);
    }
    /**
     * Queue Closed File Analysis
     */
    public function queue_closed_file_analysis(string $file_path, string $uri): void
    {
        $this->do_versioned_analysis([$file_path => $uri]);
    }
    /**
     * Queue Saved File Analysis
     */
    public function queue_save_file_analysis(string $file_path, string $uri): void
    {
        $this->queue_file_analysis_with_opened_files([$file_path => $uri]);
    }
    /**
     * Queue File Analysis appending any opened files
     *
     * This allows for reanalysis of files that have been opened
     *
     * @param array<string, string> $files
     */
    public function queue_file_analysis_with_opened_files(array $files = []): void
    {
        /** @var array<string, string> $opened */
        $opened = array_reduce($this->project_analyzer->get_codebase()->file_provider->get_open_files_path(), function (array $opened, string $file_path): array {
            $opened[$file_path] = $this->path_to_uri($file_path);
            return $opened;
        }, $files);
        $this->do_versioned_analysis($opened);
    }
    /**
     * Debounced Queue File Analysis with optional version for onChange events
     *
     * @param array<string, string> $files
     */
    public function do_versioned_analysis_on_change_debounce(array $files, ?int $version = null): void
    {
        Event_Loop::cancel($this->versioned_analysis_delay_token);
        if ($this->client->client_configuration->on_change_debounce_ms === null) {
            $this->do_versioned_analysis($files, $version);
        } else {
            /** @psalm-suppress MixedAssignment,UnusedPsalmSuppress */
            $this->versioned_analysis_delay_token = Event_Loop::delay($this->client->client_configuration->on_change_debounce_ms / 1000, fn() => $this->do_versioned_analysis($files, $version));
        }
    }
    /**
     * Debounced Queue File Analysis with optional version for onOpen events
     *
     * @param array<string, string> $files
     */
    public function do_versioned_analysis_on_open_debounce(array $files, ?int $version = null): void
    {
        if ($this->client->client_configuration->on_open_debounce_ms === null) {
            $this->do_versioned_analysis($files, $version);
        } else {
            Event_Loop::delay($this->client->client_configuration->on_open_debounce_ms / 1000, function () use ($files, $version): void {
                $files = array_filter($files, $this->project_analyzer->get_codebase()->file_provider->is_open(...), ARRAY_FILTER_USE_KEY);
                $this->do_versioned_analysis($files, $version);
            });
        }
    }
    /**
     * Queue File Analysis with optional version
     *
     * @param array<string, string> $files
     */
    public function do_versioned_analysis(array $files, ?int $version = null): void
    {
        Event_Loop::cancel($this->versioned_analysis_delay_token);
        try {
            $this->log_debug("Doing Analysis from version: {$version}");
            $this->codebase->reload_files($this->project_analyzer, array_keys($files));
            $this->codebase->analyzer->add_files_to_analyze(array_combine(array_keys($files), array_keys($files)));
            $this->log_debug("Reloading Files");
            $this->codebase->analyzer->analyze_files($this->project_analyzer, 1, false);
            $this->emit_versioned_issues($files, $version);
        } catch (Throwable $e) {
            $this->log_error((string) $e);
        }
    }
    /**
     * Emit Publish Diagnostics
     *
     * @param array<string, string> $files
     */
    public function emit_versioned_issues(array $files, ?int $version = null): void
    {
        $this->log_debug("Perform Analysis", ['files' => array_keys($files), 'version' => $version]);
        //Copy variable here to be able to process it
        $issue_baseline = $this->issue_baseline;
        $data = Issue_Buffer::clear();
        foreach ($files as $file_path => $uri) {
            //Dont report errors in files we are not watching
            if (!$this->project_analyzer->get_codebase()->config->is_in_project_dirs($file_path)) {
                continue;
            }
            $diagnostics = array_map(function (Issue_Data $issue_data): Diagnostic {
                //$check_name = $issue->check_name;
                $description = '[' . $issue_data->type . '] ' . $issue_data->message;
                $severity = $issue_data->severity;
                $start_line = max($issue_data->line_from, 1);
                $end_line = $issue_data->line_to;
                $start_column = $issue_data->column_from;
                $end_column = $issue_data->column_to;
                // Language server has 0 based lines and columns, phan has 1-based lines and columns.
                $range = new Range(new Position($start_line - 1, $start_column - 1), new Position($end_line - 1, $end_column - 1));
                $diagnostic_severity = match ($severity) {
                    Issue_Data::SEVERITY_INFO => Diagnostic_Severity::WARNING,
                    default => Diagnostic_Severity::ERROR,
                };
                $diagnostic = new Diagnostic($description, $range, null, $diagnostic_severity, 'psalm');
                $diagnostic->data = ['type' => $issue_data->type, 'snippet' => $issue_data->snippet, 'line_from' => $issue_data->line_from, 'line_to' => $issue_data->line_to];
                $diagnostic->code = $issue_data->shortcode;
                /**
                 * Client supports a codeDescription property
                 *
                 * @since LSP 3.16.0
                 */
                if ($this->client_capabilities->text_document->publish_diagnostics->code_description_support ?? false) {
                    $diagnostic->code_description = new Code_Description($issue_data->link);
                }
                return $diagnostic;
            }, array_filter(array_map(static function (Issue_Data $issue_data) use (&$issue_baseline): \Psalm\Internal\Analyzer\Issue_Data {
                if (empty($issue_baseline)) {
                    return $issue_data;
                }
                //Process Baseline
                $file = $issue_data->file_name;
                $type = $issue_data->type;
                if (isset($issue_baseline[$file][$type]) && $issue_baseline[$file][$type]['o'] > 0) {
                    if ($issue_baseline[$file][$type]['o'] === count($issue_baseline[$file][$type]['s'])) {
                        $position = array_search(str_replace("\r\n", "\n", trim($issue_data->selected_text)), $issue_baseline[$file][$type]['s'], true);
                        if ($position !== false) {
                            $issue_data->severity = Issue_Data::SEVERITY_INFO;
                            array_splice($issue_baseline[$file][$type]['s'], $position, 1);
                            $issue_baseline[$file][$type]['o']--;
                        }
                    } else {
                        $issue_baseline[$file][$type]['s'] = [];
                        $issue_data->severity = Issue_Data::SEVERITY_INFO;
                        $issue_baseline[$file][$type]['o']--;
                    }
                }
                return $issue_data;
            }, $data[$file_path] ?? []), function (Issue_Data $issue_data): bool {
                //Hide Warnings
                if ($issue_data->severity === Issue_Data::SEVERITY_INFO && $this->client->client_configuration->hide_warnings) {
                    return false;
                }
                return true;
            }));
            $this->client->text_document->publish_diagnostics($uri, array_values($diagnostics), $version);
        }
    }
    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification
     * that asks the server to exit. Clients must not send any notifications other than exit or requests to a server to
     * which they have sent a shutdown request. Clients should also wait with sending the exit notification until they
     * have received a response from the shutdown request.
     */
    public function shutdown(): void
    {
        $this->client_status('closing');
        $this->log_info("Shutting down...");
        $codebase = $this->project_analyzer->get_codebase();
        $scanned_files = $codebase->scanner->get_scanned_files();
        $codebase->file_reference_provider->update_reference_cache($codebase, $scanned_files);
        $this->client_status('closed');
    }
    /**
     * A notification to ask the server to exit its process.
     * The server should exit with success code 0 if the shutdown request has been received before;
     * otherwise with error code 1.
     */
    public function exit(): never
    {
        exit(0);
    }
    /**
     * Send log message to the client
     *
     * @psalm-param 1|2|3|4 $type
     * @param int $type The log type:
     *  - 1 = Error
     *  - 2 = Warning
     *  - 3 = Info
     *  - 4 = Log
     * @see MessageType
     * @param string  $message The log message to send to the client.
     * @param mixed[] $context The log context
     */
    public function log(int $type, string $message, array $context = []): void
    {
        $log_level = $this->client->client_configuration->log_level;
        if ($log_level === null) {
            return;
        }
        if ($type > $log_level) {
            return;
        }
        if (!empty($context)) {
            $message .= "\n" . json_encode($context, JSON_PRETTY_PRINT);
        }
        try {
            $this->client->log_message(new Log_Message($type, $message));
        } catch (Throwable) {
            // do nothing as we could potentially go into a loop here is not careful
            //TODO: Investigate if we can use error_log instead
        }
    }
    /**
     * Log Throwable Error
     */
    public function log_throwable(Throwable $throwable): void
    {
        $this->log(Message_Type::ERROR, (string) $throwable);
    }
    /**
     * Log Error message to the client
     */
    public function log_error(string $message, array $context = []): void
    {
        $this->log(Message_Type::ERROR, $message, $context);
    }
    /**
     * Log Warning message to the client
     */
    public function log_warning(string $message, array $context = []): void
    {
        $this->log(Message_Type::WARNING, $message, $context);
    }
    /**
     * Log Info message to the client
     */
    public function log_info(string $message, array $context = []): void
    {
        $this->log(Message_Type::INFO, $message, $context);
    }
    /**
     * Log Debug message to the client
     */
    public function log_debug(string $message, array $context = []): void
    {
        $this->log(Message_Type::LOG, $message, $context);
    }
    /**
     * Send status message to client. This is the same as sending a log message,
     * except this is meant for parsing by the client to present status updates in a UI.
     *
     * @param string $status The log message to send to the client. Should not contain colons `:`.
     * @param string|null $additional_info This is additional info that the client
     *                                       can use as part of the display message.
     */
    private function client_status(string $status, ?string $additional_info = null): void
    {
        try {
            $this->client->event(new Log_Message(Message_Type::INFO, $status . (!empty($additional_info) ? ': ' . $additional_info : '')));
        } catch (Throwable) {
            // do nothing
        }
    }
    /**
     * Transforms an absolute file path into a URI as used by the language server protocol.
     */
    public function path_to_uri(string $filepath): string
    {
        $filepath = str_replace('\\', '/', $filepath);
        $filepath = $this->path_mapper->map_server_to_client($oldpath = $filepath);
        $this->log_debug('Translated path to URI', ['from' => $oldpath, 'to' => $filepath]);
        $filepath = trim($filepath, '/');
        $parts = explode('/', $filepath);
        // Don't %-encode the colon after a Windows drive letter
        $first = array_shift($parts);
        if (!str_ends_with($first, ':')) {
            $first = rawurlencode($first);
        }
        $parts = array_map(rawurlencode(...), $parts);
        array_unshift($parts, $first);
        $filepath = implode('/', $parts);
        return 'file:///' . $filepath;
    }
    /**
     * Transforms URI into file path
     */
    public function uri_to_path(string $uri): string
    {
        $filepath = urldecode($this->get_path_part($uri));
        if (str_contains($filepath, ':')) {
            if ($filepath[0] === '/') {
                $filepath = substr($filepath, 1);
            }
            $filepath = str_replace('/', '\\', $filepath);
        }
        $filepath = $this->path_mapper->map_client_to_server($oldpath = $filepath);
        $this->log_debug('Translated URI to path', ['from' => $oldpath, 'to' => $filepath]);
        $realpath = realpath($filepath);
        if ($realpath !== false) {
            return $realpath;
        }
        return $filepath;
    }
    private function get_path_part(string $uri): string
    {
        $fragments = parse_url($uri);
        if ($fragments === false || !isset($fragments['scheme']) || $fragments['scheme'] !== 'file' || !isset($fragments['path'])) {
            throw new InvalidArgumentException("Not a valid file URI: {$uri}");
        }
        return $fragments['path'];
    }
    // the methods below forward special paths
    // like `$/cancelRequest` to `$this->cancelRequest()`
    // and `$/a/b/c` to `$this->a->b->c()`
    public function __isset(string $prop_name): bool
    {
        return $prop_name === '$';
    }
    public function __get(string $_prop_name): self
    {
        return $this;
    }
}