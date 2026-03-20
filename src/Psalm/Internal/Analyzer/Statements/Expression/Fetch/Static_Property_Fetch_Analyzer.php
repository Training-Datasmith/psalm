<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Impure_Static_Property;
use Psalm\Issue\Parent_Not_Found;
use Psalm\Issue\Undefined_Property_Assignment;
use Psalm\Issue\Undefined_Property_Fetch;
use Psalm\Issue_Buffer;
use Psalm\Node\Expr\Virtual_Property_Fetch;
use Psalm\Node\Expr\Virtual_Static_Property_Fetch;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Type;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function count;
use function explode;
use function in_array;
use function md5;
use function strtolower;
/**
 * @internal
 */
final class Static_Property_Fetch_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Property_Fetch $stmt, Context $context): bool
    {
        if (!$stmt->class instanceof Php_Parser\Node\Name) {
            self::analyze_variable_static_property_fetch($statements_analyzer, $stmt->class, $stmt, $context);
            return true;
        }
        $codebase = $statements_analyzer->get_codebase();
        if (count($stmt->class->get_parts()) === 1 && in_array(strtolower($stmt->class->get_first()), ['self', 'static', 'parent'], true)) {
            if ($stmt->class->get_first() === 'parent') {
                $fq_class_name = $statements_analyzer->get_parent_fqcln();
                if ($fq_class_name === null) {
                    return !Issue_Buffer::accepts(new Parent_Not_Found('Cannot check property fetch on parent as this class does not extend another', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } else {
                $fq_class_name = (string) $context->self;
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
            if ($context->check_classes) {
                if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer->get_source(), $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues()) !== true) {
                    return false;
                }
            }
        }
        if ($fq_class_name && $codebase->methods_to_move && $context->calling_method_id && isset($codebase->methods_to_move[$context->calling_method_id])) {
            $destination_method_id = $codebase->methods_to_move[$context->calling_method_id];
            $codebase->classlikes->airlift_class_like_reference($fq_class_name, explode('::', $destination_method_id)[0], $statements_analyzer->get_file_path(), (int) $stmt->class->get_attribute('startFilePos'), (int) $stmt->class->get_attribute('endFilePos') + 1);
        }
        if ($fq_class_name) {
            $statements_analyzer->node_data->set_type($stmt->class, new Union([new T_Named_Object($fq_class_name)]));
        }
        if ($stmt->name instanceof Php_Parser\Node\Var_Like_Identifier) {
            $prop_name = $stmt->name->name;
        } else {
            $was_inside_general_use = $context->inside_general_use;
            $context->inside_general_use = true;
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return false;
            }
            $context->inside_general_use = $was_inside_general_use;
            if (($stmt_name_type = $statements_analyzer->node_data->get_type($stmt->name)) && $stmt_name_type->is_single_string_literal()) {
                $prop_name = $stmt_name_type->get_single_string_literal()->value;
            } else {
                $prop_name = null;
            }
        }
        if (!$prop_name) {
            if ($fq_class_name) {
                $codebase->analyzer->add_mixed_member_name(strtolower($fq_class_name) . '::$', $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            return true;
        }
        if (!$fq_class_name || !$context->check_variables || Expression_Analyzer::is_mock($fq_class_name)) {
            return true;
        }
        $var_id = Expression_Identifier::get_var_id($stmt, $context->self ?: $statements_analyzer->get_fqcln(), $statements_analyzer);
        $property_id = $fq_class_name . '::$' . $prop_name;
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $property_id);
        }
        if ($context->mutation_free) {
            Issue_Buffer::maybe_add(new Impure_Static_Property('Cannot use a static property in a mutation-free context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
        } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
            $statements_analyzer->get_source()->inferred_has_mutation = true;
            $statements_analyzer->get_source()->inferred_impure = true;
        }
        if ($var_id && $context->has_variable($var_id)) {
            $stmt_type = $context->vars_in_scope[$var_id];
            Atomic_Property_Fetch_Analyzer::process_unspecial_taints($statements_analyzer, $stmt, $stmt_type, $property_id, false, [], []);
            $context->vars_in_scope[$var_id] = $stmt_type;
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            if ($codebase->collect_references) {
                // log the appearance
                $codebase->properties->property_exists($property_id, true, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null);
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id());
            }
            return true;
        }
        if (!$codebase->properties->property_exists($property_id, true, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null)) {
            if ($context->inside_isset || !$context->check_classes) {
                return true;
            }
            Issue_Buffer::maybe_add(new Undefined_Property_Fetch('Static property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            return true;
        }
        $declaring_property_class = $codebase->properties->get_declaring_class_for_property($fq_class_name . '::$' . $prop_name, true, $statements_analyzer);
        if ($declaring_property_class === null) {
            return false;
        }
        Atomic_Property_Fetch_Analyzer::check_property_deprecation($prop_name, $declaring_property_class, $stmt, $statements_analyzer);
        $class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
        $property = $class_storage->properties[$prop_name];
        if (!$property->is_static) {
            if ($context->inside_isset) {
                return true;
            }
            if ($context->inside_assignment) {
                Issue_Buffer::maybe_add(new Undefined_Property_Assignment('Static property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Undefined_Property_Fetch('Static property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
            }
            return true;
        }
        if (Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
            return false;
        }
        $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;
        if ($codebase->alter_code) {
            $moved_class = $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id);
            if (!$moved_class) {
                foreach ($codebase->property_transforms as $original_pattern => $transformation) {
                    if ($declaring_property_id === $original_pattern) {
                        [$old_declaring_fq_class_name] = explode('::$', $declaring_property_id);
                        [$new_fq_class_name, $new_property_name] = explode('::$', $transformation);
                        $file_manipulations = [];
                        if (strtolower($new_fq_class_name) !== $old_declaring_fq_class_name) {
                            $file_manipulations[] = new File_Manipulation((int) $stmt->class->get_attribute('startFilePos'), (int) $stmt->class->get_attribute('endFilePos') + 1, Type::get_string_from_fqcln($new_fq_class_name, $statements_analyzer->get_namespace(), $statements_analyzer->get_aliased_classes_flipped(), null));
                        }
                        $file_manipulations[] = new File_Manipulation((int) $stmt->name->get_attribute('startFilePos'), (int) $stmt->name->get_attribute('endFilePos') + 1, '$' . $new_property_name);
                        File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                    }
                }
            }
            return true;
        }
        if ($var_id) {
            if ($property->type) {
                $context->vars_in_scope[$var_id] = Type_Expander::expand_union($codebase, $property->type, $class_storage->name, $class_storage->name, $class_storage->parent_class);
            } else {
                $context->vars_in_scope[$var_id] = Type::get_mixed();
            }
            $stmt_type = $context->vars_in_scope[$var_id];
            Atomic_Property_Fetch_Analyzer::process_unspecial_taints($statements_analyzer, $stmt, $stmt_type, $property_id, false, [], []);
            $context->vars_in_scope[$var_id] = $stmt_type;
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id());
            }
        } else {
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        }
        return true;
    }
    private static function analyze_variable_static_property_fetch(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr $stmt_class, Php_Parser\Node\Expr\Static_Property_Fetch $stmt, Context $context): void
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        Expression_Analyzer::analyze($statements_analyzer, $stmt_class, $context);
        $context->inside_general_use = $was_inside_general_use;
        $stmt_class_type = $statements_analyzer->node_data->get_type($stmt_class) ?? Type::get_mixed();
        $old_data_provider = $statements_analyzer->node_data;
        $stmt_type = null;
        $codebase = $statements_analyzer->get_codebase();
        foreach ($stmt_class_type->get_atomic_types() as $class_atomic_type) {
            $statements_analyzer->node_data = clone $statements_analyzer->node_data;
            $string_type = $class_atomic_type instanceof T_Class_String && $class_atomic_type->as_type !== null ? $class_atomic_type->as_type->value : ($class_atomic_type instanceof T_Literal_String ? $class_atomic_type->value : null);
            if ($string_type) {
                $new_stmt_name = new Virtual_Fully_Qualified($string_type, $stmt_class->get_attributes());
                $fake_static_property = new Virtual_Static_Property_Fetch($new_stmt_name, $stmt->name, $stmt->get_attributes());
                self::analyze($statements_analyzer, $fake_static_property, $context);
                $fake_stmt_type = $statements_analyzer->node_data->get_type($fake_static_property) ?? Type::get_mixed();
            } else {
                $fake_var_name = '__fake_var_' . $stmt->get_attribute('startFilePos');
                $fake_var = new Virtual_Variable($fake_var_name, $stmt_class->get_attributes());
                $context->vars_in_scope['$' . $fake_var_name] = new Union([$class_atomic_type]);
                $fake_instance_property = new Virtual_Property_Fetch($fake_var, $stmt->name, $stmt->get_attributes());
                Instance_Property_Fetch_Analyzer::analyze($statements_analyzer, $fake_instance_property, $context, false, true);
                $fake_stmt_type = $statements_analyzer->node_data->get_type($fake_instance_property) ?? Type::get_mixed();
            }
            $stmt_type = $stmt_type ? Type::combine_union_types($stmt_type, $fake_stmt_type, $codebase) : $fake_stmt_type;
            $statements_analyzer->node_data = $old_data_provider;
        }
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
    }
}