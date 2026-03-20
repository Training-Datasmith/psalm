<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_File_Analysis_Event;
interface After_File_Analysis_Interface
{
    /**
     * Called after a file has been checked
     */
    public static function after_analyze_file(After_File_Analysis_Event $event): void;
}