<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Every_Function_Call_Analysis_Event;
interface After_Every_Function_Call_Analysis_Interface
{
    public static function after_every_function_call_analysis(After_Every_Function_Call_Analysis_Event $event): void;
}