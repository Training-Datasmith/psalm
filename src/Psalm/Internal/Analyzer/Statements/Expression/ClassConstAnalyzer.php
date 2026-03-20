<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use InvalidArgumentException;
use Php_Parser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\Circular_Reference_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Class_Like_Name_Options;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Issue\Ambiguous_Constant_Inheritance;
use Psalm\Issue\Circular_Reference;
use Psalm\Issue\Deprecated_Class;
use Psalm\Issue\Deprecated_Constant;
use Psalm\Issue\Inaccessible_Class_Constant;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Invalid_Class_Constant_Type;
use Psalm\Issue\Invalid_Constant_Assignment_Value;
use Psalm\Issue\Invalid_String_Class;
use Psalm\Issue\Less_Specific_Class_Constant_Type;
use Psalm\Issue\Non_Static_Self_Call;
use Psalm\Issue\Overridden_Final_Constant;
use Psalm\Issue\Overridden_Interface_Constant;
use Psalm\Issue\Parent_Not_Found;
use Psalm\Issue\ParseError;
use Psalm\Issue\Undefined_Constant;
use Psalm\Issue_Buffer;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Union;
use ReflectionProperty;
use function assert;
use function explode;
use function in_array;
use function strtolower;
/**
 * @internal
 */
final class Class_Const_Analyzer
{
    /**
     * @psalm-suppress ComplexMethod to be refactored. We should probably regroup the two big if about $stmt->class and
     * analyse the ::class int $stmt->name separately
     */
    public static function analyze_fetch(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Expr\Class_Const_Fetch $stmt, Context $context): bool
    {
        $codebase = $statements_analyzer->get_codebase();
        $statements_analyzer->node_data->set_type($stmt, Type::get_mixed());
        if ($stmt->class instanceof Php_Parser\Node\Name) {
            $first_part_lc = strtolower($stmt->class->get_first());
            if ($first_part_lc === 'self' || $first_part_lc === 'static') {
                if (!$context->self) {
                    return !Issue_Buffer::accepts(new Non_Static_Self_Call('Cannot use ' . $first_part_lc . ' outside class context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                $fq_class_name = $context->self;
            } elseif ($first_part_lc === 'parent') {
                $fq_class_name = $statements_analyzer->get_parent_fqcln();
                if ($fq_class_name === null) {
                    return !Issue_Buffer::accepts(new Parent_Not_Found('Cannot check property fetch on parent as this class does not extend another', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            } else {
                $fq_class_name = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->class, $statements_analyzer->get_aliases());
                if ($stmt->name instanceof Php_Parser\Node\Identifier) {
                    if ((!$context->inside_class_exists || $stmt->name->name !== 'class') && !isset($context->phantom_classes[strtolower($fq_class_name)])) {
                        if (Class_Like_Analyzer::check_fully_qualified_class_like_name($statements_analyzer, $fq_class_name, new Code_Location($statements_analyzer->get_source(), $stmt->class), $context->self, $context->calling_method_id, $statements_analyzer->get_suppressed_issues(), new Class_Like_Name_Options(false, true)) === false) {
                            return true;
                        }
                    }
                }
            }
            $fq_class_name_lc = strtolower($fq_class_name);
            $moved_class = false;
            if ($codebase->alter_code && !in_array($stmt->class->get_first(), ['parent', 'static'])) {
                $moved_class = $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id, false, $stmt->class->get_first() === 'self');
            }
            if ($codebase->classlikes->class_exists($fq_class_name)) {
                $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
            }
            if ($stmt->name instanceof Php_Parser\Node\Identifier && $stmt->name->name === 'class') {
                if ($codebase->classlikes->class_exists($fq_class_name)) {
                    $const_class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
                    $fq_class_name = $const_class_storage->name;
                    if ($const_class_storage->deprecated && $fq_class_name !== $context->self) {
                        Issue_Buffer::maybe_add(new Deprecated_Class('Class ' . $fq_class_name . ' is deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
                    }
                }
                if ($first_part_lc === 'static') {
                    $static_named_object = new T_Named_Object($fq_class_name, true);
                    $statements_analyzer->node_data->set_type($stmt, new Union([new T_Class_String($fq_class_name, $static_named_object)]));
                } else {
                    $statements_analyzer->node_data->set_type($stmt, Type::get_literal_class_string($fq_class_name, true));
                }
                if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                    $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $fq_class_name);
                }
                return true;
            }
            // if we're ignoring that the class doesn't exist, exit anyway
            if (!$codebase->classlikes->class_or_interface_or_enum_exists($fq_class_name)) {
                return true;
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $fq_class_name);
            }
            if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
                if ($codebase->analysis_php_version_id < 80300) {
                    Issue_Buffer::maybe_add(new ParseError('Dynamically fetching class constants and enums requires PHP 8.3', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                $was_inside_general_use = $context->inside_general_use;
                $context->inside_general_use = true;
                $ret = Expression_Analyzer::analyze($statements_analyzer, $stmt->name, $context);
                $context->inside_general_use = $was_inside_general_use;
                return $ret;
            }
            $const_id = $fq_class_name . '::' . $stmt->name;
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $const_id);
            }
            $const_class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            if ($const_class_storage->is_enum) {
                $case = $const_class_storage->enum_cases[(string) $stmt->name] ?? null;
                if ($case && $case->deprecated) {
                    Issue_Buffer::maybe_add(new Deprecated_Constant("Enum Case {$const_id} is marked as deprecated", new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($fq_class_name === $context->self || $statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer && $fq_class_name === $statements_analyzer->get_source()->get_fqcln()) {
                $class_visibility = ReflectionProperty::IS_PRIVATE;
            } elseif ($context->self && ($codebase->classlikes->class_extends($context->self, $fq_class_name) || $codebase->classlikes->class_extends($fq_class_name, $context->self))) {
                $class_visibility = ReflectionProperty::IS_PROTECTED;
            } else {
                $class_visibility = ReflectionProperty::IS_PUBLIC;
            }
            try {
                $class_constant_type = $codebase->classlikes->get_class_constant_type($fq_class_name, $stmt->name->name, $class_visibility, $statements_analyzer, [], $stmt->class->get_first() === "static");
            } catch (InvalidArgumentException) {
                return true;
            } catch (Circular_Reference_Exception) {
                Issue_Buffer::maybe_add(new Circular_Reference('Constant ' . $const_id . ' contains a circular reference', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                return true;
            }
            if (!$class_constant_type) {
                if ($fq_class_name !== $context->self) {
                    $class_constant_type = $codebase->classlikes->get_class_constant_type($fq_class_name, $stmt->name->name, ReflectionProperty::IS_PRIVATE, $statements_analyzer);
                }
                if ($class_constant_type) {
                    Issue_Buffer::maybe_add(new Inaccessible_Class_Constant('Constant ' . $const_id . ' is not visible in this context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif ($context->check_consts) {
                    Issue_Buffer::maybe_add(new Undefined_Constant('Constant ' . $const_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                return true;
            }
            if ($context->calling_method_id) {
                $codebase->file_reference_provider->add_method_reference_to_class_member($context->calling_method_id, $fq_class_name_lc . '::' . $stmt->name->name, false);
            }
            $declaring_const_id = $fq_class_name_lc . '::' . $stmt->name->name;
            if ($codebase->alter_code && !$moved_class) {
                foreach ($codebase->class_constant_transforms as $original_pattern => $transformation) {
                    if ($declaring_const_id === $original_pattern) {
                        [$new_fq_class_name, $new_const_name] = explode('::', $transformation);
                        $file_manipulations = [];
                        if (strtolower($new_fq_class_name) !== $fq_class_name_lc) {
                            $file_manipulations[] = new File_Manipulation((int) $stmt->class->get_attribute('startFilePos'), (int) $stmt->class->get_attribute('endFilePos') + 1, Type::get_string_from_fqcln($new_fq_class_name, $statements_analyzer->get_namespace(), $statements_analyzer->get_aliased_classes_flipped(), null));
                        }
                        $file_manipulations[] = new File_Manipulation((int) $stmt->name->get_attribute('startFilePos'), (int) $stmt->name->get_attribute('endFilePos') + 1, $new_const_name);
                        File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                    }
                }
            }
            if ($context->self && !$context->collect_initializations && !$context->collect_mutations && !Namespace_Analyzer::is_within_any($context->self, $const_class_storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Class($fq_class_name . ' is internal to ' . Internal_Class::list_to_phrase($const_class_storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
            }
            if ($const_class_storage->deprecated && $fq_class_name !== $context->self) {
                Issue_Buffer::maybe_add(new Deprecated_Class('Class ' . $fq_class_name . ' is deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
            } elseif (isset($const_class_storage->constants[$stmt->name->name]) && $const_class_storage->constants[$stmt->name->name]->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Constant('Constant ' . $const_id . ' is deprecated', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            if ($first_part_lc !== 'static' || $const_class_storage->final || $class_constant_type->from_docblock || isset($const_class_storage->constants[$stmt->name->name]) && $const_class_storage->constants[$stmt->name->name]->final) {
                $stmt_type = $class_constant_type;
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                $context->vars_in_scope[$const_id] = $stmt_type;
            }
            return true;
        }
        $was_inside_general_use = $context->inside_general_use;
        $context->inside_general_use = true;
        if (Expression_Analyzer::analyze($statements_analyzer, $stmt->class, $context) === false) {
            $context->inside_general_use = $was_inside_general_use;
            return false;
        }
        $context->inside_general_use = $was_inside_general_use;
        $lhs_type = $statements_analyzer->node_data->get_type($stmt->class);
        if ($lhs_type === null) {
            return true;
        }
        if ($stmt->name instanceof Php_Parser\Node\Identifier && $stmt->name->name === 'class') {
            $class_string_types = [];
            $has_mixed_or_object = false;
            foreach ($lhs_type->get_atomic_types() as $lhs_atomic_type) {
                if ($lhs_atomic_type instanceof T_Named_Object) {
                    $class_string_types[] = new T_Class_String($lhs_atomic_type->value, $lhs_atomic_type);
                } elseif ($lhs_atomic_type instanceof T_Template_Param && $lhs_atomic_type->as->is_single()) {
                    $as_atomic_type = $lhs_atomic_type->as->get_single_atomic();
                    if ($as_atomic_type instanceof T_Object) {
                        $class_string_types[] = new T_Template_Param_Class($lhs_atomic_type->param_name, 'object', null, $lhs_atomic_type->defining_class);
                    } elseif ($as_atomic_type instanceof T_Named_Object) {
                        $class_string_types[] = new T_Template_Param_Class($lhs_atomic_type->param_name, $as_atomic_type->value, $as_atomic_type, $lhs_atomic_type->defining_class);
                    }
                } elseif ($lhs_atomic_type instanceof T_Object || $lhs_atomic_type instanceof T_Mixed) {
                    $has_mixed_or_object = true;
                }
            }
            if ($has_mixed_or_object) {
                $statements_analyzer->node_data->set_type($stmt, new Union([new T_Class_String()]));
            } elseif ($class_string_types) {
                $statements_analyzer->node_data->set_type($stmt, new Union($class_string_types));
            }
            return true;
        }
        if ($stmt->class instanceof Php_Parser\Node\Expr\Variable) {
            $fq_class_name = null;
            $lhs_type_definite_class = null;
            if ($lhs_type->is_single()) {
                $atomic_type = $lhs_type->get_single_atomic();
                if ($atomic_type instanceof T_Named_Object) {
                    $fq_class_name = $atomic_type->value;
                    $lhs_type_definite_class = $atomic_type->definite_class;
                } elseif ($atomic_type instanceof T_Literal_Class_String) {
                    $fq_class_name = $atomic_type->value;
                    $lhs_type_definite_class = $atomic_type->definite_class;
                } elseif ($atomic_type instanceof T_String && !$atomic_type instanceof T_Class_String && !$codebase->config->allow_string_standin_for_class) {
                    Issue_Buffer::maybe_add(new Invalid_String_Class('String cannot be used as a class', new Code_Location($statements_analyzer->get_source(), $stmt->class)), $statements_analyzer->get_suppressed_issues());
                }
            }
            if ($fq_class_name === null || $lhs_type_definite_class === null) {
                return true;
            }
            if ($codebase->classlikes->class_exists($fq_class_name)) {
                $fq_class_name = $codebase->classlikes->get_un_aliased_name($fq_class_name);
            }
            $moved_class = false;
            if ($codebase->alter_code) {
                $moved_class = $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt->class, $fq_class_name, $context->calling_method_id);
            }
            // if we're ignoring that the class doesn't exist, exit anyway
            if (!$codebase->classlikes->class_or_interface_or_enum_exists($fq_class_name)) {
                return true;
            }
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->class, $fq_class_name);
            }
            if (!$stmt->name instanceof Php_Parser\Node\Identifier) {
                return true;
            }
            $const_id = $fq_class_name . '::' . $stmt->name;
            if ($codebase->store_node_types && !$context->collect_initializations && !$context->collect_mutations) {
                $codebase->analyzer->add_node_reference($statements_analyzer->get_file_path(), $stmt->name, $const_id);
            }
            $const_class_storage = $codebase->classlike_storage_provider->get($fq_class_name);
            if ($fq_class_name === $context->self || $statements_analyzer->get_source()->get_source() instanceof Trait_Analyzer && $fq_class_name === $statements_analyzer->get_source()->get_fqcln()) {
                $class_visibility = ReflectionProperty::IS_PRIVATE;
            } elseif ($context->self && ($codebase->classlikes->class_extends($context->self, $fq_class_name) || $codebase->classlikes->class_extends($fq_class_name, $context->self))) {
                $class_visibility = ReflectionProperty::IS_PROTECTED;
            } else {
                $class_visibility = ReflectionProperty::IS_PUBLIC;
            }
            try {
                $class_constant_type = $codebase->classlikes->get_class_constant_type($fq_class_name, $stmt->name->name, $class_visibility, $statements_analyzer);
            } catch (InvalidArgumentException) {
                return true;
            } catch (Circular_Reference_Exception) {
                Issue_Buffer::maybe_add(new Circular_Reference('Constant ' . $const_id . ' contains a circular reference', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                return true;
            }
            if (!$class_constant_type) {
                if ($fq_class_name !== $context->self) {
                    $class_constant_type = $codebase->classlikes->get_class_constant_type($fq_class_name, $stmt->name->name, ReflectionProperty::IS_PRIVATE, $statements_analyzer);
                }
                if ($class_constant_type) {
                    Issue_Buffer::maybe_add(new Inaccessible_Class_Constant('Constant ' . $const_id . ' is not visible in this context', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                } elseif ($context->check_consts) {
                    Issue_Buffer::maybe_add(new Undefined_Constant('Constant ' . $const_id . ' is not defined', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                return true;
            }
            if ($context->calling_method_id) {
                $codebase->file_reference_provider->add_method_reference_to_class_member($context->calling_method_id, strtolower($fq_class_name) . '::' . $stmt->name->name, false);
            }
            $declaring_const_id = strtolower($fq_class_name) . '::' . $stmt->name->name;
            if ($codebase->alter_code && !$moved_class) {
                foreach ($codebase->class_constant_transforms as $original_pattern => $transformation) {
                    if ($declaring_const_id === $original_pattern) {
                        [, $new_const_name] = explode('::', $transformation);
                        $file_manipulations = [];
                        $file_manipulations[] = new File_Manipulation((int) $stmt->name->get_attribute('startFilePos'), (int) $stmt->name->get_attribute('endFilePos') + 1, $new_const_name);
                        File_Manipulation_Buffer::add($statements_analyzer->get_file_path(), $file_manipulations);
                    }
                }
            }
            if ($context->self && !$context->collect_initializations && !$context->collect_mutations && !Namespace_Analyzer::is_within_any($context->self, $const_class_storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Class($fq_class_name . ' is internal to ' . Internal_Class::list_to_phrase($const_class_storage->internal) . ' but called from ' . $context->self, new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
            }
            if ($const_class_storage->deprecated && $fq_class_name !== $context->self) {
                Issue_Buffer::maybe_add(new Deprecated_Class('Class ' . $fq_class_name . ' is deprecated', new Code_Location($statements_analyzer->get_source(), $stmt), $fq_class_name), $statements_analyzer->get_suppressed_issues());
            } elseif (isset($const_class_storage->constants[$stmt->name->name]) && $const_class_storage->constants[$stmt->name->name]->deprecated) {
                Issue_Buffer::maybe_add(new Deprecated_Constant('Constant ' . $const_id . ' is deprecated', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            if ($const_class_storage->final || $lhs_type_definite_class === true) {
                $stmt_type = $class_constant_type;
                $statements_analyzer->node_data->set_type($stmt, $stmt_type);
                $context->vars_in_scope[$const_id] = $stmt_type;
            }
            return true;
        }
        return true;
    }
    public static function analyze_assignment(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Class_Const $stmt, Context $context): void
    {
        assert($context->self !== null);
        $class_storage = $statements_analyzer->get_codebase()->classlike_storage_provider->get($context->self);
        if ($class_storage->has_visitor_issues) {
            return;
        }
        foreach ($stmt->consts as $const) {
            Expression_Analyzer::analyze($statements_analyzer, $const->value, $context);
            $const_storage = $class_storage->constants[$const->name->name];
            // Check assigned type matches docblock type
            if ($assigned_type = $statements_analyzer->node_data->get_type($const->value)) {
                $const_storage_type = $const_storage->type;
                if ($const_storage_type !== null && $const_storage->stmt_location !== null && $assigned_type !== $const_storage_type && ($const_storage_type->from_docblock || $const_storage_type->from_property) && !Union_Type_Comparator::is_contained_by($statements_analyzer->get_codebase(), $assigned_type, $const_storage_type)) {
                    Issue_Buffer::maybe_add(new Invalid_Constant_Assignment_Value("{$class_storage->name}::{$const->name->name} with declared type " . "{$const_storage_type->get_id()} cannot be assigned type {$assigned_type->get_id()}", $const_storage->stmt_location, "{$class_storage->name}::{$const->name->name}"), $const_storage->suppressed_issues);
                }
            }
        }
    }
    public static function analyze(Class_Like_Storage $class_storage, Codebase $codebase): void
    {
        foreach ($class_storage->constants as $const_name => $const_storage) {
            [$parent_classlike_storage, $parent_const_storage] = self::get_overridden_constant($class_storage, $const_storage, $const_name, $codebase);
            $type_location = $const_storage->location ?? $const_storage->stmt_location;
            if ($type_location === null) {
                continue;
            }
            if ($parent_const_storage !== null) {
                assert($parent_classlike_storage !== null);
                // Check covariance
                if ($const_storage->type !== null && $parent_const_storage->type !== null && !Union_Type_Comparator::is_contained_by($codebase, $const_storage->type, $parent_const_storage->type)) {
                    if (Union_Type_Comparator::is_contained_by($codebase, $parent_const_storage->type, $const_storage->type)) {
                        // Contravariant
                        Issue_Buffer::maybe_add(new Less_Specific_Class_Constant_Type("The type \"{$const_storage->type->get_id()}\" for {$class_storage->name}::" . "{$const_name} is more general than the type " . "\"{$parent_const_storage->type->get_id()}\" inherited from " . "{$parent_classlike_storage->name}::{$const_name}", $type_location, "{$class_storage->name}::{$const_name}"), $const_storage->suppressed_issues);
                    } else {
                        // Completely different
                        Issue_Buffer::maybe_add(new Invalid_Class_Constant_Type("The type \"{$const_storage->type->get_id()}\" for {$class_storage->name}::" . "{$const_name} does not satisfy the type " . "\"{$parent_const_storage->type->get_id()}\" inherited from " . "{$parent_classlike_storage->name}::{$const_name}", $type_location, "{$class_storage->name}::{$const_name}"), $const_storage->suppressed_issues);
                    }
                }
                // Check overridden final
                if ($parent_const_storage->final && $parent_const_storage !== $const_storage) {
                    Issue_Buffer::maybe_add(new Overridden_Final_Constant("{$const_name} cannot be overridden because it is marked as final in " . $parent_classlike_storage->name, $type_location, "{$class_storage->name}::{$const_name}"), $const_storage->suppressed_issues);
                }
            }
            if ($const_storage->stmt_location !== null) {
                // Check final in PHP < 8.1
                if ($codebase->analysis_php_version_id < 80100 && $const_storage->final) {
                    Issue_Buffer::maybe_add(new ParseError("Class constants cannot be marked final before PHP 8.1", $const_storage->stmt_location), $const_storage->suppressed_issues);
                }
            }
        }
    }
    /**
     * Get the const storage from the parent or interface that this class is overriding.
     *
     * @return array{ClassLikeStorage, ClassConstantStorage}|null
     */
    private static function get_overridden_constant(Class_Like_Storage $class_storage, Class_Constant_Storage $const_storage, string $const_name, Codebase $codebase): ?array
    {
        $parent_classlike_storage = $interface_const_storage = $parent_const_storage = null;
        $interface_overrides = [];
        foreach ($class_storage->class_implements ?: $class_storage->direct_interface_parents as $interface) {
            $interface_storage = $codebase->classlike_storage_provider->get($interface);
            $parent_const_storage = $interface_storage->constants[$const_name] ?? null;
            if ($parent_const_storage !== null) {
                if ($const_storage->location && $const_storage !== $parent_const_storage && $codebase->analysis_php_version_id < 80100) {
                    $interface_overrides[strtolower($interface)] = new Overridden_Interface_Constant("{$class_storage->name}::{$const_name} cannot override constant from {$interface}", $const_storage->location, "{$class_storage->name}::{$const_name}");
                }
                if ($interface_const_storage !== null && $const_storage->location !== null) {
                    assert($parent_classlike_storage !== null);
                    if (!isset($parent_classlike_storage->parent_interfaces[strtolower($interface)]) && !isset($interface_storage->parent_interfaces[strtolower($parent_classlike_storage->name)]) && $interface_const_storage !== $parent_const_storage) {
                        Issue_Buffer::maybe_add(new Ambiguous_Constant_Inheritance("Ambiguous inheritance of {$class_storage->name}::{$const_name} from {$interface} and " . $parent_classlike_storage->name, $const_storage->location, "{$class_storage->name}::{$const_name}"), $const_storage->suppressed_issues);
                    }
                }
                $interface_const_storage = $parent_const_storage;
                $parent_classlike_storage = $interface_storage;
            }
        }
        foreach ($class_storage->parent_classes as $parent_class) {
            $parent_class_storage = $codebase->classlike_storage_provider->get($parent_class);
            $parent_const_storage = $parent_class_storage->constants[$const_name] ?? null;
            if ($parent_const_storage !== null) {
                if ($const_storage->location !== null && $interface_const_storage !== null) {
                    assert($parent_classlike_storage !== null);
                    if (!isset($parent_class_storage->class_implements[strtolower($parent_classlike_storage->name)])) {
                        Issue_Buffer::maybe_add(new Ambiguous_Constant_Inheritance("Ambiguous inheritance of {$class_storage->name}::{$const_name} from " . "{$parent_classlike_storage->name} and {$parent_class}", $const_storage->location, "{$class_storage->name}::{$const_name}"), $const_storage->suppressed_issues);
                    }
                }
                foreach ($interface_overrides as $interface_lc => $_) {
                    // If the parent is the one with the const that's overriding the interface const, and the parent
                    // doesn't implement the interface, it's just an AmbiguousConstantInheritance, not an
                    // OverriddenInterfaceConstant
                    if (!isset($parent_class_storage->class_implements[$interface_lc]) && $parent_const_storage === $const_storage) {
                        unset($interface_overrides[$interface_lc]);
                    }
                }
                $parent_classlike_storage = $parent_class_storage;
                break;
            }
        }
        if ($parent_const_storage === null) {
            $parent_const_storage = $interface_const_storage;
        }
        foreach ($interface_overrides as $issue) {
            Issue_Buffer::maybe_add($issue, $const_storage->suppressed_issues);
        }
        if ($parent_classlike_storage !== null) {
            assert($parent_const_storage !== null);
            return [$parent_classlike_storage, $parent_const_storage];
        }
        return null;
    }
}