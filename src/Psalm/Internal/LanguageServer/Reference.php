<?php

declare (strict_types=1);
namespace Psalm\Internal\Language_Server;

use Language_Server_Protocol\Range;
/**
 * @internal
 */
final class Reference
{
    public function __construct(public string $file_path, public string $symbol, public Range $range)
    {
    }
}