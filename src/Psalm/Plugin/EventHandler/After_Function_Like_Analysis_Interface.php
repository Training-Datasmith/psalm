<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Function_Like_Analysis_Event;
interface After_Function_Like_Analysis_Interface
{
    /**
     * Called after a statement has been checked
     *
     * @return null|false
     */
    public static function after_statement_analysis(After_Function_Like_Analysis_Event $event): ?bool;
}