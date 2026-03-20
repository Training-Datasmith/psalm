<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Before_File_Analysis_Event;
interface Before_File_Analysis_Interface
{
    /**
     * Called before a file has been checked
     */
    public static function before_analyze_file(Before_File_Analysis_Event $event): void;
}