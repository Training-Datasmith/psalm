<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Method_Call_Analysis_Event;
interface After_Method_Call_Analysis_Interface
{
    public static function after_method_call_analysis(After_Method_Call_Analysis_Event $event): void;
}