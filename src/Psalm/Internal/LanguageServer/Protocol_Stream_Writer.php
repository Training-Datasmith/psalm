<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Amp\Byte_Stream\Writable_Resource_Stream;
use Override;
/**
 * @internal
 */
final class Protocol_Stream_Writer implements Protocol_Writer
{
    private readonly Writable_Resource_Stream $output;
    /**
     * @param resource $output
     */
    public function __construct($output)
    {
        $this->output = new Writable_Resource_Stream($output);
    }
    /**
     * {@inheritdoc}
     */
    #[Override]
    public function write(Message $msg): void
    {
        $this->output->write((string) $msg);
    }
}