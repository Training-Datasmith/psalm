<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Before_Expression_Analysis_Event;
interface Before_Expression_Analysis_Interface
{
    /**
     * Called before an expression is checked
     */
    public static function before_expression_analysis(Before_Expression_Analysis_Event $event): ?bool;
}