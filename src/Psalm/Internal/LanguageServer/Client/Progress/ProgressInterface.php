<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server\Client\Progress;

/** @internal */
interface Progress_Interface
{
    public function begin(string $title, ?string $message = null, ?int $percentage = null): void;
    public function update(?string $message = null, ?int $percentage = null): void;
    public function end(?string $message = null): void;
}