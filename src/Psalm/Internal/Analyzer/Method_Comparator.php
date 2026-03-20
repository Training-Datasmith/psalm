<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use Php_Parser\Node\Stmt\Class_Method;
use Php_Parser\Node_Traverser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Php_Visitor\Param_Replacement_Visitor;
use Psalm\Internal\Type\Comparator\Type_Comparison_Result;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Template_Inferred_Type_Replacer;
use Psalm\Internal\Type\Template_Result;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Constructor_Signature_Mismatch;
use Psalm\Issue\Implemented_Param_Type_Mismatch;
use Psalm\Issue\Implemented_Return_Type_Mismatch;
use Psalm\Issue\Less_Specific_Implemented_Return_Type;
use Psalm\Issue\Method_Signature_Mismatch;
use Psalm\Issue\Method_Signature_Must_Provide_Return_Type;
use Psalm\Issue\Mismatching_Docblock_Param_Type;
use Psalm\Issue\Mismatching_Docblock_Return_Type;
use Psalm\Issue\Missing_Immutable_Annotation;
use Psalm\Issue\More_Specific_Implemented_Param_Type;
use Psalm\Issue\Overridden_Method_Access;
use Psalm\Issue\Param_Name_Mismatch;
use Psalm\Issue\Trait_Method_Signature_Mismatch;
use Psalm\Issue_Buffer;
use Psalm\Storage\Attribute_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Method_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use function array_any;
use function in_array;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
final class Method_Comparator
{
    /**
     * @param  string[]         $suppressed_issues
     * @return false|null
     * @psalm-suppress PossiblyUnusedReturnValue unused but seems important
     */
    public static function compare(Codebase $codebase, ?Class_Method $stmt, Class_Like_Storage $implementer_classlike_storage, Class_Like_Storage $guide_classlike_storage, Method_Storage $implementer_method_storage, Method_Storage $guide_method_storage, string $implementer_called_class_name, int $implementer_visibility, Code_Location $code_location, array $suppressed_issues, bool $prevent_abstract_override = true, bool $prevent_method_signature_mismatch = true): ?bool
    {
        $implementer_method_id = new Method_Identifier($implementer_classlike_storage->name, strtolower($guide_method_storage->cased_name ?: ''));
        $implementer_declaring_method_id = $codebase->methods->get_declaring_method_id($implementer_method_id);
        $cased_implementer_method_id = $implementer_classlike_storage->name . '::' . $implementer_method_storage->cased_name;
        $cased_guide_method_id = $guide_classlike_storage->name . '::' . $guide_method_storage->cased_name;
        $codebase->methods->file_reference_provider->add_method_dependency_to_class_member(strtolower((string) ($implementer_declaring_method_id ?? $implementer_method_id)), strtolower($guide_classlike_storage->name . '::' . $guide_method_storage->cased_name));
        self::check_for_obvious_method_mismatches($guide_classlike_storage, $implementer_classlike_storage, $guide_method_storage, $implementer_method_storage, $guide_method_storage->visibility, $implementer_visibility, $cased_guide_method_id, $cased_implementer_method_id, $prevent_method_signature_mismatch, $prevent_abstract_override, $codebase->analysis_php_version_id >= 80000, $code_location, $suppressed_issues);
        if ($guide_method_storage->signature_return_type && $prevent_method_signature_mismatch) {
            self::compare_method_signature_return_types($codebase, $guide_classlike_storage, $implementer_classlike_storage, $guide_method_storage, $implementer_method_storage, $guide_method_storage->signature_return_type, $cased_guide_method_id, $implementer_called_class_name, $cased_implementer_method_id, $code_location, $suppressed_issues);
        }
        // CallMapHandler needed due to https://github.com/vimeo/psalm/issues/10378
        if (!$guide_classlike_storage->user_defined && $implementer_classlike_storage->user_defined && $codebase->analysis_php_version_id >= 80100 && ($guide_method_storage->return_type && Internal_Call_Map_Handler::in_call_map($cased_guide_method_id) || $guide_method_storage->signature_return_type) && !$implementer_method_storage->signature_return_type && !array_any($implementer_method_storage->attributes, static fn(Attribute_Storage $s): bool => $s->fq_class_name === 'ReturnTypeWillChange')) {
            Issue_Buffer::maybe_add(new Method_Signature_Must_Provide_Return_Type('Method ' . $cased_implementer_method_id . ' must have a return type signature', $implementer_method_storage->location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
        }
        if ($guide_method_storage->return_type && $implementer_method_storage->return_type && !$implementer_method_storage->inherited_return_type && ($guide_method_storage->signature_return_type !== $guide_method_storage->return_type || $implementer_method_storage->signature_return_type !== $implementer_method_storage->return_type) && $implementer_classlike_storage->user_defined && (!$guide_classlike_storage->stubbed || $guide_classlike_storage->template_types)) {
            self::compare_method_docblock_return_types($codebase, $guide_classlike_storage, $implementer_classlike_storage, $implementer_method_storage, $guide_method_storage->return_type, $implementer_method_storage->return_type, $cased_guide_method_id, $implementer_called_class_name, $implementer_declaring_method_id, $code_location, $suppressed_issues);
        }
        foreach ($guide_method_storage->params as $i => $guide_param) {
            if (!isset($implementer_method_storage->params[$i])) {
                if (!$prevent_abstract_override && $i >= $guide_method_storage->required_param_count) {
                    continue;
                }
                if (Issue_Buffer::accepts(new Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' has fewer parameters than parent method ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues)) {
                    return false;
                }
                return null;
            }
            self::compare_method_params($codebase, $stmt, $implementer_classlike_storage, $guide_classlike_storage, $implementer_called_class_name, $guide_method_storage, $implementer_method_storage, $guide_param, $implementer_method_storage->params[$i], $i, $cased_guide_method_id, $cased_implementer_method_id, $prevent_method_signature_mismatch, $code_location, $suppressed_issues);
        }
        if (($guide_classlike_storage->is_interface || $guide_classlike_storage->preserve_constructor_signature || $implementer_method_storage->cased_name !== '__construct') && $implementer_method_storage->required_param_count > $guide_method_storage->required_param_count) {
            if ($implementer_method_storage->cased_name !== '__construct') {
                if (Issue_Buffer::accepts(new Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' has more required parameters than parent method ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues)) {
                    return false;
                }
            } else if (Issue_Buffer::accepts(new Constructor_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' has more required parameters than parent method ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues)) {
                return false;
            }
            return null;
        }
        return null;
    }
    /**
     * @param array<lowercase-string, MethodStorage> $pseudo_methods
     */
    public static function compare_pseudo_methods(array $pseudo_methods, string $fq_class_name, Codebase $codebase, Class_Like_Storage $class_storage): void
    {
        foreach ($pseudo_methods as $pseudo_method_name => $pseudo_method_storage) {
            $pseudo_method_id = new Method_Identifier($fq_class_name, $pseudo_method_name);
            $overridden_method_ids = $codebase->methods->get_overridden_method_ids($pseudo_method_id);
            if (isset($class_storage->methods[$pseudo_method_id->method_name])) {
                $overridden_method_ids[$class_storage->name] = $pseudo_method_id;
            }
            if ($overridden_method_ids && $pseudo_method_name !== '__construct' && $pseudo_method_storage->location) {
                foreach ($overridden_method_ids as $overridden_method_id) {
                    $parent_method_storage = $codebase->methods->get_storage($overridden_method_id);
                    $overridden_fq_class_name = $overridden_method_id->fq_class_name;
                    $parent_storage = $codebase->classlike_storage_provider->get($overridden_fq_class_name);
                    self::compare($codebase, null, $class_storage, $parent_storage, $pseudo_method_storage, $parent_method_storage, $fq_class_name, $pseudo_method_storage->visibility ?: 0, $class_storage->location ?: $pseudo_method_storage->location, $class_storage->suppressed_issues, true, false);
                }
            }
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function check_for_obvious_method_mismatches(Class_Like_Storage $guide_classlike_storage, Class_Like_Storage $implementer_classlike_storage, Method_Storage $guide_method_storage, Method_Storage $implementer_method_storage, int $guide_visibility, int $implementer_visibility, string $cased_guide_method_id, string $cased_implementer_method_id, bool $prevent_method_signature_mismatch, bool $prevent_abstract_override, bool $trait_mismatches_are_fatal, Code_Location $code_location, array $suppressed_issues): void
    {
        if ($implementer_visibility > $guide_visibility) {
            if ($trait_mismatches_are_fatal || $guide_classlike_storage->is_trait === $implementer_classlike_storage->is_trait || !in_array($guide_classlike_storage->name, $implementer_classlike_storage->used_traits) || $implementer_method_storage->defining_fqcln !== $implementer_classlike_storage->name || !$implementer_method_storage->abstract && !$guide_method_storage->abstract) {
                Issue_Buffer::maybe_add(new Overridden_Method_Access('Method ' . $cased_implementer_method_id . ' has different access level than ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } else {
                Issue_Buffer::maybe_add(new Trait_Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' has different access level than ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            }
        }
        if ($guide_method_storage->final && $prevent_method_signature_mismatch && $prevent_abstract_override) {
            Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $cased_guide_method_id . ' is declared final and cannot be overridden', $code_location), $guide_method_storage->final_from_docblock ? $suppressed_issues + $implementer_classlike_storage->suppressed_issues : []);
        }
        if ($prevent_abstract_override && !$guide_method_storage->abstract && $implementer_method_storage->abstract && !$guide_classlike_storage->abstract && !$guide_classlike_storage->is_interface) {
            Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' cannot be abstract when inherited method ' . $cased_guide_method_id . ' is non-abstract', $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
        }
        if ($guide_method_storage->returns_by_ref && !$implementer_method_storage->returns_by_ref) {
            Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' must return by-reference', $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
        }
        if ($guide_method_storage->external_mutation_free && !$implementer_method_storage->external_mutation_free && !$guide_method_storage->mutation_free_inferred && $prevent_method_signature_mismatch) {
            Issue_Buffer::maybe_add(new Missing_Immutable_Annotation($cased_guide_method_id . ' is marked @psalm-external-mutation-free, but ' . $implementer_classlike_storage->name . '::' . ($guide_method_storage->cased_name ?: '') . ' is not marked @psalm-external-mutation-free', $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function compare_method_params(Codebase $codebase, ?Class_Method $stmt, Class_Like_Storage $implementer_classlike_storage, Class_Like_Storage $guide_classlike_storage, string $implementer_called_class_name, Method_Storage $guide_method_storage, Method_Storage $implementer_method_storage, Function_Like_Parameter $guide_param, Function_Like_Parameter $implementer_param, int $i, string $cased_guide_method_id, string $cased_implementer_method_id, bool $prevent_method_signature_mismatch, Code_Location $code_location, array $suppressed_issues): void
    {
        // ignore errors from stubbed/out of project files
        $config = Config::get_instance();
        if (!$implementer_classlike_storage->user_defined && (!$implementer_param->location || !$config->is_in_project_dirs($implementer_param->location->file_path))) {
            return;
        }
        if ($prevent_method_signature_mismatch) {
            if (!$guide_classlike_storage->user_defined && $guide_param->type) {
                $implementer_param_type = $implementer_param->signature_type;
                $guide_param_signature_type = $guide_param->type;
                $or_null_guide_param_signature_type = $guide_param->signature_type ? $guide_param->signature_type->get_builder() : null;
                if ($or_null_guide_param_signature_type) {
                    $or_null_guide_param_signature_type->add_type(new T_Null());
                }
                if ($cased_guide_method_id === 'Serializable::unserialize') {
                    $guide_param_signature_type = null;
                    $or_null_guide_param_signature_type = null;
                }
                if (!$guide_param->type->has_mixed() && !$guide_param->type->from_docblock && ($implementer_param_type || $guide_param_signature_type)) {
                    if ($implementer_param_type && (!$guide_param_signature_type || strtolower($implementer_param_type->get_id()) !== strtolower($guide_param_signature_type->get_id())) && (!$or_null_guide_param_signature_type || strtolower($implementer_param_type->get_id()) !== strtolower($or_null_guide_param_signature_type->get_id()))) {
                        if ($implementer_method_storage->cased_name === '__construct') {
                            Issue_Buffer::maybe_add(new Constructor_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_param_type . '\', expecting \'' . $guide_param_signature_type . '\' as defined by ' . $cased_guide_method_id, $implementer_param->location && $config->is_in_project_dirs($implementer_param->location->file_path) ? $implementer_param->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                        } else {
                            Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_param_type . '\', expecting \'' . $guide_param_signature_type . '\' as defined by ' . $cased_guide_method_id, $implementer_param->location && $config->is_in_project_dirs($implementer_param->location->file_path) ? $implementer_param->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                        }
                        return;
                    }
                }
            }
            if ($guide_param->name !== $implementer_param->name && $guide_method_storage->allow_named_arg_calls && $implementer_classlike_storage->user_defined && $implementer_param->location && $guide_method_storage->cased_name && (!str_starts_with($guide_method_storage->cased_name, '__') || $guide_classlike_storage->preserve_constructor_signature && $guide_method_storage->cased_name === '__construct') && $config->is_in_project_dirs($implementer_param->location->file_path)) {
                // even if it's just a single arg, it needs to be renamed in case it's called with a single named arg
                if ($config->allow_named_arg_calls || $guide_classlike_storage->location && !$config->is_in_project_dirs($guide_classlike_storage->location->file_path)) {
                    if ($codebase->alter_code) {
                        $project_analyzer = Project_Analyzer::get_instance();
                        if ($stmt && isset($project_analyzer->get_issues_to_fix()['ParamNameMismatch'])) {
                            $param_replacer = new Param_Replacement_Visitor($implementer_param->name, $guide_param->name);
                            $traverser = new Node_Traverser();
                            $traverser->add_visitor($param_replacer);
                            $traverser->traverse([$stmt]);
                            if ($replacements = $param_replacer->get_replacements()) {
                                File_Manipulation_Buffer::add($implementer_param->location->file_path, $replacements);
                            }
                        }
                    } else {
                        Issue_Buffer::maybe_add(new Param_Name_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong name $' . $implementer_param->name . ', expecting $' . $guide_param->name . ' as defined by ' . $cased_guide_method_id, $implementer_param->location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                    }
                }
            }
            if ($guide_classlike_storage->user_defined && $implementer_param->signature_type && $guide_param->signature_type) {
                self::compare_method_signature_params($codebase, $i, $guide_classlike_storage, $implementer_classlike_storage, $guide_method_storage, $implementer_method_storage, $guide_param, $implementer_param->signature_type, $cased_guide_method_id, $cased_implementer_method_id, $code_location, $suppressed_issues);
            }
        }
        if ($implementer_param->type && $guide_param->type && $implementer_param->type->get_id() !== $guide_param->type->get_id()) {
            self::compare_method_docblock_params($codebase, $i, $guide_classlike_storage, $implementer_classlike_storage, $implementer_called_class_name, $guide_method_storage, $implementer_method_storage, $cased_guide_method_id, $cased_implementer_method_id, $guide_param->type, $implementer_param->type, $code_location, $suppressed_issues);
        }
        if ($implementer_param->by_ref !== $guide_param->by_ref) {
            Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' is' . ($implementer_param->by_ref ? '' : ' not') . ' passed by reference, but argument ' . ($i + 1) . ' of ' . $cased_guide_method_id . ' is' . ($guide_param->by_ref ? '' : ' not'), $implementer_param->location && $config->is_in_project_dirs($implementer_param->location->file_path) ? $implementer_param->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function compare_method_signature_params(Codebase $codebase, int $i, Class_Like_Storage $guide_classlike_storage, Class_Like_Storage $implementer_classlike_storage, Method_Storage $guide_method_storage, Method_Storage $implementer_method_storage, Function_Like_Parameter $guide_param, Union $implementer_param_signature_type, string $cased_guide_method_id, string $cased_implementer_method_id, Code_Location $code_location, array $suppressed_issues): void
    {
        $guide_param_signature_type = $guide_param->signature_type ? Type_Expander::expand_union($codebase, $guide_param->signature_type, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->parent_class : $guide_classlike_storage->parent_class) : null;
        // CallMapHandler needed due to https://github.com/vimeo/psalm/issues/10378
        if (!$guide_param->signature_type && $guide_param->type && Internal_Call_Map_Handler::in_call_map($cased_guide_method_id)) {
            $guide_method_storage_param_type = Type_Expander::expand_union($codebase, $guide_param->type, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->parent_class : $guide_classlike_storage->parent_class);
            $builder = $guide_method_storage_param_type->get_builder();
            foreach ($builder->get_atomic_types() as $k => $t) {
                if ($t instanceof T_Template_Param) {
                    $builder->remove_type($k);
                    foreach ($t->as->get_atomic_types() as $as_t) {
                        $builder->add_type($as_t);
                    }
                }
            }
            if ($builder->has_mixed()) {
                foreach ($builder->get_atomic_types() as $k => $_) {
                    if ($k !== 'mixed') {
                        $builder->remove_type($k);
                    }
                }
            }
            $guide_method_storage_param_type = $builder->freeze();
            unset($builder);
            if (!$guide_method_storage_param_type->has_mixed() || $codebase->analysis_php_version_id >= 80000) {
                $guide_param_signature_type = $guide_method_storage_param_type;
            }
        }
        $implementer_param_signature_type = Type_Expander::expand_union($codebase, $implementer_param_signature_type, $implementer_classlike_storage->name, $implementer_classlike_storage->name, $implementer_classlike_storage->parent_class);
        $is_contained_by = $codebase->analysis_php_version_id >= 70400 && $guide_param_signature_type ? Union_Type_Comparator::is_contained_by($codebase, $guide_param_signature_type, $implementer_param_signature_type) : Union_Type_Comparator::is_contained_by_in_php($guide_param_signature_type, $implementer_param_signature_type);
        if (!$is_contained_by) {
            $config = Config::get_instance();
            if ($codebase->analysis_php_version_id >= 80000 || $guide_classlike_storage->is_trait === $implementer_classlike_storage->is_trait || !in_array($guide_classlike_storage->name, $implementer_classlike_storage->used_traits) || $implementer_method_storage->defining_fqcln !== $implementer_classlike_storage->name || !$implementer_method_storage->abstract && !$guide_method_storage->abstract) {
                if ($implementer_method_storage->cased_name === '__construct') {
                    Issue_Buffer::maybe_add(new Constructor_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_param_signature_type . '\', expecting \'' . $guide_param_signature_type . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location && $config->is_in_project_dirs($implementer_method_storage->params[$i]->location->file_path) ? $implementer_method_storage->params[$i]->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                } else {
                    Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_param_signature_type . '\', expecting \'' . $guide_param_signature_type . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location && $config->is_in_project_dirs($implementer_method_storage->params[$i]->location->file_path) ? $implementer_method_storage->params[$i]->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                }
            } else {
                Issue_Buffer::maybe_add(new Trait_Method_Signature_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_param_signature_type . '\', expecting \'' . $guide_param_signature_type . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location && $config->is_in_project_dirs($implementer_method_storage->params[$i]->location->file_path) ? $implementer_method_storage->params[$i]->location : $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            }
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function compare_method_docblock_params(Codebase $codebase, int $i, Class_Like_Storage $guide_classlike_storage, Class_Like_Storage $implementer_classlike_storage, string $implementer_called_class_name, Method_Storage $guide_method_storage, Method_Storage $implementer_method_storage, string $cased_guide_method_id, string $cased_implementer_method_id, Union $guide_param_type, Union $implementer_param_type, Code_Location $code_location, array $suppressed_issues): void
    {
        $implementer_method_storage_param_type = Type_Expander::expand_union($codebase, $implementer_param_type, $implementer_classlike_storage->name, $implementer_called_class_name, $implementer_classlike_storage->parent_class);
        $guide_method_storage_param_type = Type_Expander::expand_union($codebase, $guide_param_type, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->parent_class : $guide_classlike_storage->parent_class);
        $guide_class_name = $guide_classlike_storage->name;
        if ($implementer_classlike_storage->is_trait) {
            $implementer_called_class_storage = $codebase->classlike_storage_provider->get($implementer_called_class_name);
            if (isset($implementer_called_class_storage->template_extended_params[$implementer_classlike_storage->name])) {
                self::transform_templates($implementer_called_class_storage->template_extended_params, $implementer_classlike_storage->name, $implementer_method_storage_param_type, $codebase);
                self::transform_templates($implementer_called_class_storage->template_extended_params, $guide_class_name, $guide_method_storage_param_type, $codebase);
            }
        }
        $builder = $implementer_method_storage_param_type->get_builder();
        foreach ($builder->get_atomic_types() as $k => $t) {
            if ($t instanceof T_Template_Param && str_starts_with($t->defining_class, 'fn-')) {
                $builder->remove_type($k);
                foreach ($t->as->get_atomic_types() as $as_t) {
                    $builder->add_type($as_t);
                }
            }
        }
        $implementer_method_storage_param_type = $builder->freeze();
        $builder = $guide_method_storage_param_type->get_builder();
        foreach ($builder->get_atomic_types() as $k => $t) {
            if ($t instanceof T_Template_Param && str_starts_with($t->defining_class, 'fn-')) {
                $builder->remove_type($k);
                foreach ($t->as->get_atomic_types() as $as_t) {
                    $builder->add_type($as_t);
                }
            }
        }
        $guide_method_storage_param_type = $builder->freeze();
        unset($builder);
        if ($implementer_classlike_storage->template_extended_params) {
            self::transform_templates($implementer_classlike_storage->template_extended_params, $guide_class_name, $guide_method_storage_param_type, $codebase);
        }
        $union_comparison_results = new Type_Comparison_Result();
        if (!Union_Type_Comparator::is_contained_by($codebase, $guide_method_storage_param_type, $implementer_method_storage_param_type, !$guide_classlike_storage->user_defined, !$guide_classlike_storage->user_defined, $union_comparison_results)) {
            // is the declared return type more specific than the inferred one?
            if ($union_comparison_results->type_coerced) {
                if ($guide_classlike_storage->user_defined) {
                    Issue_Buffer::maybe_add(new More_Specific_Implemented_Param_Type('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has the more specific type \'' . $implementer_method_storage_param_type->get_id() . '\', expecting \'' . $guide_method_storage_param_type->get_id() . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
                }
            } else if (Union_Type_Comparator::is_contained_by($codebase, $implementer_method_storage_param_type, $guide_method_storage_param_type, !$guide_classlike_storage->user_defined, !$guide_classlike_storage->user_defined)) {
                Issue_Buffer::maybe_add(new More_Specific_Implemented_Param_Type('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has the more specific type \'' . $implementer_method_storage_param_type->get_id() . '\', expecting \'' . $guide_method_storage_param_type->get_id() . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } elseif ($guide_class_name == $implementer_called_class_name) {
                Issue_Buffer::maybe_add(new Mismatching_Docblock_Param_Type('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_method_storage_param_type->get_id() . '\' in @method annotation, expecting \'' . $guide_method_storage_param_type->get_id() . '\'', $implementer_method_storage->params[$i]->location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } else {
                Issue_Buffer::maybe_add(new Implemented_Param_Type_Mismatch('Argument ' . ($i + 1) . ' of ' . $cased_implementer_method_id . ' has wrong type \'' . $implementer_method_storage_param_type->get_id() . '\', expecting \'' . $guide_method_storage_param_type->get_id() . '\' as defined by ' . $cased_guide_method_id, $implementer_method_storage->params[$i]->location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            }
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function compare_method_signature_return_types(Codebase $codebase, Class_Like_Storage $guide_classlike_storage, Class_Like_Storage $implementer_classlike_storage, Method_Storage $guide_method_storage, Method_Storage $implementer_method_storage, Union $guide_signature_return_type, string $cased_guide_method_id, string $implementer_called_class_name, string $cased_implementer_method_id, Code_Location $code_location, array $suppressed_issues): void
    {
        $guide_signature_return_type = Type_Expander::expand_union($codebase, $guide_signature_return_type, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract || $guide_classlike_storage->final ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait && $guide_method_storage->abstract ? $implementer_classlike_storage->parent_class : $guide_classlike_storage->parent_class, true, true, $implementer_method_storage->final);
        $implementer_signature_return_type = $implementer_method_storage->signature_return_type ? Type_Expander::expand_union($codebase, $implementer_method_storage->signature_return_type, $implementer_classlike_storage->is_trait ? $implementer_called_class_name : $implementer_classlike_storage->name, $implementer_classlike_storage->is_trait ? $implementer_called_class_name : $implementer_classlike_storage->name, $implementer_classlike_storage->parent_class) : null;
        $is_contained_by = $codebase->analysis_php_version_id >= 70400 && $implementer_signature_return_type ? Union_Type_Comparator::is_contained_by($codebase, $implementer_signature_return_type, $guide_signature_return_type) : Union_Type_Comparator::is_contained_by_in_php($implementer_signature_return_type, $guide_signature_return_type);
        if (!$is_contained_by) {
            if ($implementer_signature_return_type === null && array_any($implementer_method_storage->attributes, static fn(Attribute_Storage $s): bool => $s->fq_class_name === 'ReturnTypeWillChange')) {
                // no error if return type will change and no signature set at all
            } elseif ($codebase->analysis_php_version_id >= 80000 || $guide_classlike_storage->is_trait === $implementer_classlike_storage->is_trait || !in_array($guide_classlike_storage->name, $implementer_classlike_storage->used_traits) || $implementer_method_storage->defining_fqcln !== $implementer_classlike_storage->name || !$implementer_method_storage->abstract && !$guide_method_storage->abstract) {
                Issue_Buffer::maybe_add(new Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' with return type \'' . $implementer_signature_return_type . '\' is different to return type \'' . $guide_signature_return_type . '\' of inherited method ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } else {
                Issue_Buffer::maybe_add(new Trait_Method_Signature_Mismatch('Method ' . $cased_implementer_method_id . ' with return type \'' . $implementer_signature_return_type . '\' is different to return type \'' . $guide_signature_return_type . '\' of inherited method ' . $cased_guide_method_id, $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            }
        }
    }
    /**
     * @param  string[]         $suppressed_issues
     */
    private static function compare_method_docblock_return_types(Codebase $codebase, Class_Like_Storage $guide_classlike_storage, Class_Like_Storage $implementer_classlike_storage, Method_Storage $implementer_method_storage, Union $guide_return_type, Union $implementer_return_type, string $cased_guide_method_id, string $implementer_called_class_name, ?Method_Identifier $implementer_declaring_method_id, Code_Location $code_location, array $suppressed_issues): void
    {
        $implementer_method_storage_return_type = Type_Expander::expand_union($codebase, $implementer_return_type, $implementer_classlike_storage->is_trait ? $implementer_called_class_name : $implementer_classlike_storage->name, $implementer_called_class_name, $implementer_classlike_storage->parent_class);
        $guide_method_storage_return_type = Type_Expander::expand_union($codebase, $guide_return_type, $guide_classlike_storage->is_trait ? $implementer_classlike_storage->name : $guide_classlike_storage->name, $guide_classlike_storage->is_trait || $implementer_method_storage->final ? $implementer_called_class_name : $guide_classlike_storage->name, $guide_classlike_storage->parent_class, true, true, $implementer_method_storage->final);
        $guide_class_name = $guide_classlike_storage->name;
        if ($implementer_classlike_storage->template_extended_params) {
            self::transform_templates($implementer_classlike_storage->template_extended_params, $guide_class_name, $guide_method_storage_return_type, $codebase);
            if ($implementer_method_storage->defining_fqcln) {
                self::transform_templates($implementer_classlike_storage->template_extended_params, $implementer_method_storage->defining_fqcln, $implementer_method_storage_return_type, $codebase);
            }
        }
        if ($implementer_classlike_storage->is_trait) {
            $implementer_called_class_storage = $codebase->classlike_storage_provider->get($implementer_called_class_name);
            if ($implementer_called_class_storage->template_extended_params) {
                self::transform_templates($implementer_called_class_storage->template_extended_params, $implementer_classlike_storage->name, $implementer_method_storage_return_type, $codebase);
                self::transform_templates($implementer_called_class_storage->template_extended_params, $guide_class_name, $guide_method_storage_return_type, $codebase);
            }
        }
        // treat void as null when comparing against docblock implementer
        if ($implementer_method_storage_return_type->is_void()) {
            $implementer_method_storage_return_type = Type::get_null();
        }
        if ($guide_method_storage_return_type->is_void()) {
            $guide_method_storage_return_type = Type::get_null();
        }
        $union_comparison_results = new Type_Comparison_Result();
        if (!Union_Type_Comparator::is_contained_by($codebase, $implementer_method_storage_return_type, $guide_method_storage_return_type, false, false, $union_comparison_results)) {
            // is the declared return type more specific than the inferred one?
            if ($union_comparison_results->type_coerced) {
                Issue_Buffer::maybe_add(new Less_Specific_Implemented_Return_Type('The inherited return type \'' . $guide_method_storage_return_type->get_id() . '\' for ' . $cased_guide_method_id . ' is more specific than the implemented ' . 'return type for ' . $implementer_declaring_method_id . ' \'' . $implementer_method_storage_return_type->get_id() . '\'', $implementer_method_storage->return_type_location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } elseif ($guide_class_name == $implementer_called_class_name) {
                Issue_Buffer::maybe_add(new Mismatching_Docblock_Return_Type('The inherited return type \'' . $guide_method_storage_return_type->get_id() . '\' for ' . $cased_guide_method_id . ' is different to the corresponding ' . '@method annotation \'' . $implementer_method_storage_return_type->get_id() . '\'', $implementer_method_storage->return_type_location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            } else {
                Issue_Buffer::maybe_add(new Implemented_Return_Type_Mismatch('The inherited return type \'' . $guide_method_storage_return_type->get_id() . '\' for ' . $cased_guide_method_id . ' is different to the implemented ' . 'return type for ' . $implementer_declaring_method_id . ' \'' . $implementer_method_storage_return_type->get_id() . '\'', $implementer_method_storage->return_type_location ?: $code_location), $suppressed_issues + $implementer_classlike_storage->suppressed_issues);
            }
        }
    }
    /**
     * @param  array<string, array<string, Union>>  $template_extended_params
     */
    private static function transform_templates(array $template_extended_params, string $base_class_name, Union &$templated_type, Codebase $codebase): void
    {
        if (isset($template_extended_params[$base_class_name])) {
            $map = $template_extended_params[$base_class_name];
            $template_types = [];
            foreach ($map as $key => $mapped_type) {
                $new_bases = [];
                foreach ($mapped_type->get_template_types() as $mapped_atomic_type) {
                    if ($mapped_atomic_type->defining_class === $base_class_name) {
                        continue;
                    }
                    $new_bases[] = $mapped_atomic_type->defining_class;
                }
                foreach ($new_bases as $new_base_class_name) {
                    self::transform_templates($template_extended_params, $new_base_class_name, $mapped_type, $codebase);
                }
                $template_types[$key][$base_class_name] = $mapped_type;
            }
            $template_result = new Template_Result([], $template_types);
            $templated_type = Template_Inferred_Type_Replacer::replace($templated_type, $template_result, $codebase);
        }
    }
}