<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Before_Statement_Analysis_Event;
interface Before_Statement_Analysis_Interface
{
    /**
     * Called before a statement has been checked
     *
     * @return null|false Whether to continue
     *  + `null` continues with next event handler
     *  + `false` stops analyzing current statement in StatementsAnalyzer
     */
    public static function before_statement_analysis(Before_Statement_Analysis_Event $event): ?bool;
}