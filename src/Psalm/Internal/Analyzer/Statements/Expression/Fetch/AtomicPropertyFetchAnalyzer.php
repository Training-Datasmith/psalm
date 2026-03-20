<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use InvalidArgumentException;
use Php_Parser;
use Php_Parser\Node\Expr\Property_Fetch;
use Php_Parser\Node\Expr\Static_Property_Fetch;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Assignment\Instance_Property_Assignment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Codebase\Taint_Flow_Graph;
use Psalm\Internal\Data_Flow\Data_Flow_Node;
use Psalm\Internal\Data_Flow\Taint_Source;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Deprecated_Property;
use Psalm\Issue\Impure_Property_Fetch;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Internal_Property;
use Psalm\Issue\Missing_Property_Type;
use Psalm\Issue\No_Interface_Properties;
use Psalm\Issue\Undefined_Class;
use Psalm\Issue\Undefined_Docblock_Class;
use Psalm\Issue\Undefined_Magic_Property_Fetch;
use Psalm\Issue\Undefined_Property_Fetch;
use Psalm\Issue\Undefined_This_Property_Fetch;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Method_Call;
use Psalm\Node\Scalar\Virtual_String;
use Psalm\Node\Virtual_Arg;
use Psalm\Node\Virtual_Identifier;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_search;
use function count;
use function in_array;
use function strtolower;
use const ARRAY_FILTER_USE_KEY;
/**
 * @internal
 */
