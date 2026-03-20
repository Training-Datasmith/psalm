<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Params_Provider;

use Override;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Plugin\Event_Handler\Event\Function_Params_Provider_Event;
use Psalm\Plugin\Event_Handler\Function_Params_Provider_Interface;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use function array_fill;
use function assert;
use function count;
use function max;
/**
 * @internal
 */
final class Array_U_Array_Params_Provider implements Function_Params_Provider_Interface
{
    /**
     * @return array<lowercase-string>
     */
    #[Override]
    public static function get_function_ids(): array
    {
        return ['array_diff_ukey', 'array_diff_uassoc', 'array_intersect_ukey', 'array_intersect_uassoc', 'array_udiff_uassoc', 'array_uintersect_uassoc', 'array_udiff', 'array_udiff_assoc', 'array_uintersect', 'array_uintersect_assoc'];
    }
    private static ?Function_Like_Parameter $arr = null;
    /**
     * @return ?list<FunctionLikeParameter>
     */
    #[Override]
    public static function get_function_params(Function_Params_Provider_Event $event): ?array
    {
        $statements_source = $event->get_statements_source();
        if (!$statements_source instanceof Statements_Analyzer) {
            // this is practically impossible
            // but the type in the caller is parent type StatementsSource
            // even though all callers provide StatementsAnalyzer
            return null;
        }
        /** @psalm-suppress PossiblyNullPropertyFetch, PossiblyNullArrayAccess */
        $cb = Internal_Call_Map_Handler::get_callables_from_call_map('array_udiff_uassoc')[0]->params;
        assert(isset($cb[2]) && isset($cb[3]));
        $val_cb = $cb[2];
        $key_cb = $cb[3];
        $arr = self::$arr ??= new Function_Like_Parameter("array", false, Type::get_array(), null, null, null, false);
        $func = $event->get_function_id();
        $call_args = $event->get_call_args();
        $array_cnt = count($call_args) - 1;
        if ($func === 'array_diff_ukey' || $func === 'array_diff_uassoc' || $func === 'array_intersect_ukey' || $func === 'array_intersect_uassoc') {
            // Key comparison
            $args = array_fill(0, max($array_cnt, 1), $arr);
            $args[] = $key_cb;
        } elseif ($func === 'array_udiff_uassoc' || $func === 'array_uintersect_uassoc') {
            // Key+value comparison
            $args = array_fill(0, max($array_cnt - 1, 1), $arr);
            $args[] = $val_cb;
            $args[] = $key_cb;
        } else {
            // Value comparison
            $array_cnt = max($array_cnt, 1);
            $args = array_fill(0, max($array_cnt, 1), $arr);
            $args[] = $val_cb;
        }
        return $args;
    }
}