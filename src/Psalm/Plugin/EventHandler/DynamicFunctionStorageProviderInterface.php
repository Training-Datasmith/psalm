<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Dynamic_Function_Storage;
use Psalm\Plugin\Event_Handler\Event\Dynamic_Function_Storage_Provider_Event;
interface Dynamic_Function_Storage_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    public static function get_function_ids(): array;
    public static function get_function_storage(Dynamic_Function_Storage_Provider_Event $event): ?Dynamic_Function_Storage;
}