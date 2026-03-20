<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Method_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Method_Params_Provider_Interface;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
/**
 * @internal
 */
final class Pdo_Statement_Set_Fetch_Mode implements Method_Params_Provider_Interface
{
    #[Override]
    public static function get_class_like_names(): array
    {
        return ['PDOStatement'];
    }
    /**
     * @return ?array<int, FunctionLikeParameter>
     */
    #[Override]
    public static function get_method_params(Method_Params_Provider_Event $event): ?array
    {
        $statements_source = $event->get_statements_source();
        $method_name_lowercase = $event->get_method_name_lowercase();
        $context = $event->get_context();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return null;
        }
        if ($method_name_lowercase === 'setfetchmode') {
            if (!$context || !$call_args || Expression_Analyzer::analyze($statements_source, $call_args[0]->value, $context) === false) {
                return null;
            }
            if (($first_call_arg_type = $statements_source->node_data->get_type($call_args[0]->value)) && $first_call_arg_type->is_single_int_literal()) {
                $params = [new Function_Like_Parameter('mode', false, Type::get_int(), Type::get_int(), null, null, false)];
                $value = $first_call_arg_type->get_single_int_literal()->value;
                switch ($value) {
                    case 7:
                        // PDO::FETCH_COLUMN
                        $params[] = new Function_Like_Parameter('colno', false, Type::get_int(), Type::get_int(), null, null, false);
                        break;
                    case 8:
                        // PDO::FETCH_CLASS
                        $params[] = new Function_Like_Parameter('classname', false, Type::get_class_string(), Type::get_class_string(), null, null, false);
                        $params[] = new Function_Like_Parameter('ctorargs', false, Type::get_array(), Type::get_array(), null, null, true);
                        break;
                    case 9:
                        // PDO::FETCH_INTO
                        $params[] = new Function_Like_Parameter('object', false, Type::get_object(), Type::get_object(), null, null, false);
                        break;
                }
                return $params;
            }
        }
        return null;
    }
}