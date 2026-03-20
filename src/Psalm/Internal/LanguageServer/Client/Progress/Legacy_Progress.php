<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Client\Progress;

use Language_Server_Protocol\Log_Message;
use Language_Server_Protocol\Message_Type;
use LogicException;
use Override;
use Psalm\Internal\Language_Server\Client_Handler;
/** @internal */
final class Legacy_Progress implements Progress_Interface
{
    private const STATUS_INACTIVE = 'inactive';
    private const STATUS_ACTIVE = 'active';
    private const STATUS_FINISHED = 'finished';
    private string $status = self::STATUS_INACTIVE;
    private ?string $title = null;
    public function __construct(private readonly Client_Handler $handler)
    {
    }
    #[Override]
    public function begin(string $title, ?string $message = null, ?int $percentage = null): void
    {
        if ($this->status === self::STATUS_ACTIVE) {
            throw new LogicException('Progress has already been started');
        }
        if ($this->status === self::STATUS_FINISHED) {
            throw new LogicException('Progress has already been finished');
        }
        $this->title = $title;
        $this->notify($message);
        $this->status = self::STATUS_ACTIVE;
    }
    #[Override]
    public function update(?string $message = null, ?int $percentage = null): void
    {
        if ($this->status === self::STATUS_FINISHED) {
            throw new LogicException('Progress has already been finished');
        }
        if ($this->status === self::STATUS_INACTIVE) {
            throw new LogicException('Progress has not been started yet');
        }
        $this->notify($message);
    }
    #[Override]
    public function end(?string $message = null): void
    {
        if ($this->status === self::STATUS_FINISHED) {
            throw new LogicException('Progress has already been finished');
        }
        if ($this->status === self::STATUS_INACTIVE) {
            throw new LogicException('Progress has not been started yet');
        }
        $this->notify($message);
        $this->status = self::STATUS_FINISHED;
    }
    private function notify(?string $message): void
    {
        $this->handler->notify('telemetry/event', new Log_Message(Message_Type::INFO, $this->title . (empty($message) ? '' : ': ' . $message)));
    }
}