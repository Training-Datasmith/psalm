<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Client;

use Psalm\Internal\Language_Server\Client_Handler;
use Psalm\Internal\Language_Server\Language_Server;
/**
 * Provides method handlers for all textDocument/* methods
 *
 * @internal
 */
final class Workspace
{
    public function __construct(private readonly Client_Handler $handler, private readonly Language_Server $server)
    {
    }
    /**
     * The workspace/configuration request is sent from the server to the client to
     * fetch configuration settings from the client. The request can fetch several
     * configuration settings in one roundtrip. The order of the returned configuration
     * settings correspond to the order of the passed ConfigurationItems (e.g. the first
     * item in the response is the result for the first configuration item in the params).
     *
     * @param string $section The configuration section asked for.
     * @param string|null $scopeUri The scope to get the configuration section for.
     */
    public function request_configuration(string $section, ?string $scope_uri = null): array
    {
        $this->server->log_debug("workspace/configuration");
        /** @var array */
        return $this->handler->request('workspace/configuration', ['items' => [['section' => $section, 'scopeUri' => $scope_uri]]]);
    }
}