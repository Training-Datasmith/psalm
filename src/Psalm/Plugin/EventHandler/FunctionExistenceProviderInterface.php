<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Function_Existence_Provider_Event;
interface Function_Existence_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    public static function get_function_ids(): array;
    /**
     * Use this hook for informing whether or not a global function exists. If you know the function does
     * not exist, return false. If you aren't sure if it exists or not, return null and the default analysis
     * will continue to determine if the function actually exists.
     */
    public static function does_function_exist(Function_Existence_Provider_Event $event): ?bool;
}