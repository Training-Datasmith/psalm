<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Static_Method\Atomic_Static_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Non_Static_Self_Call;
use Psalm\Issue\Parent_Not_Found;
use Psalm\Issue_Buffer;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function array_merge;
use function count;
use function in_array;
use function md5;
use function strtolower;
/**
 * @internal
 */
final class Static_Call_Analyzer extends Call_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Context $context, ?Template_Result $template_result = null): bool
    {
        $method_id = null;
        $lhs_type = null;
        $codebase = $statements_analyzer->get_codebase();
        $source = $statements_analyzer->get_source();
        $config = $codebase->config;
        if ($stmt->class instanceof Php_Parser\Node\Name) {
            if (count($stmt->class->get_parts()) === 1 && in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
                if ($stmt->class->get_first() === 'parent') {
                    $child_fq_class_name = $context->self;
                    $class_storage = $child_fq_class_name ? $codebase->classlike_storage_provider->get($child_fq_class_name) : null;
                    if (!$class_storage || !$class_storage->parent_class) {
                        return !Issue_Buffer::accepts(new Parent_Not_Found('Cannot call method on parent as this class does not extend another', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                    }
                    $fq_class_name = $class_storage->parent_class;
                    $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
                    $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
                    $fq_class_name = $class_storage->name;
                    if ($context->collect_initializations && isset($stmt->name->name) && $stmt->name->name === '__construct' && isset($class_storage->declaring_method_ids['__construct'])) {
                        $construct_fq_class_name = $class_storage->declaring_method_ids['__construct']->fq_class_name;
                        $construct_class_storage = $codebase->classlike_storage_provider->get($construct_fq_class_name);
                        $construct_fq_class_name = $construct_class_storage->name;
                        foreach ($construct_class_storage->properties as $property_name => $property_storage) {
                            if ($property_storage->is_promoted && isset($context->vars_in_scope['$this->' . $property_name])) {
                                $context_type = $context->vars_in_scope['$this->' . $property_name];
                                $context->vars_in_scope['$this->' . $property_name] = $context_type->set_properties(['initialized_class' => $construct_fq_class_name, 'initialized' => true]);
                            }
                        }
                    }
                } elseif ($context->self) {
                    if ($stmt->class->get_first() === 'static' && isset($context->vars_in_scope['$this'])) {
                        $fq_class_name = (string) $context->vars_in_scope['$this'];
                        $lhs_type = $context->vars_in_scope['$this'];
                    } else {
                        $fq_class_name = $context->self;
                    }
                } else {
                    return !Issue_Buffer::accepts(new Non_Static_Self_Call('Cannot use ' . $stmt->class->get_first() . ' outside class context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                if ($context->is_phantom_class($fq_class_name)) {
                    return true;
                }
            } else {
                $aliases = $statements_analyzer->get_aliases();
                if ($context->calling_method_id && !$stmt->class instanceof Php_Parser\Node\Name\Fully_Qualified) {
                    $codebase->file_reference_provider->add_method_reference_to_class_member($context->calling_method_id, 'use:' . $stmt->class->get_first() . ':' . md5($statements_analyzer->get_file_path()), false);
                }
                $fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $aliases);
                if ($context->is_phantom_class($fq_class_name)) {
                    return true;
                }
                $does_class_exist = false;
                if ($context->self) {
                    $self_storage = $codebase->classlike_storage_provider->get($context->self);
                    if (isset($self_storage->used_traits[strtolower($fq_class_name)])) {
                        $fq_class_name = $context->self;
                        $does_class_exist = true;
                    }
                }
                if (!isset($context->phantom_classes[strtolower($fq_class_name)]) && !$does_class_exist) {
                    $does_class_exist = Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($source, $stmt->class), !$context->collect_initializations && !$context->collect_mutations ? $context->self : null, !$context->collect_initializations && !$context->collect_mutations ? $context->calling_method_id : null, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(false, false, false, true), $context->check_classes);
                }
                if (!$does_class_exist) {
                    return $does_class_exist !== false;
                }
            }
            if ($codebase->store_node_types && $fq_class_name && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $fq_class_name);
            }
            if ($fq_class_name && !$lhs_type) {
                $lhs_type = new Union([new T_Named_Object($fq_class_name)]);
            }
        } else {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            Expression_Analyzer::analyze($statements_analyzer, $stmt->class, $context);
            $context->inside_general_use = $was_inside_general_use;
            $lhs_type = $statements_analyzer->node_data->get_type($stmt->class) ?? Type::get_mixed();
        }
        if (!$lhs_type) {
            if (Arguments_Analyzer::analyze($statements_analyzer, $stmt->get_args(), null, null, true, $context) === false) {
                return false;
            }
            return true;
        }
        $has_mock = false;
        $moved_call = false;
        $has_existing_method = false;
        foreach ($lhs_type->get_atomic_types() as $lhs_type_part) {
            Atomic_Static_Call_Analyzer::analyze($statements_analyzer, $stmt, $context, $lhs_type_part, $lhs_type->ignore_nullable_issues, $moved_call, $has_mock, $has_existing_method, $template_result);
        }
        if (!$stmt->is_first_class_callable() && !$has_existing_method) {
            return self::check_method_args($method_id, $stmt->get_args(), new Template_Result([], []), $context, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer);
        }
        if (!$config->remember_property_assignments_after_call && !$context->collect_initializations) {
            $context->remove_mutable_object_vars();
        }
        if (!$statements_analyzer->node_data->get_type($stmt)) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        }
        return true;
    }
    public static function taint_return_type(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Call $stmt, Method_Identifier $method_id, string $cased_method_id, Union &$return_type_candidate, ?Method_Storage $method_storage, ?Template_Result $template_result, ?Context $context = null): void
    {
        if (!$statements_analyzer->data_flow_graph) {
            return;
        }
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
            return;
        }
        $node_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $method_location = $method_storage ? $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph ? $method_storage->signature_return_type_location ?: $method_storage->location : ($method_storage->return_type_location ?: $method_storage->location) : null;
        if ($method_storage && $method_storage->specialize_call) {
            $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $method_location, $node_location);
        } else {
            $method_source = Data_Flow_Node::get_for_method_return((string) $method_id, $cased_method_id, $method_location);
        }
        $statements_analyzer->data_flow_graph->add_node($method_source);
        $codebase = $statements_analyzer->get_codebase();
        $conditionally_removed_taints = [];
        if ($method_storage && $template_result) {
            foreach ($method_storage->conditionally_removed_taints as $conditionally_removed_taint) {
                $conditionally_removed_taint = Template_Inferred_Type_Replacer::replace($conditionally_removed_taint, $template_result, $codebase);
                $expanded_type = Type_Expander::expand_union($statements_analyzer->get_codebase(), $conditionally_removed_taint, null, null, null, true, true);
                foreach ($expanded_type->get_literal_strings() as $literal_string) {
                    $conditionally_removed_taints[] = $literal_string->value;
                }
            }
        }
        $added_taints = [];
        $removed_taints = [];
        if ($context) {
            $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
            $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        }
        if ($conditionally_removed_taints && $method_location) {
            $assignment_node = Data_Flow_Node::get_for_assignment($method_id . '-escaped', $method_location, $method_source->specialization_key);
            $statements_analyzer->data_flow_graph->add_path($method_source, $assignment_node, 'conditionally-escaped', $added_taints, [...$conditionally_removed_taints, ...$removed_taints]);
            $return_type_candidate = $return_type_candidate->add_parent_nodes([$assignment_node->id => $assignment_node]);
        } else {
            $return_type_candidate = $return_type_candidate->set_parent_nodes([$method_source->id => $method_source]);
        }
        if ($method_storage && $method_storage->taint_source_types && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $method_node = Taint_Source::get_for_method_return((string) $method_id, $cased_method_id, $method_storage->signature_return_type_location ?: $method_storage->location);
            $method_node->taints = $method_storage->taint_source_types;
            $statements_analyzer->data_flow_graph->add_source($method_node);
        }
        if ($method_storage && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            Function_Call_Return_Type_Fetcher::taint_using_flows($statements_analyzer, $method_storage, $statements_analyzer->data_flow_graph, (string) $method_id, $stmt->get_args(), $node_location, $method_source, array_merge($method_storage->removed_taints, $removed_taints), $added_taints);
        }
    }
}