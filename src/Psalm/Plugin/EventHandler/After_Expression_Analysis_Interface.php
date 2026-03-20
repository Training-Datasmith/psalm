<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Expression_Analysis_Event;
interface After_Expression_Analysis_Interface
{
    /**
     * Called after an expression has been checked
     *
     * @return null|false
     */
    public static function after_expression_analysis(After_Expression_Analysis_Event $event): ?bool;
}