<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Existence_Check_Event;
interface After_Class_Like_Existence_Check_Interface
{
    public static function after_class_like_existence_check(After_Class_Like_Existence_Check_Event $event): void;
}