<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Assignment;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Expression_Identifier;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Implicit_To_String_Cast;
use Psalm\Issue\Invalid_Property_Assignment_Value;
use Psalm\Issue\Mixed_Property_Type_Coercion;
use Psalm\Issue\Possibly_Invalid_Property_Assignment_Value;
use Psalm\Issue\Property_Type_Coercion;
use Psalm\Issue\Undefined_Property_Assignment;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use function explode;
use function strtolower;
/**
 * @internal
 */
final class Static_Property_Assignment_Analyzer
{
    /**
     * @return  false|null
     */
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Static_Property_Fetch $stmt, ?Php_Parser\Node\Expr $assignment_value, Union $assignment_value_type, Context $context): ?bool
    {
        $var_id = Expression_Identifier::get_extended_var_id($stmt, $context->self, $statements_analyzer);
        $lhs_type = $statements_analyzer->node_data->get_type($stmt->class);
        if (!$lhs_type) {
            return null;
        }
        $codebase = $statements_analyzer->get_codebase();
        $prop_name = $stmt->name;
        foreach ($lhs_type->get_atomic_types() as $lhs_atomic_type) {
            if ($lhs_atomic_type instanceof T_Class_String) {
                if (!$lhs_atomic_type->as_type) {
                    continue;
                }
                $lhs_atomic_type = $lhs_atomic_type->as_type;
            }
            if (!$lhs_atomic_type instanceof T_Named_Object) {
                continue;
            }
            $fq_class_name = $lhs_atomic_type->value;
            if (!$prop_name instanceof Php_Parser\Node\Identifier) {
                $was_inside_general_use = $context->inside_general_use;
                $context->inside_general_use = true;
                if (Expression_Analyzer::analyze($statements_analyzer, $prop_name, $context) === false) {
                    $context->inside_general_use = $was_inside_general_use;
                    return false;
                }
                $context->inside_general_use = $was_inside_general_use;
                if (!$context->ignore_variable_property) {
                    $codebase->analyzer->add_mixed_member_name(strtolower($fq_class_name) . '::$', $context->calling_method_id ?: $statements_analyzer->get_file_name());
                }
                return null;
            }
            $property_id = $fq_class_name . '::$' . $prop_name;
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $fq_class_name);
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $property_id);
            }
            if (!$codebase->properties->property_exists($property_id, false, $statements_analyzer, $context)) {
                Issue_Buffer::maybe_add(new Undefined_Property_Assignment('Static property ' . $property_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt), $property_id), $statements_analyzer->get_suppressed_issues());
                return null;
            }
            if (Class_Like_Analyzer::check_property_visibility($property_id, $context, $statements_analyzer, new Code_Location($statements_analyzer->get_source(), $stmt), $statements_analyzer->get_suppressed_issues()) === false) {
                return false;
            }
            $declaring_property_class = (string) $codebase->properties->get_declaring_class_for_property($fq_class_name . '::$' . $prop_name->name, false);
            $declaring_property_id = strtolower($declaring_property_class) . '::$' . $prop_name;
            if ($codebase->alter_code && $stmt->class instanceof Php_Parser\Node\Name) {
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
            }
            $class_storage = $codebase->classlike_storage_provider->get($declaring_property_class);
            if ($var_id) {
                $context->vars_in_scope[$var_id] = $assignment_value_type;
            }
            Instance_Property_Assignment_Analyzer::taint_unspecialized_property($statements_analyzer, $stmt, $property_id, $class_storage, $assignment_value_type, $context, null);
            $class_property_type = $codebase->properties->get_property_type($property_id, true, $statements_analyzer, $context);
            if (!$class_property_type) {
                $class_property_type = Type::get_mixed();
                $source_analyzer = $statements_analyzer->get_source()->get_source();
                $prop_name_name = $prop_name->name;
                if ($source_analyzer instanceof Class_Analyzer && $fq_class_name === $source_analyzer->get_fqcln()) {
                    $source_analyzer->inferred_property_types[$prop_name_name] = Type::combine_union_types($assignment_value_type, $source_analyzer->inferred_property_types[$prop_name_name] ?? null);
                }
            }
            if ($assignment_value_type->has_mixed()) {
                return null;
            }
            if ($class_property_type->has_mixed()) {
                return null;
            }
            $class_property_type = Type_Expander::expand_union($codebase, $class_property_type, $fq_class_name, $fq_class_name, $class_storage->parent_class);
            $union_comparison_results = new Type_Comparison_Result();
            $type_match_found = Union_Type_Comparator::is_contained_by($codebase, $assignment_value_type, $class_property_type, true, true, $union_comparison_results);
            if ($union_comparison_results->type_coerced) {
                if ($union_comparison_results->type_coerced_from_mixed) {
                    Issue_Buffer::maybe_add(new Mixed_Property_Type_Coercion($var_id . ' expects \'' . $class_property_type->get_id() . '\', ' . ' parent type `' . $assignment_value_type->get_id() . '` provided', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $property_id), $statements_analyzer->get_suppressed_issues());
                } else {
                    Issue_Buffer::maybe_add(new Property_Type_Coercion($var_id . ' expects \'' . $class_property_type->get_id() . '\', ' . ' parent type \'' . $assignment_value_type->get_id() . '\' provided', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location), $property_id), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($union_comparison_results->to_string_cast) {
                Issue_Buffer::maybe_add(new Implicit_To_String_Cast($var_id . ' expects \'' . $class_property_type . '\', ' . '\'' . $assignment_value_type . '\' provided with a __toString method', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt, $context->include_location)), $statements_analyzer->get_suppressed_issues());
            }
            if (!$type_match_found && !$union_comparison_results->type_coerced) {
                if (Union_Type_Comparator::can_be_contained_by($codebase, $assignment_value_type, $class_property_type)) {
                    if (Issue_Buffer::accepts(new Possibly_Invalid_Property_Assignment_Value($var_id . ' with declared type \'' . $class_property_type->get_id() . '\' cannot be assigned type \'' . $assignment_value_type->get_id() . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt), $property_id), $statements_analyzer->get_suppressed_issues())) {
                        return false;
                    }
                } else if (Issue_Buffer::accepts(new Invalid_Property_Assignment_Value($var_id . ' with declared type \'' . $class_property_type->get_id() . '\' cannot be assigned type \'' . $assignment_value_type->get_id() . '\'', new Code_Location($statements_analyzer->get_source(), $assignment_value ?? $stmt), $property_id), $statements_analyzer->get_suppressed_issues())) {
                    return false;
                }
            }
            if ($var_id) {
                $context->vars_in_scope[$var_id] = $assignment_value_type;
            }
        }
        return null;
    }
}