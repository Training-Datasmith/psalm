<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Client;

use Language_Server_Protocol\Diagnostic;
use Psalm\Internal\Language_Server\Client_Handler;
use Psalm\Internal\Language_Server\Language_Server;
/**
 * Provides method handlers for all textDocument/* methods
 *
 * @internal
 */
final class Text_Document
{
    public function __construct(private readonly Client_Handler $handler, private readonly Language_Server $server)
    {
    }
    /**
     * Diagnostics notification are sent from the server to the client to signal results of validation runs.
     *
     * @param Diagnostic[] $diagnostics
     */
    public function publish_diagnostics(string $uri, array $diagnostics, ?int $version = null): void
    {
        if (!$this->server->client->client_configuration->provide_diagnostics) {
            return;
        }
        $this->server->log_debug("textDocument/publishDiagnostics");
        $this->handler->notify('textDocument/publishDiagnostics', ['uri' => $uri, 'diagnostics' => $diagnostics, 'version' => $version]);
    }
}