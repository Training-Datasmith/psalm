<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler;

use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Visit_Event;
interface After_Class_Like_Visit_Interface
{
    /**
     * @return void
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ReturnTypeHint
     */
    public static function after_class_like_visit(After_Class_Like_Visit_Event $event);
}