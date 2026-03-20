<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Property_Type_Provider;

use Override;
use Psalm\Plugin\Event_Handler\Event\Property_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Property_Type_Provider_Interface;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use function strtolower;
/**
 * @internal
 */
final class Dom_Document_Property_Type_Provider implements Property_Type_Provider_Interface
{
    private static ?Union $cache = null;
    #[Override]
    public static function get_property_type(Property_Type_Provider_Event $event): ?Union
    {
        if (strtolower($event->get_property_name()) === 'documentelement') {
            self::$cache ??= new Union([new T_Named_Object('DOMElement'), new T_Null()], ['ignore_nullable_issues' => true]);
            return self::$cache;
        }
        return null;
    }
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['domdocument'];
    }
}