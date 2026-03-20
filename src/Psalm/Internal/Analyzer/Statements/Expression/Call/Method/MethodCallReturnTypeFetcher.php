<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Exception;
use PDOException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Function_Call_Return_Type_Fetcher;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Bound;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use function array_filter;
use function count;
use function in_array;
use function strtolower;
/**
 * @internal
 */
final class Method_Call_Return_Type_Fetcher
{
    /**
     * @param  TNamedObject|TTemplateParam|null  $static_type
     * @param list<PhpParser\Node\Arg> $args
     */
    public static function fetch(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Method_Call $stmt, Context $context, Method_Identifier $method_id, ?Method_Identifier $declaring_method_id, Method_Identifier $premixin_method_id, string $cased_method_id, Atomic $lhs_type_part, ?Atomic $static_type, array $args, Atomic_Method_Call_Analysis_Result $result, Template_Result $template_result): Union
    {
        $call_map_id = $declaring_method_id ?? $method_id;
        $fq_class_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        $class_storage = $codebase->methods->get_class_like_storage_for_method($method_id);
        $method_storage = $class_storage->methods[$method_id->method_name] ?? null;
        if ($stmt->is_first_class_callable()) {
            if ($method_storage) {
                return new Union([new T_Closure('Closure', $method_storage->params, $method_storage->return_type, $method_storage->pure)]);
            }
            return Type::get_closure();
        }
        if ($codebase->methods->return_type_provider->has($premixin_method_id->fq_class_name)) {
            $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $premixin_method_id->fq_class_name, $premixin_method_id->method_name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt->name), $lhs_type_part instanceof T_Generic_Object ? $lhs_type_part->type_params : null);
            if ($return_type_candidate) {
                return $return_type_candidate;
            }
        }
        if ($premixin_method_id->method_name === 'getcode' && $premixin_method_id->fq_class_name !== Exception::class && $premixin_method_id->fq_class_name !== RuntimeException::class && $premixin_method_id->fq_class_name !== PDOException::class && ($codebase->class_implements($premixin_method_id->fq_class_name, Throwable::class) || $codebase->interface_extends($premixin_method_id->fq_class_name, Throwable::class))) {
            return Type::get_int();
        }
        if ($declaring_method_id && $declaring_method_id !== $method_id) {
            $declaring_fq_class_name = $declaring_method_id->fq_class_name;
            $declaring_method_name = $declaring_method_id->method_name;
            if ($codebase->methods->return_type_provider->has($declaring_fq_class_name)) {
                $return_type_candidate = $codebase->methods->return_type_provider->get_return_type($statements_analyzer, $declaring_fq_class_name, $declaring_method_name, $stmt, $context, new Code_Location($statements_analyzer->get_source(), $stmt->name), $lhs_type_part instanceof T_Generic_Object ? $lhs_type_part->type_params : null, $fq_class_name, $method_name);
                if ($return_type_candidate) {
                    return $return_type_candidate;
                }
            }
        }
        if (Internal_Call_Map_Handler::in_call_map((string) $call_map_id)) {
            if (($template_result->lower_bounds || $class_storage->stubbed) && ($method_storage = $class_storage->methods[$method_id->method_name] ?? null) && $method_storage->return_type) {
                $return_type_candidate = $method_storage->return_type;
                $return_type_candidate = self::replace_template_types($return_type_candidate, $template_result, $method_id, count($stmt->get_args()), $codebase);
            } else {
                $callmap_callables = Internal_Call_Map_Handler::get_callables_from_call_map((string) $call_map_id);
                if (!$callmap_callables || $callmap_callables[0]->return_type === null) {
                    throw new UnexpectedValueException('Shouldn’t get here');
                }
                $return_type_candidate = $callmap_callables[0]->return_type;
            }
            if ($return_type_candidate->is_falsable()) {
                $return_type_candidate = $return_type_candidate->set_properties(['ignore_falsable_issues' => true]);
            }
            $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $fq_class_name, $static_type, $class_storage->parent_class, true, false, false, true);
        } else {
            $self_fq_class_name = $fq_class_name;
            $return_type_candidate = $codebase->methods->get_method_return_type($method_id, $self_fq_class_name, $statements_analyzer, $args, $template_result);
            if ($return_type_candidate) {
                if ($template_result->lower_bounds) {
                    $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $fq_class_name, null, $class_storage->parent_class, true, false, $static_type instanceof T_Named_Object && $codebase->classlike_storage_provider->get($static_type->value)->final, true);
                }
                $return_type_candidate = self::replace_template_types($return_type_candidate, $template_result, $method_id, count($stmt->get_args()), $codebase);
                $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, $self_fq_class_name, $static_type, $class_storage->parent_class, true, false, $static_type instanceof T_Named_Object && $codebase->classlike_storage_provider->get($static_type->value)->final, true);
                $return_type_location = $codebase->methods->get_method_return_type_location($method_id, $secondary_return_type_location);
                if ($secondary_return_type_location) {
                    $return_type_location = $secondary_return_type_location;
                }
                $config = Config::get_instance();
                // only check the type locally if it's defined externally
                if ($return_type_location && !$config->is_in_project_dirs($return_type_location->file_path)) {
                    /** @psalm-suppress UnusedMethodCall Actually generates issues */
                    $return_type_candidate->check($statements_analyzer, new Code_Location($statements_analyzer, $stmt), $statements_analyzer->get_suppressed_issues(), $context->phantom_classes, true, false, false, $context->calling_method_id);
                }
            } else {
                $result->returns_by_ref = $result->returns_by_ref || $codebase->methods->get_method_returns_by_ref($method_id);
            }
        }
        if (!$return_type_candidate) {
            $return_type_candidate = $method_name === '__tostring' ? Type::get_string() : Type::get_mixed();
        }
        self::taint_method_call_result($statements_analyzer, $return_type_candidate, $stmt->name, $stmt->var, $args, $method_id, $declaring_method_id, $cased_method_id, $context);
        return $return_type_candidate;
    }
    /**
     * @param  array<PhpParser\Node\Arg>   $args
     */
    public static function taint_method_call_result(Statements_Analyzer $statements_analyzer, Union &$return_type_candidate, Php_Parser\Node $name_expr, Php_Parser\Node\Expr $var_expr, array $args, Method_Identifier $method_id, ?Method_Identifier $declaring_method_id, string $cased_method_id, Context $context): void
    {
        if (!$statements_analyzer->data_flow_graph || !$declaring_method_id) {
            return;
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            return;
        }
        $codebase = $statements_analyzer->get_codebase();
        $event = new Add_Remove_Taints_Event($var_expr, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        $method_storage = $codebase->methods->get_storage($declaring_method_id);
        $node_location = new Code_Location($statements_analyzer, $name_expr);
        $is_declaring = (string) $declaring_method_id === (string) $method_id;
        $var_id = Expression_Identifier::get_extended_var_id($var_expr, null, $statements_analyzer);
        if ($method_storage->specialize_call && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            if ($var_id && isset($context->vars_in_scope[$var_id])) {
                $var_nodes = [];
                $parent_nodes = $context->vars_in_scope[$var_id]->parent_nodes;
                $unspecialized_parent_nodes = array_filter($parent_nodes, static fn(Data_Flow_Node $parent_node): bool => !$parent_node->specialization_key);
                $specialized_parent_nodes = array_filter($parent_nodes, static fn(Data_Flow_Node $parent_node): bool => (bool) $parent_node->specialization_key);
                $var_node = Data_Flow_Node::get_for_assignment($var_id, new Code_Location($statements_analyzer, $var_expr));
                if ($method_storage->location) {
                    $this_parent_node = Data_Flow_Node::get_for_assignment('$this in ' . $method_id, $method_storage->location);
                    foreach ($parent_nodes as $parent_node) {
                        $statements_analyzer->data_flow_graph->add_path($parent_node, $this_parent_node, '=', $added_taints, $removed_taints);
                    }
                }
                $var_nodes[$var_node->id] = $var_node;
                $method_call_nodes = [];
                if ($unspecialized_parent_nodes) {
                    $method_call_node = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $is_declaring ? $method_storage->signature_return_type_location ?: $method_storage->location : null, $node_location);
                    $method_call_nodes[$method_call_node->id] = $method_call_node;
                }
                foreach ($specialized_parent_nodes as $parent_node) {
                    $universal_method_call_node = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $is_declaring ? $method_storage->signature_return_type_location ?: $method_storage->location : null);
                    $method_call_node = new Data_Flow_Node(strtolower((string) $method_id), $cased_method_id, $is_declaring ? $method_storage->signature_return_type_location ?: $method_storage->location : null, $parent_node->specialization_key);
                    $statements_analyzer->data_flow_graph->add_path($universal_method_call_node, $method_call_node, '=', $added_taints, $removed_taints);
                    $method_call_nodes[$method_call_node->id] = $method_call_node;
                }
                if (!$method_call_nodes) {
                    return;
                }
                foreach ($method_call_nodes as $method_call_node) {
                    $statements_analyzer->data_flow_graph->add_node($method_call_node);
                    foreach ($var_nodes as $var_node) {
                        $statements_analyzer->data_flow_graph->add_node($var_node);
                        $statements_analyzer->data_flow_graph->add_path($method_call_node, $var_node, 'method-call-' . $method_id->method_name, $added_taints, $removed_taints);
                    }
                    if (!$is_declaring) {
                        $cased_declaring_method_id = $codebase->methods->get_cased_method_id($declaring_method_id);
                        $declaring_method_call_node = new Data_Flow_Node(strtolower((string) $declaring_method_id), $cased_declaring_method_id, $method_storage->signature_return_type_location ?: $method_storage->location, $method_call_node->specialization_key);
                        $statements_analyzer->data_flow_graph->add_node($declaring_method_call_node);
                        $statements_analyzer->data_flow_graph->add_path($declaring_method_call_node, $method_call_node, 'parent', $added_taints, $removed_taints);
                    }
                }
                $return_type_candidate = $return_type_candidate->set_parent_nodes($method_call_nodes);
                $stmt_var_type = $context->vars_in_scope[$var_id]->set_parent_nodes($var_nodes);
                $context->vars_in_scope[$var_id] = $stmt_var_type;
            } else {
                $method_call_node = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $is_declaring ? $method_storage->signature_return_type_location ?: $method_storage->location : null, $node_location);
                if (!$is_declaring) {
                    $cased_declaring_method_id = $codebase->methods->get_cased_method_id($declaring_method_id);
                    $declaring_method_call_node = Data_Flow_Node::get_for_method_return((string) $declaring_method_id, $cased_declaring_method_id, $method_storage->signature_return_type_location ?: $method_storage->location, $node_location);
                    $statements_analyzer->data_flow_graph->add_node($declaring_method_call_node);
                    $statements_analyzer->data_flow_graph->add_path($declaring_method_call_node, $method_call_node, 'parent', $added_taints, $removed_taints);
                }
                $statements_analyzer->data_flow_graph->add_node($method_call_node);
                $return_type_candidate = $return_type_candidate->set_parent_nodes([$method_call_node->id => $method_call_node]);
            }
        } else {
            $method_call_node = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $is_declaring ? $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph ? $method_storage->signature_return_type_location ?: $method_storage->location : ($method_storage->return_type_location ?: $method_storage->location) : null);
            if (!$is_declaring) {
                $cased_declaring_method_id = $codebase->methods->get_cased_method_id($declaring_method_id);
                $declaring_method_call_node = Data_Flow_Node::get_for_method_return((string) $declaring_method_id, $cased_declaring_method_id, $method_storage->signature_return_type_location ?: $method_storage->location);
                $statements_analyzer->data_flow_graph->add_node($declaring_method_call_node);
                $statements_analyzer->data_flow_graph->add_path($declaring_method_call_node, $method_call_node, 'parent', $added_taints, $removed_taints);
            }
            $statements_analyzer->data_flow_graph->add_node($method_call_node);
            $return_type_candidate = $return_type_candidate->set_parent_nodes([$method_call_node->id => $method_call_node]);
        }
        if (!$statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            return;
        }
        Function_Call_Return_Type_Fetcher::taint_using_flows($statements_analyzer, $method_storage, $statements_analyzer->data_flow_graph, (string) $method_id, $args, $node_location, $method_call_node, $method_storage->removed_taints);
        Function_Call_Return_Type_Fetcher::taint_using_storage($method_storage, $statements_analyzer->data_flow_graph, $method_call_node);
    }
    public static function replace_template_types(Union $return_type_candidate, Template_Result $template_result, Method_Identifier $method_id, int $arg_count, Codebase $codebase): Union
    {
        if ($template_result->template_types) {
            $bindable_template_types = $return_type_candidate->get_template_types();
            foreach ($bindable_template_types as $template_type) {
                if ($template_type->defining_class !== $method_id->fq_class_name && !isset($template_result->lower_bounds[$template_type->param_name][$template_type->defining_class])) {
                    if ($template_type->param_name === 'TFunctionArgCount') {
                        $template_result->lower_bounds[$template_type->param_name] = ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, $arg_count))]];
                    } elseif ($template_type->param_name === 'TPhpMajorVersion') {
                        $template_result->lower_bounds[$template_type->param_name] = ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, $codebase->get_major_analysis_php_version()))]];
                    } elseif ($template_type->param_name === 'TPhpVersionId') {
                        $template_result->lower_bounds[$template_type->param_name] = ['fn-' . $method_id->method_name => [new Template_Bound(Type::get_int(false, $codebase->analysis_php_version_id))]];
                    } else {
                        $template_result->lower_bounds[$template_type->param_name] = [$template_type->defining_class => [new Template_Bound(Type::get_never())]];
                    }
                }
            }
        }
        if ($template_result->lower_bounds) {
            $return_type_candidate = Type_Expander::expand_union($codebase, $return_type_candidate, null, null, null);
            $return_type_candidate = Template_Inferred_Type_Replacer::replace($return_type_candidate, $template_result, $codebase);
        }
        return $return_type_candidate;
    }
}