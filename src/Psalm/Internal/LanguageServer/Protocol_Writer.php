<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

interface Protocol_Writer
{
    /**
     * Sends a Message to the client.
     */
    public function write(Message $msg): void;
}