<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
interface Add_Taints_Interface
{
    /**
     * Called to see what taints should be added
     *
     * @return list<string>
     */
    public static function add_taints(Add_Remove_Taints_Event $event): array;
}