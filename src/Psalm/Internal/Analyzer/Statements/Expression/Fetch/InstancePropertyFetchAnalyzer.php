<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Fetch;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Method_Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Issue\Impure_Property_Fetch;
use Psalm\Issue\Invalid_Property_Fetch;
use Psalm\Issue\Mixed_Property_Fetch;
use Psalm\Issue\Null_Property_Fetch;
use Psalm\Issue\Possibly_Invalid_Property_Fetch;
use Psalm\Issue\Possibly_Null_Property_Fetch;
use Psalm\Issue\Uninitialized_Property;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Template_Param;
use function array_merge;
use function array_shift;
use function rtrim;
use function strtolower;
/**
 * @internal
 */
final class Instance_Property_Fetch_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, Context $context, bool $in_assignment = false, bool $is_static_access = false): bool
    {
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
            if (Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context) === false) {
                $context->inside_general_use = $was_inside_general_use;
                return false;
            }
        }
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->var, $context) === false) {
            $context->inside_general_use = $was_inside_general_use;
            return false;
        }
        $context->inside_general_use = $was_inside_general_use;
        if ($stmt->name instanceof Php_Parser\Node\Identifier) {
            $prop_name = $stmt->name->name;
        } elseif (($stmt_name_type = $statements_analyzer->node_data->get_type($stmt->name)) && $stmt_name_type->is_single_string_literal()) {
            $prop_name = $stmt_name_type->get_single_string_literal()->value;
        } else {
            $prop_name = null;
        }
        $codebase = $statements_analyzer->get_codebase();
        $stmt_var_id = Expression_Identifier::get_extended_var_id($stmt->var, $statements_analyzer->get_fqcln(), $statements_analyzer);
        $var_id = Expression_Identifier::get_extended_var_id($stmt, $statements_analyzer->get_fqcln(), $statements_analyzer);
        if ($var_id && $context->has_variable($var_id)) {
            self::handle_scoped_property($context, $var_id, $statements_analyzer, $stmt, $codebase, $stmt_var_id, $in_assignment);
            return true;
        }
        if ($stmt_var_id && $context->has_variable($stmt_var_id)) {
            $stmt_var_type = $context->vars_in_scope[$stmt_var_id];
        } else {
            $stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var);
        }
        if (!$stmt_var_type) {
            return true;
        }
        if ($stmt_var_type->is_null()) {
            return !Issue_Buffer::accepts(new Null_Property_Fetch('Cannot get property on null variable ' . $stmt_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        if ($stmt_var_type->is_never()) {
            return !Issue_Buffer::accepts(new Mixed_Property_Fetch('Cannot fetch property on empty var ' . $stmt_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
        }
        if ($stmt_var_type->has_mixed()) {
            if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
                $codebase->analyzer->increment_mixed_count($statements_analyzer->get_file_path());
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                $codebase->analyzer->add_mixed_member_name('$' . $stmt->name->name, $context->calling_method_id ?: $statements_analyzer->get_file_name());
            }
            Issue_Buffer::maybe_add(new Mixed_Property_Fetch('Cannot fetch property on mixed var ' . $stmt_var_id, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_var_type->get_id());
            }
        }
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_root_file_path());
        }
        if ($stmt_var_type->is_nullable() && !$stmt_var_type->ignore_nullable_issues) {
            // we can only be sure that the variable is possibly null if we know the var_id
            if (!$context->inside_isset && $stmt->name instanceof Php_Parser\Node\Identifier && !Method_Call_Analyzer::has_nullsafe($stmt->var)) {
                Issue_Buffer::maybe_add(new Possibly_Null_Property_Fetch(rtrim('Cannot get property on possibly null variable ' . $stmt_var_id) . ' of type ' . $stmt_var_type, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                $statements_analyzer->node_data->set_type($stmt, Type::get_null());
            }
        }
        if (!$prop_name) {
            if ($stmt_var_type->has_object_type() && !$context->ignore_variable_property) {
                foreach ($stmt_var_type->get_atomic_types() as $type) {
                    if ($type instanceof T_Named_Object) {
                        $codebase->analyzer->add_mixed_member_name(strtolower($type->value) . '::$', $context->calling_method_id ?: $statements_analyzer->get_file_name());
                    }
                }
            }
            $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_var_type->get_id());
            }
            return true;
        }
        $invalid_fetch_types = [];
        $has_valid_fetch_type = false;
        $var_atomic_types = $stmt_var_type->get_atomic_types();
        while ($lhs_type_part = array_shift($var_atomic_types)) {
            if ($lhs_type_part instanceof T_Template_Param) {
                $var_atomic_types = array_merge($var_atomic_types, $lhs_type_part->as->get_atomic_types());
                continue;
            }
            Atomic_Property_Fetch_Analyzer::analyze($statements_analyzer, $stmt, $context, $in_assignment, $var_id, $stmt_var_id, $stmt_var_type, $lhs_type_part, $prop_name, $has_valid_fetch_type, $invalid_fetch_types, $is_static_access);
        }
        $stmt_type = $statements_analyzer->node_data->get_type($stmt);
        if ($stmt_var_type->is_nullable() && !$context->inside_isset && $stmt_type) {
            $stmt_type = $stmt_type->get_builder()->add_type(new T_Null());
            if ($stmt_var_type->ignore_nullable_issues) {
                $stmt_type->ignore_nullable_issues = true;
            }
            $stmt_type = $stmt_type->freeze();
            $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations && $stmt_type = $statements_analyzer->node_data->get_type($stmt)) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id());
        }
        if ($invalid_fetch_types) {
            $lhs_type_part = $invalid_fetch_types[0];
            if ($has_valid_fetch_type) {
                Issue_Buffer::maybe_add(new Possibly_Invalid_Property_Fetch('Cannot fetch property on possible non-object ' . $stmt_var_id . ' of type ' . $lhs_type_part, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            } else {
                Issue_Buffer::maybe_add(new Invalid_Property_Fetch('Cannot fetch property on non-object ' . $stmt_var_id . ' of type ' . $lhs_type_part, new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
        }
        if ($var_id) {
            $context->vars_in_scope[$var_id] = $statements_analyzer->node_data->get_type($stmt) ?? Type::get_mixed();
        }
        return true;
    }
    private static function handle_scoped_property(Context $context, string $var_id, Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Property_Fetch $stmt, Codebase $codebase, ?string $stmt_var_id, bool $in_assignment): void
    {
        $stmt_type = $context->vars_in_scope[$var_id];
        // we don't need to check anything
        $statements_analyzer->node_data->set_type($stmt, $stmt_type);
        if (!$context->collect_initializations && !$context->collect_mutations && $statements_analyzer->get_file_path() === $statements_analyzer->get_root_file_path() && (!($parent_source = $statements_analyzer->get_source()) instanceof Function_Like_Analyzer || !$parent_source->get_source() instanceof Trait_Analyzer)) {
            $codebase->analyzer->increment_non_mixed_count($statements_analyzer->get_file_path());
        }
        if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
            $codebase->analyzer->add_node_type($statements_analyzer->get_file_path(), $stmt->name, $stmt_type->get_id());
        }
        if ($stmt_var_id === '$this' && !$stmt_type->initialized && $context->collect_initializations && ($stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var)) && $stmt_var_type->has_object_type() && $stmt->name instanceof Php_Parser\Node\Identifier) {
            $source = $statements_analyzer->get_source();
            $property_id = null;
            foreach ($stmt_var_type->get_atomic_types() as $lhs_type_part) {
                if ($lhs_type_part instanceof T_Named_Object) {
                    if (!$codebase->class_exists($lhs_type_part->value)) {
                        continue;
                    }
                    $property_id = $lhs_type_part->value . '::$' . $stmt->name->name;
                }
            }
            if ($property_id && $source instanceof Function_Like_Analyzer && $source->get_method_name() === '__construct' && !$context->inside_unset) {
                if ($context->inside_isset || $context->inside_assignment && isset($context->vars_in_scope[$var_id]) && $context->vars_in_scope[$var_id]->is_nullable()) {
                    $stmt_type = $stmt_type->set_properties(['initialized' => true]);
                    $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                    $context->vars_in_scope[$var_id] = $stmt_type;
                } else {
                    Issue_Buffer::maybe_add(new Uninitialized_Property('Cannot use uninitialized property ' . $var_id, new Code_Location($statements_analyzer->get_source(), $stmt), $var_id), $statements_analyzer->get_suppressed_issues());
                    $stmt_type = $stmt_type->get_builder()->add_type(new T_Null())->freeze();
                    $context->vars_in_scope[$var_id] = $stmt_type;
                    $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                }
            }
        }
        if (($stmt_var_type = $statements_analyzer->node_data->get_type($stmt->var)) && $stmt_var_type->has_object_type() && $stmt->name instanceof Php_Parser\Node\Identifier) {
            // log the appearance
            foreach ($stmt_var_type->get_atomic_types() as $lhs_type_part) {
                if ($lhs_type_part instanceof T_Named_Object) {
                    if (!$codebase->class_exists($lhs_type_part->value)) {
                        continue;
                    }
                    $property_id = $lhs_type_part->value . '::$' . $stmt->name->name;
                    $class_storage = $codebase->classlike_storage_provider->get($lhs_type_part->value);
                    Atomic_Property_Fetch_Analyzer::process_taints($statements_analyzer, $stmt, $stmt_type, $property_id, $class_storage, $in_assignment);
                    $context->vars_in_scope[$var_id] = $stmt_type;
                    $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                    $declaring_property_class = $codebase->properties->get_declaring_class_for_property($property_id, true, $statements_analyzer);
                    if ($declaring_property_class) {
                        Atomic_Property_Fetch_Analyzer::check_property_deprecation($stmt->name->name, $declaring_property_class, $stmt, $statements_analyzer);
                    }
                    $codebase->properties->property_exists($property_id, true, $statements_analyzer, $context, $codebase->collect_locations ? new Code_Location($statements_analyzer->get_source(), $stmt) : null);
                    if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                        $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $property_id);
                    }
                    if (!$context->collect_mutations && !$context->collect_initializations && !($class_storage->external_mutation_free && $stmt_type->allow_mutations)) {
                        if ($context->pure) {
                            Issue_Buffer::maybe_add(new Impure_Property_Fetch('Cannot access a property on a mutable object from a pure context', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                        } elseif ($statements_analyzer->get_source() instanceof Function_Like_Analyzer && $statements_analyzer->get_source()->track_mutations) {
                            $statements_analyzer->get_source()->inferred_impure = true;
                        }
                    }
                }
            }
        }
    }
}