<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Method_Existence_Provider_Event;
interface Method_Existence_Provider_Interface
{
    /**
     * @return array<string>
     */
    public static function get_class_like_names(): array;
    /**
     * Use this hook for informing whether or not a method exists on a given object. If you know the method does
     * not exist, return false. If you aren't sure if it exists or not, return null and the default analysis will
     * continue to determine if the method actually exists.
     */
    public static function does_method_exist(Method_Existence_Provider_Event $event): ?bool;
}