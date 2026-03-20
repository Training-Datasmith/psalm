<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Return_Type_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Return_Type_Provider_Interface;
use Psalm\Type;
use Psalm\Type\Atomic\T_Bool;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use function count;
/**
 * @internal
 */
final class Version_Compare_Return_Type_Provider implements Function_Return_Type_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['version_compare'];
    }
    #[Override]
    public static function get_function_return_type(Function_Return_Type_Provider_Event $event): Union
    {
        $statements_source = $event->get_statements_source();
        $call_args = $event->get_call_args();
        if (!$statements_source instanceof Statements_Analyzer) {
            return Type::get_mixed();
        }
        if (count($call_args) > 2) {
            $operator_type = $statements_source->node_data->get_type($call_args[2]->value);
            if ($operator_type) {
                if (!$operator_type->has_mixed()) {
                    $acceptable_operator_type = new Union([Type::get_atomic_string_from_literal('<'), Type::get_atomic_string_from_literal('lt'), Type::get_atomic_string_from_literal('<='), Type::get_atomic_string_from_literal('le'), Type::get_atomic_string_from_literal('>'), Type::get_atomic_string_from_literal('gt'), Type::get_atomic_string_from_literal('>='), Type::get_atomic_string_from_literal('ge'), Type::get_atomic_string_from_literal('=='), Type::get_atomic_string_from_literal('='), Type::get_atomic_string_from_literal('eq'), Type::get_atomic_string_from_literal('!='), Type::get_atomic_string_from_literal('<>'), Type::get_atomic_string_from_literal('ne')]);
                    $codebase = $statements_source->get_codebase();
                    if (Union_Type_Comparator::is_contained_by($codebase, $operator_type, $acceptable_operator_type)) {
                        return Type::get_bool();
                    }
                }
            }
            return new Union([new T_Bool(), new T_Null()]);
        }
        return new Union([new T_Literal_Int(-1), new T_Literal_Int(0), new T_Literal_Int(1)]);
    }
}