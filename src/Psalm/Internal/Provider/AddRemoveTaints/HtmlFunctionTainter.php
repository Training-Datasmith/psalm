<?php

declare (strict_types=1);
namespace Psalm\Internal\Provider\Add_Remove_Taints;

use Override;
use Php_Parser;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Add_Taints_Interface;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Plugin\Event_Handler\Remove_Taints_Interface;
use Psalm\Type\Taint_Kind;
use function count;
use function strtolower;
use const ENT_QUOTES;
/**
 * @internal
 */
final class Html_Function_Tainter implements Add_Taints_Interface, Remove_Taints_Interface
{
    /**
     * Called to see what taints should be added
     *
     * @return list<string>
     */
    #[Override]
    public static function add_taints(Add_Remove_Taints_Event $event): array
    {
        $item = $event->get_expr();
        $statements_analyzer = $event->get_statements_source();
        if (!$statements_analyzer instanceof Statements_Analyzer || !$item instanceof Php_Parser\Node\Expr\Func_Call || $item->is_first_class_callable() || !$item->name instanceof Php_Parser\Node\Name || count($item->name->get_parts()) !== 1 || count($item->get_args()) === 0) {
            return [];
        }
        $function_id = strtolower($item->name->get_first());
        if ($function_id === 'html_entity_decode' || $function_id === 'htmlspecialchars_decode') {
            $second_arg = $item->get_args()[1]->value ?? null;
            if ($second_arg === null) {
                if ($statements_analyzer->get_codebase()->analysis_php_version_id >= 80100) {
                    return [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES];
                }
                return [Taint_Kind::INPUT_HTML];
            }
            $second_arg_value = $statements_analyzer->node_data->get_type($second_arg);
            if (!$second_arg_value || !$second_arg_value->is_single_int_literal()) {
                return [Taint_Kind::INPUT_HTML];
            }
            $second_arg_value = $second_arg_value->get_single_int_literal()->value;
            if (($second_arg_value & ENT_QUOTES) === ENT_QUOTES) {
                return [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES];
            }
            return [Taint_Kind::INPUT_HTML];
        }
        return [];
    }
    /**
     * Called to see what taints should be removed
     *
     * @return list<string>
     */
    #[Override]
    public static function remove_taints(Add_Remove_Taints_Event $event): array
    {
        $item = $event->get_expr();
        $statements_analyzer = $event->get_statements_source();
        if (!$statements_analyzer instanceof Statements_Analyzer || !$item instanceof Php_Parser\Node\Expr\Func_Call || $item->is_first_class_callable() || !$item->name instanceof Php_Parser\Node\Name || count($item->name->get_parts()) !== 1 || count($item->get_args()) === 0) {
            return [];
        }
        $function_id = strtolower($item->name->get_first());
        if ($function_id === 'htmlentities' || $function_id === 'htmlspecialchars') {
            $second_arg = $item->get_args()[1]->value ?? null;
            if ($second_arg === null) {
                if ($statements_analyzer->get_codebase()->analysis_php_version_id >= 80100) {
                    return [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES];
                }
                return [Taint_Kind::INPUT_HTML];
            }
            $second_arg_value = $statements_analyzer->node_data->get_type($second_arg);
            if (!$second_arg_value || !$second_arg_value->is_single_int_literal()) {
                return [Taint_Kind::INPUT_HTML];
            }
            $second_arg_value = $second_arg_value->get_single_int_literal()->value;
            if (($second_arg_value & ENT_QUOTES) === ENT_QUOTES) {
                return [Taint_Kind::INPUT_HTML, Taint_Kind::INPUT_HAS_QUOTES];
            }
            return [Taint_Kind::INPUT_HTML];
        }
        return [];
    }
}