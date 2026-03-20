<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Assignment;

use Php_Parser;
use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Property_Fetch;
use Php_Parser\Node\Property_Item;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Class_Template_Param_Collector;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Atomic_Property_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Methods;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Codebase\Variable_Use_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Template_Standin_Type_Replacer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Deprecated_Property;
use Psalm\Issue\Implicit_To_String_Cast;
use Psalm\Issue\Impure_Property_Assignment;
use Psalm\Issue\Inaccessible_Property;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Internal_Property;
use Psalm\Issue\Invalid_Property_Assignment;
use Psalm\Issue\Invalid_Property_Assignment_Value;
use Psalm\Issue\Loop_Invalidation;
use Psalm\Issue\Mixed_Assignment;
use Psalm\Issue\Mixed_Property_Assignment;
use Psalm\Issue\Mixed_Property_Type_Coercion;
use Psalm\Issue\No_Interface_Properties;
use Psalm\Issue\Null_Property_Assignment;
use Psalm\Issue\Possibly_False_Property_Assignment_Value;
use Psalm\Issue\Possibly_Invalid_Property_Assignment;
use Psalm\Issue\Possibly_Invalid_Property_Assignment_Value;
use Psalm\Issue\Possibly_Null_Property_Assignment;
use Psalm\Issue\Possibly_Null_Property_Assignment_Value;
use Psalm\Issue\Property_Type_Coercion;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Undefined_Magic_Property_Assignment;
use Psalm\Issue\Undefined_Property_Assignment;
use Psalm\Issue\Undefined_This_Property_Assignment;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Scalar\Virtual_String;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Property_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use UnexpectedValueException;
use function array_diff;
use function array_merge;
use function array_pop;
use function count;
use function in_array;
use function reset;
use function strpos;
use function strtolower;
/**
 * @internal
 */
