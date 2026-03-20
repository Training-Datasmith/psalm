<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Type\Union;
interface Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    public static function get_function_ids(): array;
    /**
     * Use this hook for providing custom return type logic. If this plugin does not know what a function should
     * return but another plugin may be able to determine the type, return null. Otherwise return a mixed union type
     * if something should be returned, but can't be more specific.
     */
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): ?Union;
}