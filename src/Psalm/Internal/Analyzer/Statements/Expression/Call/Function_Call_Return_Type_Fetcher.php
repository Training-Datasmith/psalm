<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use InvalidArgumentException;
use Php_Parser;
use Php_Parser\Builder_Factory;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Comparator\Callable_Type_Comparator;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Plugin\Event_Handler\Event\After_Function_Call_Analysis_Event;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Taint_Kind;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_merge;
use function array_values;
use function count;
use function explode;
use function in_array;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
/**
 * @internal
 */
final class Function_Call_Return_Type_Fetcher
{
    /**
     * @param non-empty-string $function_id
     */
    public static function fetch(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Func_Call $stmt, Php_Parser\Node\Name $function_name, string $function_id, bool $in_call_map, bool $is_stubbed, ?Function_Like_Storage $function_storage, ?T_Callable $callmap_callable, Template_Result $template_result, Context $context): Union
    {
        $stmt_type = null;
        $config = $codebase->config;
        if ($stmt->is_first_class_callable()) {
            $candidate_callable = Callable_Type_Comparator::get_callable_from_atomic($codebase, Type::get_atomic_string_from_literal($function_id), null, $statements_analyzer, true);
            if ($candidate_callable) {
                $stmt_type = new Union([new T_Closure('Closure', $candidate_callable->params, $candidate_callable->return_type, $candidate_callable->is_pure)]);
            } else {
                $stmt_type = Type::get_closure();
            }
        } elseif ($codebase->functions->return_type_provider->has($function_id)) {
            $stmt_type = $codebase->functions->return_type_provider->get_return_type($statements_analyzer, $function_id, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $function_name));
        }
        if (!$stmt_type) {
            if (!$in_call_map || $is_stubbed) {
                if ($function_storage && $function_storage->template_types) {
                    foreach ($function_storage->template_types as $template_name => $_) {
                        if (!isset($template_result->lower_bounds[$template_name])) {
                            if ($template_name === 'TFunctionArgCount') {
                                $template_result->lower_bounds[$template_name] = ['fn-' . $function_id => [new Template_Bound(Type::get_int(false, count($stmt->get_args())))]];
                            } elseif ($template_name === 'TPhpMajorVersion') {
                                $template_result->lower_bounds[$template_name] = ['fn-' . $function_id => [new Template_Bound(Type::get_int(false, $codebase->get_major_analysis_php_version()))]];
                            } elseif ($template_name === 'TPhpVersionId') {
                                $template_result->lower_bounds[$template_name] = ['fn-' . $function_id => [new Template_Bound(Type::get_int(false, $codebase->analysis_php_version_id))]];
                            } else {
                                $template_result->lower_bounds[$template_name] = ['fn-' . $function_id => [new Template_Bound(Type::get_never())]];
                            }
                        }
                    }
                }
                if ($function_storage && !$context->is_suppressing_exceptions($statements_analyzer)) {
                    $context->merge_function_exceptions($function_storage, new Code_Location($statements_analyzer->get_source(), $stmt));
                }
                try {
                    if ($function_storage && $function_storage->return_type) {
                        $return_type = $function_storage->return_type;
                        if ($template_result->lower_bounds && $function_storage->template_types) {
                            $return_type = Type_Expander::expand_union($codebase, $return_type, null, null, null);
                            $return_type = Template_Inferred_Type_Replacer::replace($return_type, $template_result, $codebase);
                        }
                        $return_type = Type_Expander::expand_union($codebase, $return_type, null, null, null, true, false, false, true);
                        $return_type_location = $function_storage->return_type_location;
                        $event = new After_Function_Call_Analysis_Event($stmt, $function_id, $context, $statements_analyzer->get_source(), $codebase, $return_type, []);
                        $config->event_dispatcher->dispatch_after_function_call_analysis($event);
                        $file_manipulations = $event->get_file_replacements();
                        if ($file_manipulations) {
                            File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                        }
                        $return_type = $return_type->set_by_ref($function_storage->returns_by_ref);
                        $stmt_type = $return_type;
                        // only check the type locally if it's defined externally
                        if ($return_type_location && !$is_stubbed && !$config->is_in_project_dirs($return_type_location->file_path)) {
                            /** @psalm-suppress UnusedMethodCall Actually generates issues */
                            $return_type->check($statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues(), $context->phantom_classes, true, false, false, $context->calling_method_id);
                        }
                    }
                } catch (InvalidArgumentException) {
                    // this can happen when the function was defined in the Config startup script
                    $stmt_type = Type::get_mixed();
                }
            } else {
                if (!$callmap_callable) {
                    throw new UnexpectedValueException('We should have a callmap callable here');
                }
                $stmt_type = self::get_return_type_from_call_map_with_args($statements_analyzer, $function_id, $stmt->get_args(), $callmap_callable, $context);
            }
        }
        if (!$stmt_type) {
            $stmt_type = Type::get_mixed();
        }
        if (!$statements_analyzer->data_flow_graph || !$function_storage) {
            return $stmt_type;
        }
        $return_node = self::taint_return_type($statements_analyzer, $stmt, $function_id, $function_name->to_code_string(), $function_storage, $stmt_type, $template_result, $context);
        if ($function_storage->proxy_calls !== null) {
            foreach ($function_storage->proxy_calls as $proxy_call) {
                $fake_call_arguments = [];
                foreach ($proxy_call['params'] as $i) {
                    $fake_call_arguments[] = $stmt->get_args()[$i];
                }
                $fake_call_factory = new Builder_Factory();
                if (str_contains($proxy_call['fqn'], '::')) {
                    [$fqcn, $method] = explode('::', $proxy_call['fqn']);
                    $fake_call = $fake_call_factory->static_call($fqcn, $method, $fake_call_arguments);
                } else {
                    $fake_call = $fake_call_factory->func_call($proxy_call['fqn'], $fake_call_arguments);
                }
                $old_node_data = $statements_analyzer->node_data;
                $statements_analyzer->node_data = clone $statements_analyzer->node_data;
                Expression_Analyzer::analyze($statements_analyzer, $fake_call, $context);
                $statements_analyzer->node_data = $old_node_data;
                if ($return_node && $proxy_call['return']) {
                    $fake_call_type = $statements_analyzer->node_data->get_type($fake_call);
                    if (null !== $fake_call_type) {
                        foreach ($fake_call_type->parent_nodes as $fake_call_node) {
                            $statements_analyzer->data_flow_graph->add_path($fake_call_node, $return_node, 'return');
                        }
                    }
                }
            }
        }
        return $stmt_type;
    }
    /**
     * @param  list<PhpParser\Node\Arg>   $call_args
     */
    private static function get_return_type_from_call_map_with_args(Statements_Analyzer $statements_analyzer, string $function_id, array $call_args, T_Callable $callmap_callable, Context $context): Union
    {
        $call_map_key = strtolower($function_id);
        $codebase = $statements_analyzer->get_codebase();
        if (!$call_args) {
            switch ($call_map_key) {
                case 'hrtime':
                    $keyed_array = new T_Keyed_Array([Type::get_int(), Type::get_int()], null, null, true);
                    return new Union([$keyed_array]);
                case 'get_called_class':
                    return new Union([new T_Class_String($context->self ?: 'object', $context->self ? new T_Named_Object($context->self, true) : null)]);
                case 'get_parent_class':
                    if ($context->self && $codebase->class_exists($context->self)) {
                        $classlike_storage = $codebase->classlike_storage_provider->get($context->self);
                        if ($classlike_storage->parent_classes) {
                            return new Union([new T_Class_String(array_values($classlike_storage->parent_classes)[0])]);
                        }
                    }
            }
        } else {
            switch ($call_map_key) {
                case 'count':
                case 'sizeof':
                    if ($first_arg_type = $statements_analyzer->node_data->get_type($call_args[0]->value)) {
                        $atomic_types = $first_arg_type->get_atomic_types();
                        if (count($atomic_types) === 1) {
                            if (isset($atomic_types['array'])) {
                                if ($atomic_types['array'] instanceof T_Callable_Keyed_Array) {
                                    return Type::get_int(false, 2);
                                }
                                if ($atomic_types['array'] instanceof T_Non_Empty_Array) {
                                    return new Union([$atomic_types['array']->count !== null ? new T_Literal_Int($atomic_types['array']->count) : new T_Int_Range(1, null)]);
                                }
                                if ($atomic_types['array'] instanceof T_Keyed_Array) {
                                    $min = $atomic_types['array']->get_min_count();
                                    $max = $atomic_types['array']->get_max_count();
                                    if ($min === $max) {
                                        return new Union([new T_Literal_Int($max)]);
                                    }
                                    return Type::get_int_range($min, $max);
                                }
                                if ($atomic_types['array'] instanceof T_Array && $atomic_types['array']->is_empty_array()) {
                                    return Type::get_int(false, 0);
                                }
                                return new Union([new T_Int_Range(0, null)]);
                            }
                        }
                    }
                    break;
                case 'hrtime':
                    if ($first_arg_type = $statements_analyzer->node_data->get_type($call_args[0]->value)) {
                        if ((string) $first_arg_type === 'true') {
                            return Type::get_int(true);
                        }
                        $keyed_array = new T_Keyed_Array([Type::get_int(), Type::get_int()], null, null, true);
                        if ((string) $first_arg_type === 'false') {
                            return new Union([$keyed_array]);
                        }
                        return new Union([$keyed_array, new T_Int()]);
                    }
                    return Type::get_int(true);
                case 'min':
                case 'max':
                    if (isset($call_args[0])) {
                        $first_arg = $call_args[0]->value;
                        if ($first_arg_type = $statements_analyzer->node_data->get_type($first_arg)) {
                            if ($first_arg_type->has_array()) {
                                $array_type = $first_arg_type->get_array();
                                if ($array_type instanceof T_Keyed_Array) {
                                    return $array_type->get_generic_value_type();
                                }
                                if ($array_type instanceof T_Array) {
                                    return $array_type->type_params[1];
                                }
                            } elseif ($first_arg_type->has_scalar_type() && ($second_arg = $call_args[1]->value ?? null) && ($second_arg_type = $statements_analyzer->node_data->get_type($second_arg)) && $second_arg_type->has_scalar_type()) {
                                return Type::combine_union_types($first_arg_type, $second_arg_type);
                            }
                        }
                    }
                    break;
                case 'get_parent_class':
                    // this is unreliable, as it's hard to know exactly what's wanted - attempted this in
                    // https://github.com/vimeo/psalm/commit/355ed831e1c69c96bbf9bf2654ef64786cbe9fd7
                    // but caused problems where it didn’t know exactly what level of child we
                    // were receiving.
                    //
                    // Really this should only work on instances we've created with new Foo(),
                    // but that requires more work
                    break;
                case 'fgetcsv':
                    $string_type = new Union([new T_String(), new T_Null()], ['ignore_nullable_issues' => true]);
                    return new Union([Type::get_non_empty_list_atomic($string_type), new T_False(), new T_Null()], ['ignore_nullable_issues' => $codebase->config->ignore_internal_nullable_issues, 'ignore_falsable_issues' => $codebase->config->ignore_internal_falsable_issues]);
                case 'mb_strtolower':
                    $string_arg_type = $statements_analyzer->node_data->get_type($call_args[0]->value);
                    if ($string_arg_type !== null && $string_arg_type->is_non_empty_string()) {
                        $return_type = Type::get_non_empty_lowercase_string();
                    } else {
                        $return_type = Type::get_lowercase_string();
                    }
                    if (count($call_args) < 2) {
                        return $return_type;
                    }
                    $second_arg_type = $statements_analyzer->node_data->get_type($call_args[1]->value);
                    if ($second_arg_type && $second_arg_type->is_null()) {
                        return $return_type;
                    }
                    if ($string_arg_type !== null && $string_arg_type->is_non_empty_string()) {
                        return Type::get_non_empty_string();
                    }
                    return Type::get_string();
            }
        }
        $stmt_type = $callmap_callable->return_type ?: Type::get_mixed();
        switch ($function_id) {
            case 'mb_strpos':
            case 'mb_strrpos':
            case 'mb_stripos':
            case 'mb_strripos':
            case 'strpos':
            case 'strrpos':
            case 'stripos':
            case 'strripos':
            case 'strstr':
            case 'stristr':
            case 'strrchr':
            case 'strpbrk':
            case 'array_search':
                break;
            default:
                if ($stmt_type->is_falsable() && $codebase->config->ignore_internal_falsable_issues) {
                    $stmt_type = $stmt_type->set_properties(['ignore_falsable_issues' => true]);
                }
        }
        return $stmt_type;
    }
    private static function taint_return_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Func_Call $stmt, string $function_id, string $cased_function_id, Function_Like_Storage $function_storage, Union &$stmt_type, Template_Result $template_result, Context $context): ?Data_Flow_Node
    {
        if (!$statements_analyzer->data_flow_graph) {
            return null;
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            return null;
        }
        $codebase = $statements_analyzer->get_codebase();
        $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        $node_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $function_call_node = Data_Flow_Node::get_for_method_return($function_id, $cased_function_id, $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph ? $function_storage->signature_return_type_location ?: $function_storage->location : ($function_storage->return_type_location ?: $function_storage->location), $function_storage->specialize_call ? $node_location : null);
        $statements_analyzer->data_flow_graph->add_node($function_call_node);
        $codebase = $statements_analyzer->get_codebase();
        $conditionally_removed_taints = [];
        foreach ($function_storage->conditionally_removed_taints as $conditionally_removed_taint) {
            $conditionally_removed_taint = Template_Inferred_Type_Replacer::replace($conditionally_removed_taint, $template_result, $codebase);
            $expanded_type = Type_Expander::expand_union($statements_analyzer->get_codebase(), $conditionally_removed_taint, null, null, null, true, true);
            if (!$expanded_type->is_nullable()) {
                foreach ($expanded_type->get_literal_strings() as $literal_string) {
                    $conditionally_removed_taints[] = $literal_string->value;
                }
            }
        }
        if ($conditionally_removed_taints && $function_storage->location) {
            $assignment_node = Data_Flow_Node::get_for_assignment($function_id . '-escaped', $function_storage->signature_return_type_location ?: $function_storage->location, $function_call_node->specialization_key);
            $statements_analyzer->data_flow_graph->add_path($function_call_node, $assignment_node, 'conditionally-escaped', $added_taints, [...$removed_taints, ...$conditionally_removed_taints]);
            $stmt_type = $stmt_type->add_parent_nodes([$assignment_node->id => $assignment_node]);
        } else {
            $stmt_type = $stmt_type->add_parent_nodes([$function_call_node->id => $function_call_node]);
        }
        if (!$statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            return $function_call_node;
        }
        if ($function_storage->return_source_params) {
            $removed_taints = $function_storage->removed_taints;
            if ($function_id === 'preg_replace' && count($stmt->get_args()) > 2) {
                $first_stmt_type = $statements_analyzer->node_data->get_type($stmt->get_args()[0]->value);
                $second_stmt_type = $statements_analyzer->node_data->get_type($stmt->get_args()[1]->value);
                if ($first_stmt_type && $second_stmt_type && $first_stmt_type->is_single_string_literal() && $second_stmt_type->is_single_string_literal()) {
                    $first_arg_value = $first_stmt_type->get_single_string_literal()->value;
                    $pattern = substr($first_arg_value, 1, -1);
                    if (strlen(trim($pattern)) > 0) {
                        $pattern = trim($pattern);
                        if ($pattern[0] === '[' && $pattern[1] === '^' && str_ends_with($pattern, ']')) {
                            $pattern = substr($pattern, 2, -1);
                            if (self::simple_exclusion($pattern, $first_arg_value[0])) {
                                $removed_taints[] = Taint_Kind::INPUT_HTML;
                                $removed_taints[] = Taint_Kind::INPUT_HAS_QUOTES;
                                $removed_taints[] = Taint_Kind::INPUT_SQL;
                            }
                        }
                    }
                }
            }
            $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
            $removed_taints = array_merge($removed_taints, $codebase->config->event_dispatcher->dispatch_remove_taints($event));
            if (!$stmt->is_first_class_callable()) {
                self::taint_using_flows($statements_analyzer, $function_storage, $statements_analyzer->data_flow_graph, $function_id, $stmt->get_args(), $node_location, $function_call_node, array_merge($removed_taints, $conditionally_removed_taints), $added_taints);
            }
        }
        self::taint_using_storage($function_storage, $statements_analyzer->data_flow_graph, $function_call_node);
        return $function_call_node;
    }
    /**
     * @param  array<PhpParser\Node\Arg>   $args
     * @param  array<string> $removed_taints
     * @param  array<string> $added_taints
     */
    public static function taint_using_flows(Statements_Analyzer $statements_analyzer, Function_Like_Storage $function_storage, Taint_Flow_Graph $graph, string $function_id, array $args, Code_Location $node_location, Data_Flow_Node $function_call_node, array $removed_taints, array $added_taints = []): void
    {
        foreach ($function_storage->return_source_params as $i => $path_type) {
            if (!isset($args[$i])) {
                continue;
            }
            $taintable_arg_index = [$i];
            if ($function_storage->params[$i]->is_variadic) {
                $max_params = count($args) - 1;
                for ($arg_index = $i + 1; $arg_index <= $max_params; $arg_index++) {
                    $taintable_arg_index[] = $arg_index;
                }
            }
            foreach ($taintable_arg_index as $arg_index) {
                $arg_location = new Code_Location($statements_analyzer, $args[$arg_index]->value);
                $function_param_sink = Data_Flow_Node::get_for_method_argument($function_id, $function_id, $arg_index, $arg_location, $function_storage->specialize_call ? $node_location : null);
                $graph->add_node($function_param_sink);
                $graph->add_path($function_param_sink, $function_call_node, $path_type, array_merge($added_taints, $function_storage->added_taints), $removed_taints);
            }
        }
    }
    public static function taint_using_storage(Function_Like_Storage $function_storage, Taint_Flow_Graph $graph, Data_Flow_Node $function_call_node): void
    {
        // Docblock-defined taints should override inherited
        $added_taints = [];
        if ($function_storage->taint_source_types !== []) {
            $added_taints = $function_storage->taint_source_types;
        } elseif ($function_storage->added_taints !== []) {
            $added_taints = $function_storage->added_taints;
        }
        $taints = array_diff($added_taints, $function_storage->removed_taints);
        if ($taints !== []) {
            $taint_source = Taint_Source::from_node($function_call_node);
            $taint_source->taints = $taints;
            $graph->add_source($taint_source);
        }
    }
    /**
     * @psalm-pure
     */
    private static function simple_exclusion(string $pattern, string $escape_char): bool
    {
        $str_length = strlen($pattern);
        for ($i = 0; $i < $str_length; $i++) {
            $current = $pattern[$i];
            $next = $pattern[$i + 1] ?? null;
            if ($current === '\\') {
                if ($next === null || $next === 'x' || $next === 'u') {
                    return false;
                }
                if ($next === '.' || $next === '(' || $next === ')' || $next === '[' || $next === ']' || $next === 's' || $next === 'w' || $next === $escape_char) {
                    $i++;
                    continue;
                }
                return false;
            }
            if ($next !== '-') {
                if ($current === '_') {
                    continue;
                }
                if ($current === '-') {
                    continue;
                }
                if ($current === '|') {
                    continue;
                }
                if ($current === ':') {
                    continue;
                }
                if ($current === '#') {
                    continue;
                }
                if ($current === '.') {
                    continue;
                }
                if ($current === ' ') {
                    continue;
                }
                return false;
            }
            if ($current === ']') {
                return false;
            }
            if (!isset($pattern[$i + 2])) {
                return false;
            }
            if ($current === 'a' && $pattern[$i + 2] === 'z' || $current === 'a' && $pattern[$i + 2] === 'Z' || $current === 'A' && $pattern[$i + 2] === 'Z' || $current === '0' && $pattern[$i + 2] === '9') {
                $i += 2;
                continue;
            }
            return false;
        }
        return true;
    }
}