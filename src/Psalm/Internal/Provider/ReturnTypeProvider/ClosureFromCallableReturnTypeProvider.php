<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Type_Combiner;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Union;
/**
 * @internal
 */
final class Closure_From_Callable_Return_Type_Provider implements Method_Return_Type_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['Closure'];
    }
    #[Override]
    public static function get_method_return_type(Method_Return_Type_Provider_Event $event): ?Union
    {
        $source = $event->get_source();
        $method_name_lowercase = $event->get_method_name_lowercase();
        $call_args = $event->get_call_args();
        if (!$source instanceof Statements_Analyzer) {
            return null;
        }
        $type_provider = $source->get_node_type_provider();
        $codebase = $source->get_codebase();
        if ($method_name_lowercase === 'fromcallable') {
            $closure_types = [];
            if (isset($call_args[0]) && $input_type = $type_provider->get_type($call_args[0]->value)) {
                foreach ($input_type->get_atomic_types() as $atomic_type) {
                    $candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, $atomic_type, null, $source, true);
                    if ($candidate_callable) {
                        $closure_types[] = new T_Closure('Closure', $candidate_callable->params, $candidate_callable->return_type, $candidate_callable->is_pure);
                    } else {
                        return Type::get_closure();
                    }
                }
            }
            if ($closure_types) {
                return Type_Combiner::combine($closure_types, $codebase);
            }
            return Type::get_closure();
        }
        return null;
    }
}