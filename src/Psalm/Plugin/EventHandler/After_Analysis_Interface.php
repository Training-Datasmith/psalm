<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Analysis_Event;
interface After_Analysis_Interface
{
    /**
     * Called after analysis is complete
     */
    public static function after_analysis(After_Analysis_Event $event): void;
}