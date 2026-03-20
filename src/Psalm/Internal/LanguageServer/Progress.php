<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Override;
use Psalm\Progress\Progress as Base;
use function str_replace;
/**
 * @internal
 */
final class Progress extends Base
{
    private ?Language_Server $server = null;
    public function set_server(Language_Server $server): void
    {
        $this->server = $server;
    }
    #[Override]
    public function debug(string $message): void
    {
        if ($this->server) {
            $this->server->log_debug(str_replace("\n", "", $message));
        }
    }
    #[Override]
    public function write(string $message): void
    {
        if ($this->server) {
            $this->server->log_info(str_replace("\n", "", $message));
        }
    }
}