<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\Function_Params_Provider_Event;
use Psalm\Storage\Function_Like_Parameter;
interface Function_Params_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    public static function get_function_ids(): array;
    /**
     * @return ?array<int, FunctionLikeParameter>
     */
    public static function get_function_params(Function_Params_Provider_Event $event): ?array;
}