<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Codebase_Populated_Event;
interface After_Codebase_Populated_Interface
{
    /**
     * Called after codebase has been populated
     *
     * @return void
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint
     */
    public static function after_codebase_populated(After_Codebase_Populated_Event $event);
}