final class Instance_Property_Assignment_Analyzer
{
    /**
     * @param   PropertyFetch|PropertyItem  $stmt
     * @param   bool                        $direct_assignment whether the variable is assigned explicitly
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node_Abstract $stmt, string $prop_name, ?Php_Parser\Node\Expr $assignment_value, Union $assignment_value_type, Context $context, bool $direct_assignment = true): void
    {
        $codebase = $statements_analyzer->get_codebase();
        if ($stmt instanceof Property_Item) {
            if (!$context->self || !$stmt->default) {
                return;
            }
            $property_id = $context->self . '::$' . $prop_name;
            $class_property_type = null;
            try {
                $class_property_type = $codebase->properties->get_property_type($property_id, true, $statements_analyzer, $context);
            } catch (UnexpectedValueException) {
                // do nothing
            }
            if ($class_property_type) {
                $class_storage = $codebase->classlike_storage_provider->get($context->self);
                $class_property_type = self::get_expanded_property_type($codebase, $context->self, $prop_name, $class_storage);
            }
            $var_id = '$this->' . $prop_name;
            $assigned_properties = [new Assigned_Property($class_property_type ?? Type::get_mixed(), $property_id, $assignment_value_type)];
        } else {
            $assigned_properties = self::analyze_regular_assignment($statements_analyzer, $stmt, $assignment_value, $context, $direct_assignment, $codebase, $assignment_value_type, $prop_name, $var_id);
        }
        if (!$assigned_properties) {
            return;
        }
        if ($assignment_value_type->has_mixed()) {
            return;
        }
        $invalid_assignment_value_types = [];
        $has_valid_assignment_value_type = false;
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations && count($assigned_properties) === 1) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $assigned_properties[0]->property_type->get_id());
        }
        foreach ($assigned_properties as $assigned_property) {
            $class_property_type = $assigned_property->property_type;
            $assignment_type = $assigned_property->assignment_type;
            if ($class_property_type->has_mixed()) {
                continue;
            }
            $union_comparison_results = new Type_Comparison_Result();
            $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $assignment_type, $class_property_type, true, true, $union_comparison_results);
            if ($type_match_found && $union_comparison_results->replacement_union_type) {
                if ($var_id) {
                    $context->vars_in_scope[$var_id] = $union_comparison_results->replacement_union_type;
                }
            }
            if ($union_comparison_results->type_coerced) {
                if ($union_comparison_results->type_coerced_from_mixed) {
                    Issue_Buffer::maybe_add(new Mixed_Property_Type_Coercion($var_id . ' expects \'' . $class_property_type->get_id() . '\', ' . ' parent type `' . $assignment_type->get_id() . '` provided', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $assigned_property->id), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Property_Type_Coercion($var_id . ' expects \'' . $class_property_type->get_id() . '\', ' . ' parent type \'' . $assignment_type->get_id() . '\' provided', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $assigned_property->id), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($union_comparison_results->to_string_cast) {
                Issue_Buffer::maybe_add(new Implicit_To_String_Cast($var_id . ' expects \'' . $class_property_type . '\', ' . '\'' . $assignment_type . '\' provided with a __toString method', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location)), $statements_analyzer->get_suppressed_issues());
            }
            if (!$type_match_found && !$union_comparison_results->type_coerced) {
                if (Union_Type_Comparator::can_be_contained_by($codebase, $assignment_type, $class_property_type, true, true)) {
                    $has_valid_assignment_value_type = true;
                }
                $invalid_assignment_value_types[$assigned_property->id] = $class_property_type->get_id();
            } else {
                $has_valid_assignment_value_type = true;
            }
            if ($type_match_found) {
                if (!$assignment_type->ignore_nullable_issues && $assignment_type->is_nullable() && !$class_property_type->is_nullable()) {
                    if (Issue_Buffer::accepts(new Possibly_Null_Property_Assignment_Value($var_id . ' with non-nullable declared type \'' . $class_property_type . '\' cannot be assigned nullable type \'' . $assignment_type . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $assigned_property->id), $statements_analyzer->get_suppressed_issues())) {
                        return;
                    }
                }
                if (!$assignment_type->ignore_falsable_issues && $assignment_type->is_falsable() && !$class_property_type->has_bool() && !$class_property_type->has_scalar()) {
                    if (Issue_Buffer::accepts(new Possibly_False_Property_Assignment_Value($var_id . ' with non-falsable declared type \'' . $class_property_type . '\' cannot be assigned possibly false type \'' . $assignment_type . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $assigned_property->id), $statements_analyzer->get_suppressed_issues())) {
                        return;
                    }
                }
            }
        }
        foreach ($invalid_assignment_value_types as $property_id => $invalid_class_property_type) {
            if (!$has_valid_assignment_value_type) {
                if (Issue_Buffer::accepts(new Invalid_Property_Assignment_Value($var_id . ' with declared type \'' . $invalid_class_property_type . '\' cannot be assigned type \'' . $assignment_value_type->get_id() . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $property_id), $statements_analyzer->get_suppressed_issues())) {
                    return;
                }
            } else if (Issue_Buffer::accepts(new Possibly_Invalid_Property_Assignment_Value($var_id . ' with declared type \'' . $invalid_class_property_type . '\' cannot be assigned possibly different type \'' . $assignment_value_type->get_id() . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $property_id), $statements_analyzer->get_suppressed_issues())) {
                return;
            }
        }
    }
    public static function track_property_impurity(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, string $property_id, Property_Storage $property_storage, Class_Like_Storage $declaring_class_storage, Context $context): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var);
        $property_var_pure_compatible = $stmt_var_type && $stmt_var_type->reference_free && $stmt_var_type->allow_mutations;
        $appearing_property_class = $codebase->properties->get_appearing_class_for_property($property_id, true);
        $project_analyzer = $statements_analyzer->get_project_analyzer();
        if ($appearing_property_class && ($property_storage->readonly || $codebase->alter_code)) {
            $can_set_readonly_property = $context->self && $context->calling_method_id && ($appearing_property_class === $context->self || $codebase->class_extends($context->self, $appearing_property_class)) && (strpos($context->calling_method_id, '::__construct') || strpos($context->calling_method_id, '::unserialize') || strpos($context->calling_method_id, '::__unserialize') || strpos($context->calling_method_id, '::__clone') || $property_storage->allow_private_mutation || $property_var_pure_compatible);
            if (!$can_set_readonly_property) {
                if ($property_storage->readonly) {
                    Issue_Buffer::maybe_add(new Inaccessible_Property($property_id . ' is marked readonly', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif (!$declaring_class_storage->mutation_free && isset($project_analyzer->get_issues_to_fix()['MissingImmutableAnnotation']) && $statements_analyzer->get_source() instanceof Function_Like_Analyzer) {
                    $codebase->analyzer->add_mutable_class($declaring_class_storage->name);
                }
            }
        }
    }
    public static function analyze_statement(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Property $stmt, Context $context): void
    {
        foreach ($stmt->props as $prop) {
            if ($prop->default) {
                if ($stmt->is_readonly()) {
                    Issue_Buffer::maybe_add(new Invalid_Property_Assignment('Readonly property ' . $context->self . '::$' . $prop->name->name . ' cannot have a default', new Code_Location($statements_analyzer->get_source(), $prop->default)));
                }
                Expression_Analyzer::analyze($statements_analyzer, $prop->default, $context);
                if ($prop_default_type = $statements_analyzer->node_data->get_type($prop->default)) {
                    self::analyze($statements_analyzer, $prop, $prop->name->name, $prop->default, $prop_default_type, $context);
                }
            }
        }
    }
    private static function taint_property(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, string $property_id, Class_Like_Storage $class_storage, Union &$assignment_value_type, Context $context): void
    {
        if (!$statements_analyzer->data_flow_graph) {
            return;
        }
        $codebase = $statements_analyzer->get_codebase();
        $data_flow_graph = $statements_analyzer->data_flow_graph;
        if ($class_storage->specialize_instance) {
            $var_id = Expression_Identifier::get_extended_var_id($stmt->var, null, $statements_analyzer);
            $var_property_id = Expression_Identifier::get_extended_var_id($stmt, null, $statements_analyzer);
            if ($var_id) {
                if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                    $context->vars_in_scope[$var_id] = $context->vars_in_scope[$var_id]->set_parent_nodes([]);
                    return;
                }
                $var_location = new Code_Location($statements_analyzer->get_source(), $stmt->var);
                $var_node = Data_Flow_Node::get_for_assignment($var_id, $var_location);
                $data_flow_graph->add_node($var_node);
                $property_location = new Code_Location($statements_analyzer->get_source(), $stmt);
                $property_node = Data_Flow_Node::get_for_assignment($var_property_id ?: $var_id . '->$property', $property_location);
                $data_flow_graph->add_node($property_node);
                $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
                $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
                $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
                $taints = array_diff($added_taints, $removed_taints);
                if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                    $taint_source = Taint_Source::from_node($property_node);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
                $data_flow_graph->add_path($property_node, $var_node, 'property-assignment' . ($stmt->name instanceof Php_Parser\Node\Identifier ? '-' . $stmt->name : ''), $added_taints, $removed_taints);
                foreach ($assignment_value_type->parent_nodes as $parent_node) {
                    $data_flow_graph->add_path($parent_node, $property_node, '=', $added_taints, $removed_taints);
                }
                if (isset($context->vars_in_scope[$var_id])) {
                    $stmt_var_type = $context->vars_in_scope[$var_id]->set_parent_nodes([$var_node->id => $var_node]);
                    if ($context->vars_in_scope[$var_id]->parent_nodes) {
                        foreach ($context->vars_in_scope[$var_id]->parent_nodes as $parent_node) {
                            $data_flow_graph->add_path($parent_node, $var_node, '=', $added_taints, $removed_taints);
                        }
                    }
                    $context->vars_in_scope[$var_id] = $stmt_var_type;
                }
            }
        } else {
            if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                $assignment_value_type = $assignment_value_type->set_parent_nodes([]);
                return;
            }
            $var_property_id = Expression_Identifier::get_extended_var_id($stmt, null, $statements_analyzer);
            self::taint_unspecialized_property($statements_analyzer, $stmt, $property_id, $class_storage, $assignment_value_type, $context, $var_property_id);
        }
    }
    public static function taint_unspecialized_property(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, string $property_id, Class_Like_Storage $class_storage, Union $assignment_value_type, Context $context, ?string $var_property_id): void
    {
        $codebase = $statements_analyzer->get_codebase();
        $data_flow_graph = $statements_analyzer->data_flow_graph;
        if (!$data_flow_graph) {
            return;
        }
        $property_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $localized_property_node = Data_Flow_Node::get_for_assignment($var_property_id ?: $property_id, $property_location);
        $data_flow_graph->add_node($localized_property_node);
        $property_node = new Data_Flow_Node($property_id, $property_id, null);
        $data_flow_graph->add_node($property_node);
        $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
        $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
        $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        $taints = array_diff($added_taints, $removed_taints);
        if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $taint_source = Taint_Source::from_node($property_node);
            $taint_source->taints = $taints;
            $statements_analyzer->data_flow_graph->add_source($taint_source);
        }
        $data_flow_graph->add_path($localized_property_node, $property_node, 'property-assignment', $added_taints, $removed_taints);
        foreach ($assignment_value_type->parent_nodes as $parent_node) {
            $data_flow_graph->add_path($parent_node, $localized_property_node, '=', $added_taints, $removed_taints);
        }
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, false, $statements_analyzer);
        if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $declaring_property_class && $declaring_property_class !== $class_storage->name && ($stmt instanceof Php_Parser\Node\Expr\Property_Fetch || $stmt instanceof Php_Parser\Node\Expr\Static_Property_Fetch) && $stmt->name instanceof Php_Parser\Node\Identifier) {
            $declaring_property_node = new Data_Flow_Node($declaring_property_class . '::$' . $stmt->name, $declaring_property_class . '::$' . $stmt->name, null);
            $data_flow_graph->add_node($declaring_property_node);
            $data_flow_graph->add_path($property_node, $declaring_property_node, 'property-assignment', $added_taints, $removed_taints);
        }
    }
    /**
     * @return list<AssignedProperty>
     */
    private static function analyze_regular_assignment(Statements_Analyzer $statements_analyzer, Property_Fetch $stmt, ?Php_Parser\Node\Expr $assignment_value, Context $context, bool $direct_assignment, Codebase $codebase, Union $assignment_value_type, string $prop_name, ?string &$var_id): array
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context);
        $context->inside_general_use = $was_inside_general_use;
        $lhs_type = $statements_analyzer->node_data->get_type($stmt->var);
        if ($lhs_type === null) {
            return [];
        }
        $lhs_var_id = Expression_Identifier::get_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $var_id = Expression_Identifier::get_var_id($stmt, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($var_id) {
            $context->assigned_var_ids[$var_id] = (int) $stmt->var->get_attribute('startFilePos');
            if ($direct_assignment && isset($context->protected_var_ids[$var_id])) {
                Issue_Buffer::maybe_add(new Loop_Invalidation('Variable ' . $var_id . ' has already been assigned in a for/foreach loop', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($lhs_type->has_mixed()) {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                $codebase->analyzer->add_mixed_member_name('$' . $stmt->name->name, $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            Issue_Buffer::maybe_add(new Mixed_Property_Assignment($lhs_var_id . ' of type mixed cannot be assigned to', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            return [];
        }
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
        }
        if ($lhs_type->is_null()) {
            Issue_Buffer::maybe_add(new Null_Property_Assignment($lhs_var_id . ' of type null cannot be assigned to', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            return [];
        }
        if ($lhs_type->is_nullable() && !$lhs_type->ignore_nullable_issues) {
            Issue_Buffer::maybe_add(new Possibly_Null_Property_Assignment($lhs_var_id . ' with possibly null type \'' . $lhs_type . '\' cannot be assigned to', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
        }
        $has_regular_setter = false;
        $invalid_assignment_types = [];
        $has_valid_assignment_type = false;
        $lhs_atomic_types = $lhs_type->get_atomic_types();
        $assigned_properties = [];
        $context_type = null;
        while ($lhs_atomic_types) {
            $lhs_type_part = array_pop($lhs_atomic_types);
            if ($lhs_type_part instanceof T_Template_Param) {
                $lhs_atomic_types = array_merge($lhs_atomic_types, $lhs_type_part->as->get_atomic_types());
                continue;
            }
            $assigned_property = self::analyze_atomic_assignment($statements_analyzer, $codebase, $stmt, $assignment_value, $prop_name, $context, $lhs_type, $lhs_type_part, $invalid_assignment_types, $var_id, $assignment_value_type, $lhs_var_id, $has_valid_assignment_type, $has_regular_setter);
            if ($assigned_property) {
                $assigned_properties[] = $assigned_property;
                if ($context_type) {
                    $context_type = Type::combine_union_types($context_type, $assigned_property->assignment_type, $codebase);
                } else {
                    $context_type = $assigned_property->assignment_type;
                }
            }
        }
        if ($invalid_assignment_types) {
            $invalid_assignment_type = $invalid_assignment_types[0];
            if (!$has_valid_assignment_type) {
                Issue_Buffer::maybe_add(new Invalid_Property_Assignment($lhs_var_id . ' with non-object type \'' . $invalid_assignment_type . '\' cannot treated as an object', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Property_Assignment($lhs_var_id . ' with possible non-object type \'' . $invalid_assignment_type . '\' cannot treated as an object', new Code_Location($statements_analyzer->get_source(), $stmt->var)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if (!$has_regular_setter) {
            return [];
        }
        $context_type = $context_type ?: $assignment_value_type;
        if ($var_id) {
            if ($context->collect_initializations && $lhs_var_id === '$this') {
                $context_type = $context_type->set_properties(['initialized_class' => $context->self]);
            }
            // because we don't want to be assigning for property declarations
            $context->vars_in_scope[$var_id] = $context_type;
        }
        return $assigned_properties;
    }
    /**
     * @param list<string> $invalid_assignment_types
     * @psalm-suppress ComplexMethod Unavoidably complex method
     */
    private static function analyze_atomic_assignment(Statements_Analyzer $statements_analyzer, Codebase $codebase, Property_Fetch $stmt, ?Php_Parser\Node\Expr $assignment_value, string $prop_name, Context $context, Union $lhs_type, Atomic $lhs_type_part, array &$invalid_assignment_types, ?string $var_id, Union $assignment_value_type, ?string $lhs_var_id, bool &$has_valid_assignment_type, bool &$has_regular_setter): ?Assigned_Property
    {
        if ($lhs_type_part instanceof T_Null) {
            return null;
        }
        if ($lhs_type_part instanceof T_False && $lhs_type->ignore_falsable_issues && count($lhs_type->get_atomic_types()) > 1) {
            return null;
        }
        if (!$lhs_type_part instanceof T_Object && !$lhs_type_part instanceof T_Named_Object) {
            $invalid_assignment_types[] = (string) $lhs_type_part;
            return null;
        }
        $has_valid_assignment_type = true;
        // stdClass and SimpleXMLElement are special cases where we cannot infer the return types
        // but we don't want to throw an error
        // Hack has a similar issue: https://github.com/facebook/hhvm/issues/5164
        if ($lhs_type_part instanceof T_Object || in_array(strtolower($lhs_type_part->value), Config::get_instance()->get_universal_object_crates() + ['dateinterval', 'domdocument', 'domnode'], true)) {
            if ($var_id) {
                if ($lhs_type_part instanceof T_Named_Object && strtolower($lhs_type_part->value) === 'stdclass') {
                    $context->vars_in_scope[$var_id] = $assignment_value_type;
                } else {
                    $context->vars_in_scope[$var_id] = Type::get_mixed();
                }
            }
            return null;
        }
        if (Expression_Analyzer::is_mock($lhs_type_part->value)) {
            if ($var_id) {
                $context->vars_in_scope[$var_id] = Type::get_mixed();
            }
            return null;
        }
        $intersection_types = $lhs_type_part->get_intersection_types() ?: [];
        $fq_class_name = $lhs_type_part->value;
        $override_property_visibility = false;
        $class_exists = false;
        $interface_exists = false;
        if (!$codebase->class_exists($lhs_type_part->value)) {
            if ($codebase->interface_exists($lhs_type_part->value)) {
                $interface_exists = true;
                $interface_storage = $codebase->classlike_storage_provider->get(strtolower($lhs_type_part->value));
                $override_property_visibility = $interface_storage->override_property_visibility;
                foreach ($intersection_types as $intersection_type) {
                    if ($intersection_type instanceof T_Named_Object && $codebase->class_exists($intersection_type->value)) {
                        $fq_class_name = $intersection_type->value;
                        $class_exists = true;
                        break;
                    }
                }
                // Test if the property has a 'set' hook
                $interface_property = $stmt->name instanceof Php_Parser\Node\Identifier ? $interface_storage->properties[$stmt->name->name] ?? null : null;
                $has_set_hook = $codebase->analysis_php_version_id >= 80400 && $interface_property?->hook_set !== null;
                if (!$class_exists && !$has_set_hook) {
                    if (Issue_Buffer::accepts(new No_Interface_Properties('Interfaces cannot have properties', new Code_Location($statements_analyzer->get_source(), $stmt), $lhs_type_part->value), $statements_analyzer->get_suppressed_issues())) {
                        return null;
                    }
                    if (!$codebase->methods->method_exists(new Method_Identifier($fq_class_name, '__set'))) {
                        return null;
                    }
                }
            }
            if (!$class_exists && !$interface_exists) {
                Issue_Buffer::maybe_add(new Undefined_Class('Cannot set properties of undefined class ' . $lhs_type_part->value, new Code_Location($statements_analyzer->get_source(), $stmt), $lhs_type_part->value), $statements_analyzer->get_suppressed_issues());
                return null;
            }
        } else {
            $class_exists = true;
        }
        $property_id = $fq_class_name . '::$' . $prop_name;
        $has_magic_setter = false;
        $set_method_id = new Method_Identifier($fq_class_name, '__set');
        if ((!$codebase->properties->property_exists($property_id, false, $statements_analyzer, $context) || $lhs_var_id !== '$this' && $fq_class_name !== $context->self && Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues(), false) !== true) && $codebase->methods->method_exists($set_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path())) {
            $has_magic_setter = true;
            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            if ($var_id) {
                if (isset($class_storage->pseudo_property_set_types['$' . $prop_name])) {
                    $class_property_type = Type_Expander::expand_union($codebase, $class_storage->pseudo_property_set_types['$' . $prop_name], $fq_class_name, $fq_class_name, $class_storage->parent_class);
                    $has_regular_setter = true;
                    if (!$context->collect_initializations && !$context->collect_mutations) {
                        self::taint_property($statements_analyzer, $stmt, $property_id, $class_storage, $assignment_value_type, $context);
                    }
                    return new Assigned_Property($class_property_type, $property_id, $assignment_value_type);
                }
            }
            if ($assignment_value) {
                self::analyze_set_call($var_id, $context, $statements_analyzer, $stmt, $prop_name, $assignment_value);
            }
            /*
             * If we have an explicit list of all allowed magic properties on the class, and we're
             * not in that list, fall through
             */
            if ($var_id === null || !$class_storage->has_sealed_properties($codebase->config)) {
                if (!$context->collect_initializations && !$context->collect_mutations) {
                    self::taint_property($statements_analyzer, $stmt, $property_id, $class_storage, $assignment_value_type, $context);
                }
                return null;
            }
            if (!$class_exists) {
                Issue_Buffer::maybe_add(new Undefined_Magic_Property_Assignment('Magic instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
        }
        if (!$class_exists) {
            return null;
        }
        $has_regular_setter = true;
        if ($stmt->var instanceof Php_Parser\Node\Expr\Variable && $stmt->var->name === 'this' && $context->self) {
            $self_property_id = $context->self . '::$' . $prop_name;
            if ($self_property_id !== $property_id && $codebase->properties->property_exists($self_property_id, false, $statements_analyzer, $context)) {
                $property_id = $self_property_id;
            }
        }
        if ($statements_analyzer->data_flow_graph && !$context->collect_initializations && !$context->collect_mutations) {
            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            self::taint_property($statements_analyzer, $stmt, $property_id, $class_storage, $assignment_value_type, $context);
        }
        if (!$codebase->properties->property_exists($property_id, false, $statements_analyzer, $context, new Code_Location($statements_analyzer->get_source(), $stmt)) || $codebase->properties->has_storage($property_id) && $codebase->properties->get_storage($property_id)->is_static) {
            if ($stmt->var instanceof Php_Parser\Node\Expr\Variable && $stmt->var->name === 'this') {
                // if this is a proper error, we'll see it on the first pass
                if ($context->collect_mutations) {
                    return null;
                }
                Issue_Buffer::maybe_add(new Undefined_This_Property_Assignment('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            } else if ($has_magic_setter) {
                Issue_Buffer::maybe_add(new Undefined_Magic_Property_Assignment('Magic instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Undefined_Property_Assignment('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            return null;
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $property_id);
        }
        if (!$override_property_visibility) {
            if (!$context->collect_mutations) {
                if (Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
                    return null;
                }
            } else if (Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues(), false) !== true) {
                return null;
            }
        }
        $declaring_property_class = (string) $codebase->properties->get_declaring_class_for_property($property_id, false);
        self::handle_property_renames($codebase, $declaring_property_class, $prop_name, $stmt, $statements_analyzer->get_file_path());
        $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        if (isset($declaring_class_storage->properties[$prop_name])) {
            $property_storage = $declaring_class_storage->properties[$prop_name];
            if ($property_storage->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Property($property_id . ' is marked deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            if ($context->self && !Namespace_Analyzer::is_within_any($context->self, $property_storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Property($property_id . ' is internal to ' . Internal_Class::list_to_phrase($property_storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            self::track_property_impurity($statements_analyzer, $stmt, $property_id, $property_storage, $declaring_class_storage, $context);
            if (!$property_storage->readonly && !$context->collect_mutations && !$context->collect_initializations && $lhs_var_id !== null && isset($context->vars_in_scope[$lhs_var_id]) && !$context->vars_in_scope[$lhs_var_id]->allow_mutations) {
                if ($context->mutation_free) {
                    Issue_Buffer::maybe_add(new Impure_Property_Assignment('Cannot assign to a property from a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                    $statements_analyzer->get_source()->inferred_impure = true;
                }
            }
            if ($property_storage->getter_method) {
                $getter_id = $lhs_var_id . '->' . $property_storage->getter_method . '()';
                unset($context->vars_in_scope[$getter_id]);
            }
        }
        $class_property_type = $codebase->properties->get_property_type($property_id, true, $statements_analyzer, $context);
        if (!$class_property_type || isset($declaring_class_storage->properties[$prop_name]) && !$declaring_class_storage->properties[$prop_name]->type_location) {
            if (!$class_property_type) {
                $class_property_type = Type::get_mixed();
            }
            $source_analyzer = $statements_analyzer->get_source()->get_source();
            if ($lhs_var_id === '$this' && $source_analyzer instanceof Class_Analyzer) {
                $source_analyzer->inferred_property_types[$prop_name] = Type::combine_union_types($assignment_value_type, $source_analyzer->inferred_property_types[$prop_name] ?? null);
            }
        }
        if (!$class_property_type->is_mixed()) {
            $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            $class_property_type = Type_Expander::expand_union($codebase, $class_property_type, $fq_class_name, $lhs_type_part, $declaring_class_storage->parent_class, true, false, $class_storage->final);
            $class_property_type = Methods::localize_type($codebase, $class_property_type, $fq_class_name, $declaring_property_class);
            if ($lhs_type_part instanceof T_Generic_Object) {
                $class_property_type = Atomic_Property_Fetch_Analyzer::localize_property_type($codebase, $class_property_type, $lhs_type_part, $class_storage, $declaring_class_storage);
            }
            $assignment_value_type = Methods::localize_type($codebase, $assignment_value_type, $fq_class_name, $declaring_property_class);
            if (!$class_property_type->has_mixed() && $assignment_value_type->has_mixed()) {
                $origin_locations = [];
                if ($statements_analyzer->data_flow_graph instanceof Variable_Use_Graph) {
                    foreach ($assignment_value_type->parent_nodes as $parent_node) {
                        $origin_locations = [...$origin_locations, ...$statements_analyzer->data_flow_graph->get_origin_locations($parent_node)];
                    }
                }
                $origin_location = count($origin_locations) === 1 ? reset($origin_locations) : null;
                $message = $var_id ? 'Unable to determine the type that ' . $var_id . ' is being assigned to' : 'Unable to determine the type of this assignment';
                if ($origin_location && $origin_location->get_line_number() === $stmt->get_line()) {
                    $origin_location = null;
                }
                Issue_Buffer::maybe_add(new Mixed_Assignment($message, new Code_Location($statements_analyzer->get_source(), $stmt), $origin_location), $statements_analyzer->get_suppressed_issues());
            }
        }
        return new Assigned_Property($class_property_type, $property_id, $assignment_value_type);
    }
    private static function handle_property_renames(Codebase $codebase, string $declaring_property_class, string $prop_name, Property_Fetch $stmt, string $file_path): void
    {
        if (!$codebase->properties_to_rename) {
            return;
        }
        $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;
        foreach ($codebase->properties_to_rename as $original_property_id => $new_property_name) {
            if ($declaring_property_id === $original_property_id) {
                $file_manipulations = [new File_Manipulation((int) $stmt->name->get_attribute('startFilePos'), (int) $stmt->name->get_attribute('endFilePos') + 1, $new_property_name)];
                File_Manipulation_Buffer::add($file_path, $file_manipulations);
            }
        }
    }
    public static function get_expanded_property_type(Codebase $codebase, string $fq_class_name, string $property_name, Class_Like_Storage $storage): ?Union
    {
        $property_class_name = $codebase->properties->get_declaring_class_for_property($fq_class_name . '::$' . $property_name, true);
        if ($property_class_name === null) {
            return null;
        }
        $property_class_storage = $codebase->classlike_storage_provider->get($property_class_name);
        $property_storage = $property_class_storage->properties[$property_name];
        if (!$property_storage->type) {
            return null;
        }
        $property_type = $property_storage->type;
        $fleshed_out_type = !$property_type->is_mixed() ? Type_Expander::expand_union($codebase, $property_type, $fq_class_name, $fq_class_name, $storage->parent_class, true, false, $storage->final) : $property_type;
        $class_template_params = Class_Template_Param_Collector::collect($codebase, $property_class_storage, $storage, null, new T_Named_Object($fq_class_name), true);
        $template_result = new Template_Result($class_template_params ?: [], []);
        if ($class_template_params) {
            return Template_Standin_Type_Replacer::replace($fleshed_out_type, $template_result, $codebase, null, null);
        }
        return $fleshed_out_type;
    }
    private static function analyze_set_call(?string $var_id, Context $context, Statements_Analyzer $statements_analyzer, Property_Fetch $stmt, string $prop_name, Expr $assignment_value): void
    {
        if ($var_id) {
            $context->remove_var_from_conflicting_clauses($var_id, Type::get_mixed(), $statements_analyzer);
            $context->remove_possible_reference($var_id);
        }
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;
        $fake_method_call = new Virtual_Method_Call($stmt->var, new Virtual_Identifier('__set', $stmt->name->get_attributes()), [new Virtual_Arg(new Virtual_String($prop_name, $stmt->name->get_attributes())), new Virtual_Arg($assignment_value)]);
        $suppressed_issues = $statements_analyzer->get_suppressed_issues();
        if (!in_array('PossiblyNullReference', $suppressed_issues, true)) {
            $statements_analyzer->add_suppressed_issues(['PossiblyNullReference']);
        }
        Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context, false);
        if (!in_array('PossiblyNullReference', $suppressed_issues, true)) {
            $statements_analyzer->remove_suppressed_issues(['PossiblyNullReference']);
        }
        $statements_analyzer->node_data = $old_data_provider;
    }
}