final class Atomic_Property_Fetch_Analyzer
{
    /**
     * @param array<string> $invalid_fetch_types $invalid_fetch_types
     * @psalm-suppress ComplexMethod Unavoidably complex method.
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, Context $context, bool $in_assignment, ?string $var_id, ?string $stmt_var_id, Union $stmt_var_type, Atomic $lhs_type_part, string $prop_name, bool &$has_valid_fetch_type, array &$invalid_fetch_types, bool $is_static_access = false): void
    {
        if ($lhs_type_part instanceof T_Null) {
            return;
        }
        if ($lhs_type_part instanceof T_Mixed) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            return;
        }
        if ($lhs_type_part instanceof T_False && $stmt_var_type->ignore_falsable_issues) {
            return;
        }
        if (!$lhs_type_part instanceof T_Named_Object && !$lhs_type_part instanceof T_Object) {
            $invalid_fetch_types[] = (string) $lhs_type_part;
            return;
        }
        if ($lhs_type_part instanceof T_Object_With_Properties) {
            if (!isset($lhs_type_part->properties[$prop_name])) {
                return;
            }
            $has_valid_fetch_type = true;
            $stmt_type = $statements_analyzer->node_data->get_type($stmt);
            $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types(Type_Expander::expand_union($statements_analyzer->get_codebase(), $lhs_type_part->properties[$prop_name], null, null, null, true, true, false, true, false, true), $stmt_type));
            return;
        }
        $intersection_types = [];
        if (!$lhs_type_part instanceof T_Object) {
            $intersection_types = $lhs_type_part->get_intersection_types();
        }
        // stdClass and SimpleXMLElement are special cases where we cannot infer the return types
        // but we don't want to throw an error
        // Hack has a similar issue: https://github.com/facebook/hhvm/issues/5164
        if ($lhs_type_part instanceof T_Object || in_array(strtolower($lhs_type_part->value), Config::get_instance()->get_universal_object_crates(), true) && $intersection_types === []) {
            $has_valid_fetch_type = true;
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            return;
        }
        if (Expression_Analyzer::is_mock($lhs_type_part->value)) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            return;
        }
        $fq_class_name = $lhs_type_part->value;
        $override_property_visibility = false;
        $has_magic_getter = false;
        $class_exists = false;
        $codebase = $statements_analyzer->get_codebase();
        if (!$codebase->class_exists($lhs_type_part->value) && !$codebase->classlikes->enum_exists($lhs_type_part->value)) {
            $interface_exists = false;
            self::handle_non_existent_class($statements_analyzer, $codebase, $stmt, $lhs_type_part, $intersection_types, $class_exists, $interface_exists, $fq_class_name, $override_property_visibility);
            if (!$class_exists && !$interface_exists) {
                return;
            }
        } else {
            $class_exists = true;
        }
        $class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
        $config = $statements_analyzer->get_project_analyzer()->get_config();
        $property_id = $fq_class_name . '::$' . $prop_name;
        if ($class_storage->is_enum || in_array('UnitEnum', $codebase->get_parent_interfaces($fq_class_name))) {
            if ($prop_name === 'value' && !$class_storage->is_enum) {
                $has_valid_fetch_type = true;
                $statements_analyzer->node_data->set_type($stmt, new Union([new T_String(), new T_Int()]));
            } elseif ($prop_name === 'value' && $class_storage->enum_type !== null && $class_storage->enum_cases) {
                $has_valid_fetch_type = true;
                self::handle_enum_value($statements_analyzer, $stmt, $stmt_var_type, $class_storage);
            } elseif ($prop_name === 'name') {
                $has_valid_fetch_type = true;
                self::handle_enum_name($statements_analyzer, $stmt, $stmt_var_type, $class_storage);
            } else {
                self::handle_non_existent_property($statements_analyzer, $codebase, $stmt, $context, $config, $class_storage, $prop_name, $lhs_type_part, $fq_class_name, $property_id, $in_assignment, $stmt_var_id, $has_magic_getter, $var_id, $has_valid_fetch_type);
            }
            return;
        }
        $naive_property_exists = $codebase->properties->property_exists($property_id, !$in_assignment, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null);
        // add method before changing fq_class_name
        $get_method_id = new Method_Identifier($fq_class_name, '__get');
        if (!$naive_property_exists) {
            if ($class_storage->named_mixins) {
                foreach ($class_storage->named_mixins as $mixin) {
                    $new_property_id = $mixin->value . '::$' . $prop_name;
                    try {
                        $new_class_storage = $codebase->classlike_storage_provider->get($mixin->value);
                    } catch (InvalidArgumentException) {
                        $new_class_storage = null;
                    }
                    if ($new_class_storage && ($codebase->properties->property_exists($new_property_id, !$in_assignment, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null) || isset($new_class_storage->pseudo_property_get_types['$' . $prop_name]))) {
                        $fq_class_name = $mixin->value;
                        $lhs_type_part = $mixin;
                        $class_storage = $new_class_storage;
                        if (!isset($new_class_storage->pseudo_property_get_types['$' . $prop_name])) {
                            $naive_property_exists = true;
                        }
                        $property_id = $new_property_id;
                    }
                }
            } elseif ($intersection_types !== [] && !$class_storage->final) {
                foreach ($intersection_types as $intersection_type) {
                    self::analyze($statements_analyzer, $stmt, $context, $in_assignment, $var_id, $stmt_var_id, $stmt_var_type, $intersection_type, $prop_name, $has_valid_fetch_type, $invalid_fetch_types, $is_static_access);
                    if ($has_valid_fetch_type) {
                        return;
                    }
                }
            }
        }
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, true, $statements_analyzer);
        if (self::property_fetch_can_be_analyzed($statements_analyzer, $codebase, $stmt, $context, $fq_class_name, $prop_name, $lhs_type_part, $property_id, $has_magic_getter, $stmt_var_id, $naive_property_exists, $override_property_visibility, $class_exists, $declaring_property_class, $class_storage, $get_method_id, $in_assignment) === false) {
            return;
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $property_id);
        }
        if (!$naive_property_exists && $fq_class_name !== $context->self && $context->self && $codebase->classlikes->class_extends($fq_class_name, $context->self) && $codebase->properties->property_exists($context->self . '::$' . $prop_name, true, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null)) {
            $property_id = $context->self . '::$' . $prop_name;
        } elseif (!$naive_property_exists || !$is_static_access && $codebase->properties->has_storage($property_id) && $codebase->properties->get_storage($property_id)->is_static) {
            self::handle_non_existent_property($statements_analyzer, $codebase, $stmt, $context, $config, $class_storage, $prop_name, $lhs_type_part, $declaring_property_class, $property_id, $in_assignment, $stmt_var_id, $has_magic_getter, $var_id, $has_valid_fetch_type);
            return;
        }
        if (!$override_property_visibility) {
            if (Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
                return;
            }
        }
        // FIXME: the following line look superfluous, but removing it makes
        // Psalm\Tests\PropertyTypeTest::testValidCode with data set "callInParentContext"
        // fail
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, true, $statements_analyzer);
        if ($declaring_property_class === null) {
            return;
        }
        if ($codebase->properties_to_rename) {
            $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;
            foreach ($codebase->properties_to_rename as $original_property_id => $new_property_name) {
                if ($declaring_property_id === $original_property_id) {
                    $file_manipulations = [new File_Manipulation((int) $stmt->name->get_attribute('startFilePos'), (int) $stmt->name->get_attribute('endFilePos') + 1, $new_property_name)];
                    File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                }
            }
        }
        $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        if (isset($declaring_class_storage->properties[$prop_name])) {
            self::check_property_deprecation($prop_name, $declaring_property_class, $stmt, $statements_analyzer);
            $property_storage = $declaring_class_storage->properties[$prop_name];
            if ($context->self && !Namespace_Analyzer::is_within_any($context->self, $property_storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Property($property_id . ' is internal to ' . Internal_Class::list_to_phrase($property_storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            if ($context->inside_unset) {
                Instance_Property_Assignment_Analyzer::track_property_impurity($statements_analyzer, $stmt, $property_id, $property_storage, $declaring_class_storage, $context);
            }
        }
        $class_property_type = self::get_class_property_type($statements_analyzer, $codebase, $config, $context, $stmt, $class_storage, $declaring_class_storage, $property_id, $fq_class_name, $prop_name, $lhs_type_part);
        if (!$context->collect_mutations && !$context->collect_initializations && !($class_storage->external_mutation_free && $class_property_type->allow_mutations)) {
            if ($context->pure) {
                Issue_Buffer::maybe_add(new Impure_Property_Fetch('Cannot access a property on a mutable object from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_impure = true;
            }
        }
        self::process_taints($statements_analyzer, $stmt, $class_property_type, $property_id, $class_storage, $in_assignment, $context);
        if ($class_storage->mutation_free) {
            $class_property_type = $class_property_type->set_properties(['has_mutations' => false]);
        }
        $stmt_type = $statements_analyzer->node_data->get_type($stmt);
        $has_valid_fetch_type = true;
        $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types($class_property_type, $stmt_type));
    }
    /**
     * @param PropertyFetch|StaticPropertyFetch $stmt
     */
    public static function check_property_deprecation(string $prop_name, string $declaring_property_class, Php_Parser\Node\Expr $stmt, Statements_Analyzer $statements_analyzer): void
    {
        $property_id = $declaring_property_class . '::$' . $prop_name;
        $codebase = $statements_analyzer->get_codebase();
        $declaring_class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        if (isset($declaring_class_storage->properties[$prop_name])) {
            $property_storage = $declaring_class_storage->properties[$prop_name];
            if ($property_storage->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Property($property_id . ' is marked deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    private static function property_fetch_can_be_analyzed(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Property_Fetch $stmt, Context $context, string $fq_class_name, string $prop_name, T_Named_Object $lhs_type_part, string &$property_id, bool &$has_magic_getter, ?string $stmt_var_id, bool $naive_property_exists, bool $override_property_visibility, bool $class_exists, ?string $declaring_property_class, Class_Like_Storage $class_storage, Method_Identifier $get_method_id, bool $in_assignment): bool
    {
        if ((!$naive_property_exists || $stmt_var_id !== '$this' && $fq_class_name !== $context->self && Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues(), false) !== true) && $codebase->methods->method_exists($get_method_id, $context->calling_method_id, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null, !$context->collect_initializations && !$context->collect_mutations ? $statements_analyzer : null, $statements_analyzer->get_file_path())) {
            $has_magic_getter = true;
            if (isset($class_storage->pseudo_property_get_types['$' . $prop_name])) {
                $stmt_type = Type_Expander::expand_union($codebase, $class_storage->pseudo_property_get_types['$' . $prop_name], $class_storage->name, $class_storage->name, $class_storage->parent_class);
                if (count($template_types = $class_storage->get_class_template_types()) !== 0) {
                    if (!$lhs_type_part instanceof T_Generic_Object) {
                        $lhs_type_part = new T_Generic_Object($lhs_type_part->value, $template_types);
                    }
                    $stmt_type = self::localize_property_type($codebase, $stmt_type, $lhs_type_part, $class_storage, $declaring_property_class ? $codebase->classlike_storage_provider->get($declaring_property_class) : $class_storage);
                }
                self::process_taints($statements_analyzer, $stmt, $stmt_type, $property_id, $class_storage, $in_assignment, $context);
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                return false;
            }
            $old_data_provider = $statements_analyzer->node_data;
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            $statements_analyzer->node_data->set_type($stmt->var, new Union([$lhs_type_part]));
            $fake_method_call = new Virtual_Method_Call($stmt->var, new Virtual_Identifier('__get', $stmt->name->get_attributes()), [new Virtual_Arg(new Virtual_String($prop_name, $stmt->name->get_attributes()))]);
            $suppressed_issues = $statements_analyzer->get_suppressed_issues();
            if (!in_array('InternalMethod', $suppressed_issues, true)) {
                $statements_analyzer->add_suppressed_issues(['InternalMethod']);
            }
            Method_Call_Analyzer::analyze($statements_analyzer, $fake_method_call, $context, false);
            if (!in_array('InternalMethod', $suppressed_issues, true)) {
                $statements_analyzer->remove_suppressed_issues(['InternalMethod']);
            }
            $fake_method_call_type = $statements_analyzer->node_data->get_type($fake_method_call);
            $statements_analyzer->node_data = $old_data_provider;
            if ($fake_method_call_type) {
                $stmt_type = $statements_analyzer->node_data->get_type($stmt);
                $statements_analyzer->node_data->set_type($stmt, Type::combine_union_types($fake_method_call_type, $stmt_type));
            } else {
                $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            }
            /*
             * If we have an explicit list of all allowed magic properties on the class, and we're
             * not in that list, fall through
             */
            if (!$class_storage->has_sealed_properties($codebase->config) && !$override_property_visibility) {
                return false;
            }
            if (!$class_exists) {
                $property_id = $lhs_type_part->value . '::$' . $prop_name;
                Issue_Buffer::maybe_add(new Undefined_Magic_Property_Fetch('Magic instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                return false;
            }
        }
        return true;
    }
    public static function localize_property_type(Codebase $codebase, Union $class_property_type, T_Generic_Object $lhs_type_part, Class_Like_Storage $property_class_storage, Class_Like_Storage $property_declaring_class_storage): Union
    {
        $template_types = Call_Analyzer::get_template_types_for_call($codebase, $property_declaring_class_storage, $property_declaring_class_storage->name, $property_class_storage, $property_class_storage->template_types ?: []);
        $extended_types = $property_class_storage->template_extended_params;
        if ($template_types) {
            if ($property_class_storage->template_types) {
                foreach ($lhs_type_part->type_params as $param_offset => $lhs_param_type) {
                    $i = -1;
                    foreach ($property_class_storage->template_types as $calling_param_name => $_) {
                        $i++;
                        if ($i === $param_offset) {
                            $template_types[$calling_param_name][$property_class_storage->name] = $lhs_param_type;
                            break;
                        }
                    }
                }
            }
            foreach ($template_types as $type_name => $_) {
                if (isset($extended_types[$property_declaring_class_storage->name][$type_name])) {
                    $mapped_type = $extended_types[$property_declaring_class_storage->name][$type_name];
                    foreach ($mapped_type->get_atomic_types() as $mapped_type_atomic) {
                        if (!$mapped_type_atomic instanceof T_Template_Param) {
                            continue;
                        }
                        $param_name = $mapped_type_atomic->param_name;
                        $position = false;
                        if (isset($property_class_storage->template_types[$param_name])) {
                            $position = array_search($param_name, array_keys($property_class_storage->template_types), true);
                        }
                        if ($position !== false && isset($lhs_type_part->type_params[$position])) {
                            $template_types[$type_name][$property_declaring_class_storage->name] = $lhs_type_part->type_params[$position];
                        }
                    }
                }
            }
            $class_property_type = Template_Inferred_Type_Replacer::replace($class_property_type, new Template_Result([], $template_types), $codebase);
        }
        return $class_property_type;
    }
    public static function process_taints(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, Union &$type, string $property_id, Class_Like_Storage $class_storage, bool $in_assignment, ?Context $context = null): void
    {
        if (!$statements_analyzer->data_flow_graph) {
            return;
        }
        $data_flow_graph = $statements_analyzer->data_flow_graph;
        $added_taints = [];
        $removed_taints = [];
        if ($context) {
            $codebase = $statements_analyzer->get_codebase();
            $event = new Add_Remove_Taints_Event($stmt, $context, $statements_analyzer, $codebase);
            $added_taints = $codebase->config->event_dispatcher->dispatch_add_taints($event);
            $removed_taints = $codebase->config->event_dispatcher->dispatch_remove_taints($event);
        }
        if ($class_storage->specialize_instance) {
            $var_id = Expression_Identifier::get_extended_var_id($stmt->var, null, $statements_analyzer);
            $var_property_id = Expression_Identifier::get_extended_var_id($stmt, null, $statements_analyzer);
            if ($var_id) {
                $var_type = $statements_analyzer->node_data->get_type($stmt->var);
                if ($statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph && $var_type && in_array('TaintedInput', $statements_analyzer->get_suppressed_issues())) {
                    $statements_analyzer->node_data->set_type($stmt->var, $var_type->set_parent_nodes([]));
                    return;
                }
                $var_location = new Code_Location($statements_analyzer->get_source(), $stmt->var);
                $property_location = new Code_Location($statements_analyzer->get_source(), $stmt);
                $var_node = Data_Flow_Node::get_for_assignment($var_id, $var_location);
                $data_flow_graph->add_node($var_node);
                $property_node = Data_Flow_Node::get_for_assignment($var_property_id ?: $var_id . '->$property', $property_location);
                $data_flow_graph->add_node($property_node);
                $data_flow_graph->add_path($var_node, $property_node, 'property-fetch' . ($stmt->name instanceof Php_Parser\Node\Identifier ? '-' . $stmt->name : ''), $added_taints, $removed_taints);
                if ($var_type && $var_type->parent_nodes) {
                    foreach ($var_type->parent_nodes as $parent_node) {
                        $data_flow_graph->add_path($parent_node, $var_node, '=', $added_taints, $removed_taints);
                    }
                }
                $type = $type->set_parent_nodes([$property_node->id => $property_node], true);
                $taints = array_diff($added_taints, $removed_taints);
                if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
                    $taint_source = Taint_Source::from_node($var_node);
                    $taint_source->taints = $taints;
                    $statements_analyzer->data_flow_graph->add_source($taint_source);
                }
            }
        } else {
            self::process_unspecial_taints($statements_analyzer, $stmt, $type, $property_id, $in_assignment, $added_taints, $removed_taints);
        }
    }
    /**
     * @param ?array<string> $added_taints
     * @param ?array<string> $removed_taints
     */
    public static function process_unspecial_taints(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt, Union &$type, string $property_id, bool $in_assignment, ?array $added_taints, ?array $removed_taints): void
    {
        if (!$statements_analyzer->data_flow_graph) {
            return;
        }
        $data_flow_graph = $statements_analyzer->data_flow_graph;
        $var_property_id = Expression_Identifier::get_extended_var_id($stmt, null, $statements_analyzer);
        $property_location = new Code_Location($statements_analyzer->get_source(), $stmt);
        $localized_property_node = Data_Flow_Node::get_for_assignment($var_property_id ?: $property_id, $property_location);
        $data_flow_graph->add_node($localized_property_node);
        $property_node = new Data_Flow_Node($property_id, $property_id, null);
        $data_flow_graph->add_node($property_node);
        if ($in_assignment) {
            $data_flow_graph->add_path($localized_property_node, $property_node, 'property-assignment', $added_taints, $removed_taints);
        } else {
            $data_flow_graph->add_path($property_node, $localized_property_node, 'property-fetch', $added_taints, $removed_taints);
        }
        $type = $type->set_parent_nodes([$localized_property_node->id => $localized_property_node], true);
        $taints = array_diff($added_taints ?? [], $removed_taints ?? []);
        if ($taints !== [] && $statements_analyzer->data_flow_graph instanceof Taint_Flow_Graph) {
            $taint_source = Taint_Source::from_node($localized_property_node);
            $taint_source->taints = $taints;
            $statements_analyzer->data_flow_graph->add_source($taint_source);
        }
    }
    private static function handle_enum_name(Statements_Analyzer $statements_analyzer, Property_Fetch $stmt, Union $stmt_var_type, Class_Like_Storage $class_storage): void
    {
        $relevant_enum_cases = array_filter($stmt_var_type->get_atomic_types(), static fn(Atomic $type): bool => $type instanceof T_Enum_Case);
        $relevant_enum_case_names = array_map(static fn(T_Enum_Case $enum_case): string => $enum_case->case_name, $relevant_enum_cases);
        if (empty($relevant_enum_case_names)) {
            $relevant_enum_case_names = array_keys($class_storage->enum_cases);
        }
        $statements_analyzer->node_data->set_type($stmt, empty($relevant_enum_case_names) ? Type::get_non_empty_string() : new Union(array_map(static fn(string $name): T_String => Type::get_atomic_string_from_literal($name), $relevant_enum_case_names)));
    }
    private static function handle_enum_value(Statements_Analyzer $statements_analyzer, Property_Fetch $stmt, Union $stmt_var_type, Class_Like_Storage $class_storage): void
    {
        $relevant_enum_cases = array_filter($stmt_var_type->get_atomic_types(), static fn(Atomic $type): bool => $type instanceof T_Enum_Case);
        $relevant_enum_case_names = array_map(static fn(T_Enum_Case $enum_case): string => $enum_case->case_name, $relevant_enum_cases);
        $enum_cases = $class_storage->enum_cases;
        if (!empty($relevant_enum_case_names)) {
            // If we have a known subset of enum cases, include only those
            $enum_cases = array_filter($enum_cases, static fn(string $key): bool => in_array($key, $relevant_enum_case_names, true), ARRAY_FILTER_USE_KEY);
        }
        $case_values = [];
        foreach ($enum_cases as $enum_case) {
            $case_value = $enum_case->get_value($statements_analyzer->get_codebase()->classlikes);
            $case_values[] = $case_value ?? new T_Mixed();
        }
        /** @psalm-suppress ArgumentTypeCoercion */
        $statements_analyzer->node_data->set_type($stmt, new Union($case_values));
    }
    private static function handle_undefined_property(Context $context, Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, ?string $stmt_var_id, string $property_id, bool $has_magic_getter, ?string $var_id): void
    {
        if ($context->inside_isset || $context->collect_initializations) {
            if ($context->pure) {
                Issue_Buffer::maybe_add(new Impure_Property_Fetch('Cannot access a property on a mutable object from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
            } elseif ($context->inside_isset && $statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                $statements_analyzer->get_source()->inferred_impure = true;
            }
            return;
        }
        if ($stmt_var_id === '$this') {
            Issue_Buffer::maybe_add(new Undefined_This_Property_Fetch('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
        } else if ($has_magic_getter) {
            Issue_Buffer::maybe_add(new Undefined_Magic_Property_Fetch('Magic instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
        } else {
            Issue_Buffer::maybe_add(new Undefined_Property_Fetch('Instance property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
        }
        $stmt_type = Type::get_mixed();
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        if ($var_id) {
            $context->vars_in_scope[$var_id] = $stmt_type;
        }
    }
    /**
     * @param  array<Atomic>     $intersection_types
     */
    private static function handle_non_existent_class(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Property_Fetch $stmt, T_Named_Object $lhs_type_part, array $intersection_types, bool &$class_exists, bool &$interface_exists, string &$fq_class_name, bool &$override_property_visibility): void
    {
        if ($codebase->interface_exists($lhs_type_part->value)) {
            $interface_exists = true;
            $interface_storage = $codebase->classlike_storage_provider->get($lhs_type_part->value);
            $override_property_visibility = $interface_storage->override_property_visibility;
            $intersects_with_enum = false;
            foreach ($intersection_types as $intersection_type) {
                if ($intersection_type instanceof T_Named_Object && $codebase->class_exists($intersection_type->value)) {
                    $fq_class_name = $intersection_type->value;
                    $class_exists = true;
                    return;
                }
                if ($intersection_type instanceof T_Named_Object && (in_array($intersection_type->value, ['UnitEnum', 'BackedEnum'], true) || in_array('UnitEnum', $codebase->get_parent_interfaces($intersection_type->value)))) {
                    $intersects_with_enum = true;
                }
            }
            // In PHP Core enum interfaces have properties
            $is_enum_interface = in_array($fq_class_name, ['UnitEnum', 'BackedEnum'], true) || in_array('UnitEnum', $codebase->get_parent_interfaces($fq_class_name)) || $intersects_with_enum;
            // Since PHP 8.4 interfaces can have hook properties
            $interface_property = $stmt->name instanceof Php_Parser\Node\Identifier ? $interface_storage->properties[$stmt->name->name] ?? null : null;
            $has_get_hook = $codebase->analysis_php_version_id >= 80400 && $interface_property?->hook_get !== null;
            if (!$class_exists && !$is_enum_interface && !$has_get_hook) {
                if (Issue_Buffer::accepts(new No_Interface_Properties('Interfaces cannot have properties', new Code_Location($statements_analyzer->get_source(), $stmt), $lhs_type_part->value), $statements_analyzer->get_suppressed_issues())) {
                    return;
                }
                if (!$codebase->method_exists($fq_class_name . '::__set')) {
                    return;
                }
            }
        }
        if (!$class_exists && !$interface_exists) {
            if ($lhs_type_part->from_docblock) {
                Issue_Buffer::maybe_add(new Undefined_Docblock_Class('Cannot get properties of undefined docblock class ' . $lhs_type_part->value, new Code_Location($statements_analyzer->get_source(), $stmt), $lhs_type_part->value), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Undefined_Class('Cannot get properties of undefined class ' . $lhs_type_part->value, new Code_Location($statements_analyzer->get_source(), $stmt), $lhs_type_part->value), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    private static function handle_non_existent_property(Statements_Analyzer $statements_analyzer, Codebase $codebase, Php_Parser\Node\Expr\Property_Fetch $stmt, Context $context, Config $config, Class_Like_Storage $class_storage, string $prop_name, T_Named_Object $lhs_type_part, ?string $declaring_property_class, string $property_id, bool $in_assignment, ?string $stmt_var_id, bool $has_magic_getter, ?string $var_id, bool &$has_valid_fetch_type): void
    {
        if (($config->use_phpdoc_property_without_magic_or_parent || $class_storage->has_attribute_including_parents('AllowDynamicProperties', $codebase)) && isset($class_storage->pseudo_property_get_types['$' . $prop_name])) {
            $stmt_type = $class_storage->pseudo_property_get_types['$' . $prop_name];
            if (count($template_types = $class_storage->get_class_template_types()) !== 0) {
                if (!$lhs_type_part instanceof T_Generic_Object) {
                    $lhs_type_part = new T_Generic_Object($lhs_type_part->value, $template_types);
                }
                $stmt_type = self::localize_property_type($codebase, $stmt_type, $lhs_type_part, $class_storage, $declaring_property_class ? $codebase->classlike_storage_provider->get($declaring_property_class) : $class_storage);
            }
            self::process_taints($statements_analyzer, $stmt, $stmt_type, $property_id, $class_storage, $in_assignment, $context);
            $has_valid_fetch_type = true;
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            return;
        }
        if ($class_storage->is_interface) {
            return;
        }
        self::handle_undefined_property($context, $statements_analyzer, $stmt, $stmt_var_id, $property_id, $has_magic_getter, $var_id);
    }
    private static function get_class_property_type(Statements_Analyzer $statements_analyzer, Codebase $codebase, Config $config, Context $context, Php_Parser\Node\Expr\Property_Fetch $stmt, Class_Like_Storage $class_storage, Class_Like_Storage $declaring_class_storage, string $property_id, string $fq_class_name, string $prop_name, T_Named_Object $lhs_type_part): Union
    {
        $class_property_type = $codebase->properties->get_property_type($property_id, false, $statements_analyzer, $context);
        if (!$class_property_type) {
            if ($declaring_class_storage->location && $config->is_in_project_dirs($declaring_class_storage->location->file_path)) {
                Issue_Buffer::maybe_add(new Missing_Property_Type('Property ' . $fq_class_name . '::$' . $prop_name . ' does not have a declared type', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            $class_property_type = Type::get_mixed();
        } else {
            $class_property_type = Type_Expander::expand_union($codebase, $class_property_type, $declaring_class_storage->name, $declaring_class_storage->name, $declaring_class_storage->parent_class);
            if (count($template_types = $declaring_class_storage->get_class_template_types()) !== 0) {
                if (!$lhs_type_part instanceof T_Generic_Object) {
                    $lhs_type_part = new T_Generic_Object($lhs_type_part->value, $template_types);
                }
                $class_property_type = self::localize_property_type($codebase, $class_property_type, $lhs_type_part, $class_storage, $declaring_class_storage);
            } elseif ($lhs_type_part instanceof T_Generic_Object) {
                $class_property_type = self::localize_property_type($codebase, $class_property_type, $lhs_type_part, $class_storage, $declaring_class_storage);
            }
        }
        return $class_property_type;
    }
}