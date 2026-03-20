<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Language_Server_Protocol\Log_Message;
use Language_Server_Protocol\Log_Trace;
use Psalm\Internal\Language_Server\Client\Progress\Legacy_Progress;
use Psalm\Internal\Language_Server\Client\Progress\Progress;
use Psalm\Internal\Language_Server\Client\Progress\Progress_Interface;
use Psalm\Internal\Language_Server\Client\Text_Document as ClientTextDocument;
use Psalm\Internal\Language_Server\Client\Workspace as ClientWorkspace;
use Revolt\Event_Loop;
use Throwable;
use function is_null;
use function json_decode;
use function json_encode;
use const JSON_THROW_ON_ERROR;
/**
 * @internal
 */
final class Language_Client
{
    /**
     * Handles textDocument/* methods
     */
    public Client_Text_Document $text_document;
    /**
     * Handles workspace/* methods
     */
    public Client_Workspace $workspace;
    /**
     * The client handler
     */
    private readonly Client_Handler $handler;
    public function __construct(
        Protocol_Reader $reader,
        Protocol_Writer $writer,
        /**
         * The Language Server
         */
        private readonly Language_Server $server,
        /**
         * The Client Configuration
         */
        public Client_Configuration $client_configuration
    )
    {
        $this->handler = new Client_Handler($reader, $writer);
        $this->text_document = new Client_Text_Document($this->handler, $this->server);
        $this->workspace = new Client_Workspace($this->handler, $this->server);
    }
    /**
     * Request Configuration from Client and save it
     */
    public function refresh_configuration(): void
    {
        $capabilities = $this->server->client_capabilities;
        if ($capabilities->workspace->configuration ?? false) {
            Event_Loop::queue(function (): void {
                try {
                    /** @var object $config */
                    [$config] = $this->workspace->request_configuration('psalm');
                    $this->configuration_refreshed((array) $config);
                } catch (Throwable $e) {
                    $this->server->log_error('There was an error getting configuration: ' . $e->get_message());
                }
            });
        }
    }
    /**
     * A notification to log the trace of the server’s execution.
     * The amount and content of these notifications depends on the current trace configuration.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function log_trace(Log_Trace $log_trace): void
    {
        //If trace is 'off', the server should not send any logTrace notification.
        if (is_null($this->server->trace) || $this->server->trace === 'off') {
            return;
        }
        //If trace is 'messages', the server should not add the 'verbose' field in the LogTraceParams.
        if ($this->server->trace === 'messages') {
            $log_trace->verbose = null;
        }
        $this->handler->notify('$/logTrace', $log_trace);
    }
    /**
     * Send a log message to the client.
     */
    public function log_message(Log_Message $log_message): void
    {
        $this->handler->notify('window/logMessage', $log_message);
    }
    /**
     * The telemetry notification is sent from the
     * server to the client to ask the client to log
     * a telemetry event.
     *
     * The protocol doesn’t specify the payload since no
     * interpretation of the data happens in the protocol.
     * Most clients even don’t handle the event directly
     * but forward them to the extensions owing the corresponding
     * server issuing the event.
     */
    public function event(Log_Message $log_message): void
    {
        $this->handler->notify('telemetry/event', $log_message);
    }
    public function make_progress(string $token): Progress_Interface
    {
        if ($this->server->client_capabilities->window->work_done_progress ?? false) {
            return new Progress($this->handler, $token);
        }
        return new Legacy_Progress($this->handler);
    }
    /**
     * Configuration Refreshed from Client
     */
    private function configuration_refreshed(array $config): void
    {
        //do things when the config is refreshed
        if (empty($config)) {
            return;
        }
        /** @var array */
        $array = json_decode(json_encode($config, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (isset($array['hideWarnings'])) {
            $this->client_configuration->hide_warnings = (bool) $array['hideWarnings'];
        }
        if (isset($array['provideCompletion'])) {
            $this->client_configuration->provide_completion = (bool) $array['provideCompletion'];
        }
        if (isset($array['provideDefinition'])) {
            $this->client_configuration->provide_definition = (bool) $array['provideDefinition'];
        }
        if (isset($array['provideHover'])) {
            $this->client_configuration->provide_hover = (bool) $array['provideHover'];
        }
        if (isset($array['provideSignatureHelp'])) {
            $this->client_configuration->provide_signature_help = (bool) $array['provideSignatureHelp'];
        }
        if (isset($array['provideCodeActions'])) {
            $this->client_configuration->provide_code_actions = (bool) $array['provideCodeActions'];
        }
        if (isset($array['provideDiagnostics'])) {
            $this->client_configuration->provide_diagnostics = (bool) $array['provideDiagnostics'];
        }
        if (isset($array['findUnusedVariables'])) {
            $this->client_configuration->find_unused_variables = (bool) $array['findUnusedVariables'];
        }
    }
}