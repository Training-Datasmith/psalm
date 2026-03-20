<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Union;
use function assert;
use function in_array;
/**
 * @internal
 */
final class Imagick_Pixel_Color_Return_Type_Provider implements Method_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['imagickpixel'];
    }
    #[Override]
    public static function get_method_return_type(Method_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_source();
        $call_args = $event->get_call_args();
        $method_name_lowercase = $event->get_method_name_lowercase();
        if ($method_name_lowercase !== 'getcolor') {
            return null;
        }
        if (!$source instanceof Statements_Analyzer) {
            return null;
        }
        if (!$call_args) {
            $formats = [0 => true];
        } else {
            $normalized = $source->node_data->get_type($call_args[0]->value) ?? Type::get_mixed();
            $formats = [];
            foreach ($normalized->get_atomic_types() as $t) {
                if ($t instanceof T_Literal_Int && in_array($t->value, [0, 1, 2], true)) {
                    $formats[$t->value] = true;
                } else {
                    $formats[0] = true;
                    $formats[1] = true;
                    $formats[2] = true;
                }
            }
        }
        $types = [];
        if (isset($formats[0])) {
            $types[] = new Union([new T_Keyed_Array(['r' => Type::get_int_range(0, 255), 'g' => Type::get_int_range(0, 255), 'b' => Type::get_int_range(0, 255), 'a' => Type::get_int_range(0, 1)])]);
        }
        if (isset($formats[1])) {
            $types[] = new Union([new T_Keyed_Array(['r' => Type::get_float(), 'g' => Type::get_float(), 'b' => Type::get_float(), 'a' => Type::get_float()])]);
        }
        if (isset($formats[2])) {
            $types[] = new Union([new T_Keyed_Array(['r' => Type::get_int_range(0, 255), 'g' => Type::get_int_range(0, 255), 'b' => Type::get_int_range(0, 255), 'a' => Type::get_int_range(0, 255)])]);
        }
        assert($types !== []);
        return Type::combine_union_type_array($types, $event->get_source()->get_codebase());
    }
}