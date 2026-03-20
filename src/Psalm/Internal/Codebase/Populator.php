<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Backed_Enum;
use InvalidArgumentException;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Issue\Circular_Reference;
use Psalm\Issue\Undefined_Trait;
use Psalm\Issue_Buffer;
use Psalm\Progress\Progress;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Property_Storage;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Union;
use Unit_Enum;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_keys;
use function array_merge;
use function array_splice;
use function count;
use function in_array;
use function key;
use function reset;
use function strpos;
use function strtolower;
/**
 * @internal
 *
 * Populates file and class information so that analysis can work properly
 */
final class Populator
{
    /**
     * @var array<lowercase-string, list<ClassLikeStorage>>
     */
    private array $invalid_class_storages = [];
    public function __construct(private readonly Class_Like_Storage_Provider $classlike_storage_provider, private readonly File_Storage_Provider $file_storage_provider, private readonly Class_Likes $classlikes, private readonly File_Reference_Provider $file_reference_provider, private readonly Progress $progress)
    {
    }
    public function populate_codebase(): void
    {
        $this->progress->debug('ClassLikeStorage is populating' . "\n");
        foreach ($this->classlike_storage_provider->get_new() as $class_storage) {
            $this->populate_class_like_storage($class_storage);
        }
        $this->progress->debug('ClassLikeStorage is populated' . "\n");
        $this->progress->debug('FileStorage is populating' . "\n");
        $all_file_storage = $this->file_storage_provider->get_new();
        foreach ($all_file_storage as $file_storage) {
            $this->populate_file_storage($file_storage);
        }
        foreach ($this->classlike_storage_provider->get_new() as $class_storage) {
            foreach ($class_storage->dependent_classlikes as $dependent_classlike_lc => $_) {
                try {
                    $dependee_storage = $this->classlike_storage_provider->get($dependent_classlike_lc);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $class_storage->dependent_classlikes += $dependee_storage->dependent_classlikes;
            }
        }
        $this->progress->debug('FileStorage is populated' . "\n");
        Class_Like_Storage_Provider::populated();
        File_Storage_Provider::populated();
    }
    private function populate_class_like_storage(Class_Like_Storage $storage, array $dependent_classlikes = []): void
    {
        if ($storage->populated) {
            return;
        }
        $fq_classlike_name_lc = strtolower($storage->name);
        if (isset($dependent_classlikes[$fq_classlike_name_lc])) {
            if ($storage->location) {
                Issue_Buffer::maybe_add(new Circular_Reference('Circular reference discovered when loading ' . $storage->name, $storage->location));
            }
            return;
        }
        $storage_provider = $this->classlike_storage_provider;
        $dependent_classlikes[$fq_classlike_name_lc] = true;
        foreach ($storage->used_traits as $used_trait_lc => $_) {
            $this->populate_data_from_trait($storage, $storage_provider, $dependent_classlikes, $used_trait_lc);
        }
        if ($storage->parent_classes) {
            $this->populate_data_from_parent_class($storage, $storage_provider, $dependent_classlikes, reset($storage->parent_classes));
        }
        if (!strpos($fq_classlike_name_lc, '\\') && !isset($storage->methods['__construct']) && isset($storage->methods[$fq_classlike_name_lc]) && !$storage->is_interface && !$storage->is_trait) {
            $storage->methods['__construct'] = $storage->methods[$fq_classlike_name_lc];
        }
        foreach ($storage->direct_interface_parents as $parent_interface_lc => $_) {
            $this->populate_interface_data_from_parent_interface($storage, $storage_provider, $dependent_classlikes, $parent_interface_lc);
        }
        foreach ($storage->direct_class_interfaces as $implemented_interface_lc => $_) {
            $this->populate_data_from_implemented_interface($storage, $storage_provider, $dependent_classlikes, $implemented_interface_lc);
        }
        if ($storage->location) {
            $file_path = $storage->location->file_path;
            foreach ($storage->parent_interfaces as $parent_interface_lc) {
                $this->file_reference_provider->add_file_inheritance_to_class($file_path, $parent_interface_lc);
            }
            foreach ($storage->parent_classes as $parent_class_lc => $_) {
                $this->file_reference_provider->add_file_inheritance_to_class($file_path, $parent_class_lc);
            }
            foreach ($storage->class_implements as $implemented_interface) {
                $this->file_reference_provider->add_file_inheritance_to_class($file_path, strtolower($implemented_interface));
            }
            foreach ($storage->used_traits as $used_trait_lc => $_) {
                $this->file_reference_provider->add_file_inheritance_to_class($file_path, $used_trait_lc);
            }
        }
        if ($storage->mutation_free || $storage->external_mutation_free) {
            foreach ($storage->methods as $method) {
                if (!$method->is_static && !$method->external_mutation_free) {
                    $method->mutation_free = $storage->mutation_free;
                    $method->external_mutation_free = $storage->external_mutation_free;
                    $method->immutable = $storage->mutation_free;
                }
            }
            if ($storage->mutation_free) {
                foreach ($storage->properties as $property) {
                    if (!$property->is_static) {
                        $property->readonly = true;
                    }
                }
            }
        }
        if ($storage->specialize_instance) {
            foreach ($storage->methods as $method) {
                if (!$method->is_static) {
                    $method->specialize_call = true;
                }
            }
        }
        if (!$storage->is_interface && !$storage->is_trait) {
            foreach ($storage->methods as $method) {
                $method->internal = [...$storage->internal, ...$method->internal];
            }
            foreach ($storage->properties as $property) {
                $property->internal = [...$storage->internal, ...$property->internal];
            }
        }
        $this->populate_overridden_methods($storage, $storage_provider);
        $this->progress->debug('Have populated ' . $storage->name . "\n");
        $storage->populated = true;
        if (isset($this->invalid_class_storages[$fq_classlike_name_lc])) {
            foreach ($this->invalid_class_storages[$fq_classlike_name_lc] as $dependency) {
                // Dependencies may not be fully set yet, so we have to loop through dependencies of dependencies
                $dependencies = [strtolower($dependency->name) => true];
                do {
                    $current_dependency_name = key(array_splice($dependencies, 0, 1));
                    // Key shift
                    $current_dependency = $storage_provider->get($current_dependency_name);
                    $dependencies += $current_dependency->dependent_classlikes;
                    if (isset($current_dependency->dependent_classlikes[$fq_classlike_name_lc])) {
                        if ($dependency->location) {
                            Issue_Buffer::maybe_add(new Circular_Reference('Circular reference discovered when loading ' . $dependency->name, $dependency->location));
                        }
                        continue 2;
                    }
                } while (!empty($dependencies));
                $dependency->populated = false;
                unset($dependency->invalid_dependencies[$fq_classlike_name_lc]);
                $this->populate_class_like_storage($dependency, $dependent_classlikes);
            }
            unset($this->invalid_class_storages[$fq_classlike_name_lc]);
        }
    }
    private function populate_overridden_methods(Class_Like_Storage $storage, Class_Like_Storage_Provider $storage_provider): void
    {
        $interface_method_implementers = [];
        foreach ($storage->class_implements as $interface) {
            try {
                $implemented_interface = strtolower($this->classlikes->get_un_aliased_name($interface));
                $implemented_interface_storage = $storage_provider->get($implemented_interface);
            } catch (InvalidArgumentException) {
                continue;
            }
            $implemented_interface_storage->dependent_classlikes[strtolower($storage->name)] = true;
            foreach ($implemented_interface_storage->methods as $method_name => $method) {
                if ($method->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC) {
                    $interface_method_implementers[$method_name][] = new Method_Identifier($implemented_interface_storage->name, $method_name);
                }
            }
        }
        foreach ($interface_method_implementers as $method_name => $interface_method_ids) {
            if (count($interface_method_ids) === 1) {
                if (isset($storage->methods[$method_name])) {
                    $method_storage = $storage->methods[$method_name];
                    if ($method_storage->signature_return_type && !$method_storage->signature_return_type->is_void() && $method_storage->return_type === $method_storage->signature_return_type) {
                        $interface_fqcln = $interface_method_ids[0]->fq_class_name;
                        $interface_storage = $storage_provider->get($interface_fqcln);
                        if (isset($interface_storage->methods[$method_name])) {
                            $interface_method_storage = $interface_storage->methods[$method_name];
                            if ($interface_method_storage->throws && (!$method_storage->throws || $method_storage->inheritdoc)) {
                                $method_storage->throws += $interface_method_storage->throws;
                            }
                        }
                    }
                }
            }
            foreach ($interface_method_ids as $interface_method_id) {
                $storage->overridden_method_ids[$method_name][$interface_method_id->fq_class_name] = $interface_method_id;
            }
        }
        $storage->documenting_method_ids = [];
        foreach ($storage->methods as $method_name => $method_storage) {
            if (isset($storage->overridden_method_ids[$method_name])) {
                $overridden_method_ids = $storage->overridden_method_ids[$method_name];
                $candidate_overridden_ids = null;
                $declaring_class_storages = [];
                foreach ($overridden_method_ids as $declaring_method_id) {
                    $declaring_class = $declaring_method_id->fq_class_name;
                    $declaring_class_storage = $declaring_class_storages[$declaring_class] = $this->classlike_storage_provider->get($declaring_class);
                    if ($candidate_overridden_ids === null) {
                        $candidate_overridden_ids = ($declaring_class_storage->overridden_method_ids[$method_name] ?? []) + [$declaring_method_id->fq_class_name => $declaring_method_id];
                    } else {
                        $candidate_overridden_ids = array_intersect_key($candidate_overridden_ids, ($declaring_class_storage->overridden_method_ids[$method_name] ?? []) + [$declaring_method_id->fq_class_name => $declaring_method_id]);
                    }
                }
                foreach ($overridden_method_ids as $declaring_method_id) {
                    $declaring_class = $declaring_method_id->fq_class_name;
                    $declaring_method_name = $declaring_method_id->method_name;
                    $declaring_class_storage = $declaring_class_storages[$declaring_class];
                    $declaring_method_storage = $declaring_class_storage->methods[$declaring_method_name] ?? $declaring_class_storage->pseudo_methods[$declaring_method_name] ?? $declaring_class_storage->pseudo_static_methods[$declaring_method_name];
                    if (($declaring_method_storage->has_docblock_param_types || $declaring_method_storage->has_docblock_return_type) && !$method_storage->has_docblock_param_types && !$method_storage->has_docblock_return_type && $method_storage->inherited_return_type !== null) {
                        if (!isset($storage->documenting_method_ids[$method_name]) || (string) $storage->documenting_method_ids[$method_name] === (string) $declaring_method_id) {
                            $storage->documenting_method_ids[$method_name] = $declaring_method_id;
                            $method_storage->inherited_return_type = true;
                        } else if (in_array($storage->documenting_method_ids[$method_name]->fq_class_name, $declaring_class_storage->parent_interfaces)) {
                            $storage->documenting_method_ids[$method_name] = $declaring_method_id;
                            $method_storage->inherited_return_type = true;
                        } else {
                            $documenting_class_storage = $declaring_class_storages[$storage->documenting_method_ids[$method_name]->fq_class_name];
                            if (!in_array($declaring_class, $documenting_class_storage->parent_interfaces) && $documenting_class_storage->is_interface) {
                                unset($storage->documenting_method_ids[$method_name]);
                                $method_storage->inherited_return_type = null;
                            }
                        }
                    }
                    // tell the declaring class it's overridden downstream
                    $declaring_method_storage->overridden_downstream = true;
                    $declaring_method_storage->overridden_somewhere = true;
                    if ($declaring_method_storage->mutation_free_inferred) {
                        $declaring_method_storage->mutation_free = false;
                        $declaring_method_storage->external_mutation_free = false;
                        $declaring_method_storage->mutation_free_inferred = false;
                    }
                    if ($declaring_method_storage->throws && (!$method_storage->throws || $method_storage->inheritdoc)) {
                        $method_storage->throws += $declaring_method_storage->throws;
                    }
                }
            }
        }
    }
    private function populate_data_from_trait(Class_Like_Storage $storage, Class_Like_Storage_Provider $storage_provider, array $dependent_classlikes, string $used_trait_lc): void
    {
        try {
            $used_trait_lc = strtolower($this->classlikes->get_un_aliased_name($used_trait_lc));
            $trait_storage = $storage_provider->get($used_trait_lc);
        } catch (InvalidArgumentException) {
            return;
        }
        $this->populate_class_like_storage($trait_storage, $dependent_classlikes);
        $this->inherit_constants_from_trait($storage, $trait_storage);
        $this->inherit_methods_from_parent($storage, $trait_storage);
        $this->inherit_properties_from_parent($storage, $trait_storage);
        self::extend_template_params($storage, $trait_storage, false);
        $storage->pseudo_property_get_types += $trait_storage->pseudo_property_get_types;
        $storage->pseudo_property_set_types += $trait_storage->pseudo_property_set_types;
        $storage->pseudo_static_methods += $trait_storage->pseudo_static_methods;
        $storage->pseudo_methods += $trait_storage->pseudo_methods;
        $storage->declaring_pseudo_method_ids += $trait_storage->declaring_pseudo_method_ids;
    }
    private static function extend_type(Union $type, Class_Like_Storage $storage): Union
    {
        $extended_types = [];
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Template_Param) {
                $referenced_type = $storage->template_extended_params[$atomic_type->defining_class][$atomic_type->param_name] ?? null;
                if ($referenced_type) {
                    foreach ($referenced_type->get_atomic_types() as $atomic_referenced_type) {
                        if (!$atomic_referenced_type instanceof T_Template_Param) {
                            $extended_types[] = $atomic_referenced_type;
                        } else {
                            $extended_types[] = $atomic_type;
                        }
                    }
                } else {
                    $extended_types[] = $atomic_type;
                }
            } else {
                $extended_types[] = $atomic_type;
            }
        }
        return new Union($extended_types);
    }
    private function populate_data_from_parent_class(Class_Like_Storage $storage, Class_Like_Storage_Provider $storage_provider, array $dependent_classlikes, string $parent_storage_class): void
    {
        $parent_storage_class = strtolower($this->classlikes->get_un_aliased_name($parent_storage_class));
        try {
            $parent_storage = $storage_provider->get($parent_storage_class);
        } catch (InvalidArgumentException) {
            $this->progress->debug('Populator could not find dependency (' . __LINE__ . ")\n");
            $storage->invalid_dependencies[$parent_storage_class] = true;
            $this->invalid_class_storages[$parent_storage_class][] = $storage;
            return;
        }
        $this->populate_class_like_storage($parent_storage, $dependent_classlikes);
        $storage->parent_classes = [...$storage->parent_classes, ...$parent_storage->parent_classes];
        self::extend_template_params($storage, $parent_storage, true);
        $this->inherit_methods_from_parent($storage, $parent_storage);
        $this->inherit_properties_from_parent($storage, $parent_storage);
        $storage->class_implements = [...$storage->class_implements, ...$parent_storage->class_implements];
        $storage->invalid_dependencies = [...$storage->invalid_dependencies, ...$parent_storage->invalid_dependencies];
        if ($parent_storage->has_visitor_issues) {
            $storage->has_visitor_issues = true;
        }
        $storage->constants = [...array_filter($parent_storage->constants, static fn(Class_Constant_Storage $constant): bool => $constant->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || $constant->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED), ...$storage->constants];
        if ($parent_storage->preserve_constructor_signature) {
            $storage->preserve_constructor_signature = true;
        }
        if (($parent_storage->named_mixins || $parent_storage->templated_mixins) && (!$storage->named_mixins || !$storage->templated_mixins)) {
            $storage->mixin_declaring_fqcln = $parent_storage->mixin_declaring_fqcln;
            if (!$storage->named_mixins) {
                $storage->named_mixins = $parent_storage->named_mixins;
            }
            if (!$storage->templated_mixins) {
                $storage->templated_mixins = $parent_storage->templated_mixins;
            }
        }
        $storage->pseudo_property_get_types += $parent_storage->pseudo_property_get_types;
        $storage->pseudo_property_set_types += $parent_storage->pseudo_property_set_types;
        $parent_storage->dependent_classlikes[strtolower($storage->name)] = true;
        foreach ($parent_storage->pseudo_static_methods as $method_name => $pseudo_method) {
            if (!isset($storage->methods[$method_name])) {
                $storage->pseudo_static_methods[$method_name] = $pseudo_method;
            }
        }
        foreach ($parent_storage->pseudo_methods as $method_name => $pseudo_method) {
            if (!isset($storage->methods[$method_name])) {
                $storage->pseudo_methods[$method_name] = $pseudo_method;
            }
        }
        foreach ($parent_storage->declaring_pseudo_method_ids as $method_name => $pseudo_method_id) {
            if (!isset($storage->methods[$method_name])) {
                $storage->declaring_pseudo_method_ids[$method_name] = $pseudo_method_id;
            }
        }
        $parent_storage->has_children = true;
    }
    private function populate_interface_data(Class_Like_Storage $storage, Class_Like_Storage $interface_storage, Class_Like_Storage_Provider $storage_provider, array $dependent_classlikes): void
    {
        $this->populate_class_like_storage($interface_storage, $dependent_classlikes);
        // copy over any constants
        $storage->constants = [...array_filter($interface_storage->constants, static fn(Class_Constant_Storage $constant): bool => $constant->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC), ...$storage->constants];
        $storage->invalid_dependencies = [...$storage->invalid_dependencies, ...$interface_storage->invalid_dependencies];
        self::extend_template_params($storage, $interface_storage, false);
        $new_parents = array_keys($interface_storage->parent_interfaces);
        $new_parents[] = $interface_storage->name;
        foreach ($new_parents as $new_parent) {
            try {
                $new_parent = strtolower($this->classlikes->get_un_aliased_name($new_parent));
                $new_parent_interface_storage = $storage_provider->get($new_parent);
            } catch (InvalidArgumentException) {
                continue;
            }
            $new_parent_interface_storage->dependent_classlikes[strtolower($storage->name)] = true;
        }
    }
    private static function extend_template_params(Class_Like_Storage $storage, Class_Like_Storage $parent_storage, bool $from_direct_parent): void
    {
        if ($parent_storage->yield && !$storage->yield) {
            $storage->yield = $parent_storage->yield;
            $storage->declaring_yield_fqcn ??= $parent_storage->name;
        }
        if ($parent_storage->template_types) {
            $storage->template_extended_params[$parent_storage->name] = [];
            if (isset($storage->template_extended_offsets[$parent_storage->name])) {
                foreach ($storage->template_extended_offsets[$parent_storage->name] as $i => $type) {
                    $parent_template_type_names = array_keys($parent_storage->template_types);
                    $mapped_name = $parent_template_type_names[$i] ?? null;
                    if ($mapped_name) {
                        $storage->template_extended_params[$parent_storage->name][$mapped_name] = $type;
                    }
                }
                if ($parent_storage->template_extended_params) {
                    foreach ($parent_storage->template_extended_params as $t_storage_class => $type_map) {
                        foreach ($type_map as $i => $type) {
                            $storage->template_extended_params[$t_storage_class][$i] = self::extend_type($type, $storage);
                        }
                    }
                }
            } else {
                foreach ($parent_storage->template_types as $template_name => $template_type_map) {
                    foreach ($template_type_map as $template_type) {
                        $default_param = $template_type->set_properties(['from_docblock' => false]);
                        $storage->template_extended_params[$parent_storage->name][$template_name] = $default_param;
                    }
                }
                if ($from_direct_parent) {
                    if ($parent_storage->template_extended_params) {
                        $storage->template_extended_params = array_merge($storage->template_extended_params, $parent_storage->template_extended_params);
                    }
                }
            }
        } elseif ($parent_storage->template_extended_params) {
            $storage->template_extended_params = array_merge($storage->template_extended_params ?: [], $parent_storage->template_extended_params);
        }
    }
    private function populate_interface_data_from_parent_interface(Class_Like_Storage $storage, Class_Like_Storage_Provider $storage_provider, array $dependent_classlikes, string $parent_interface_lc): void
    {
        try {
            $parent_interface_lc = strtolower($this->classlikes->get_un_aliased_name($parent_interface_lc));
            $parent_interface_storage = $storage_provider->get($parent_interface_lc);
        } catch (InvalidArgumentException) {
            $this->progress->debug('Populator could not find dependency (' . __LINE__ . ")\n");
            $storage->invalid_dependencies[$parent_interface_lc] = true;
            return;
        }
        $this->populate_interface_data($storage, $parent_interface_storage, $storage_provider, $dependent_classlikes);
        $this->inherit_methods_from_parent($storage, $parent_interface_storage);
        $storage->pseudo_methods += $parent_interface_storage->pseudo_methods;
        $storage->declaring_pseudo_method_ids += $parent_interface_storage->declaring_pseudo_method_ids;
        $storage->parent_interfaces = [...$parent_interface_storage->parent_interfaces, ...$storage->parent_interfaces];
        if (isset($storage->parent_interfaces[strtolower(Unit_Enum::class)])) {
            $storage->declaring_property_ids['name'] = $storage->name;
            $storage->appearing_property_ids['name'] = "{$storage->name}::\$name";
            $storage->properties['name'] = new Property_Storage();
            $storage->properties['name']->type = new Union([new T_Non_Empty_String()]);
        }
        if (isset($storage->parent_interfaces[strtolower(Backed_Enum::class)])) {
            $storage->declaring_property_ids['value'] = $storage->name;
            $storage->appearing_property_ids['value'] = "{$storage->name}::\$value";
            $storage->properties['value'] = new Property_Storage();
            $storage->properties['value']->type = new Union([new T_Int(), new T_String()]);
        }
    }
    private function populate_data_from_implemented_interface(Class_Like_Storage $storage, Class_Like_Storage_Provider $storage_provider, array $dependent_classlikes, string $implemented_interface_lc): void
    {
        try {
            $implemented_interface_lc = strtolower($this->classlikes->get_un_aliased_name($implemented_interface_lc));
            $implemented_interface_storage = $storage_provider->get($implemented_interface_lc);
        } catch (InvalidArgumentException) {
            $this->progress->debug('Populator could not find dependency (' . __LINE__ . ")\n");
            $storage->invalid_dependencies[$implemented_interface_lc] = true;
            return;
        }
        $this->populate_interface_data($storage, $implemented_interface_storage, $storage_provider, $dependent_classlikes);
        $storage->class_implements = [...$storage->class_implements, ...$implemented_interface_storage->parent_interfaces];
    }
    /**
     * @param  array<string, bool> $dependent_file_paths
     */
    private function populate_file_storage(File_Storage $storage, array $dependent_file_paths = []): void
    {
        if ($storage->populated) {
            return;
        }
        $file_path_lc = strtolower($storage->file_path);
        if (isset($dependent_file_paths[$file_path_lc])) {
            return;
        }
        $dependent_file_paths[$file_path_lc] = true;
        $all_required_file_paths = $storage->required_file_paths;
        foreach ($storage->required_file_paths as $included_file_path => $_) {
            try {
                $included_file_storage = $this->file_storage_provider->get($included_file_path);
            } catch (InvalidArgumentException) {
                continue;
            }
            $this->populate_file_storage($included_file_storage, $dependent_file_paths);
            $all_required_file_paths = $all_required_file_paths + $included_file_storage->required_file_paths;
        }
        foreach ($all_required_file_paths as $included_file_path => $_) {
            try {
                $included_file_storage = $this->file_storage_provider->get($included_file_path);
            } catch (InvalidArgumentException) {
                continue;
            }
            $storage->declaring_function_ids = [...$included_file_storage->declaring_function_ids, ...$storage->declaring_function_ids];
            $storage->declaring_constants = [...$included_file_storage->declaring_constants, ...$storage->declaring_constants];
        }
        foreach ($storage->referenced_classlikes as $fq_class_name) {
            try {
                $classlike_storage = $this->classlike_storage_provider->get($fq_class_name);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (!$classlike_storage->location) {
                continue;
            }
            try {
                $included_file_storage = $this->file_storage_provider->get($classlike_storage->location->file_path);
            } catch (InvalidArgumentException) {
                continue;
            }
            foreach ($classlike_storage->used_traits as $used_trait) {
                try {
                    $trait_storage = $this->classlike_storage_provider->get($used_trait);
                } catch (InvalidArgumentException) {
                    continue;
                }
                if (!$trait_storage->location) {
                    continue;
                }
                try {
                    $included_trait_file_storage = $this->file_storage_provider->get($trait_storage->location->file_path);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $storage->declaring_function_ids = [...$included_trait_file_storage->declaring_function_ids, ...$storage->declaring_function_ids];
            }
            $storage->declaring_function_ids = [...$included_file_storage->declaring_function_ids, ...$storage->declaring_function_ids];
        }
        $storage->required_file_paths = $all_required_file_paths;
        foreach ($all_required_file_paths as $required_file_path) {
            try {
                $required_file_storage = $this->file_storage_provider->get($required_file_path);
            } catch (InvalidArgumentException) {
                continue;
            }
            $required_file_storage->required_by_file_paths += [$file_path_lc => $storage->file_path];
        }
        foreach ($storage->required_classes as $required_classlike) {
            try {
                $classlike_storage = $this->classlike_storage_provider->get($required_classlike);
            } catch (InvalidArgumentException) {
                continue;
            }
            if (!$classlike_storage->location) {
                continue;
            }
            try {
                $required_file_storage = $this->file_storage_provider->get($classlike_storage->location->file_path);
            } catch (InvalidArgumentException) {
                continue;
            }
            $required_file_storage->required_by_file_paths += [$file_path_lc => $storage->file_path];
        }
        $storage->populated = true;
    }
    private function inherit_constants_from_trait(Class_Like_Storage $storage, Class_Like_Storage $trait_storage): void
    {
        if (!$trait_storage->is_trait) {
            $location = $storage->location ?? $storage->stmt_location;
            if (!$location) {
                return;
            }
            $trait_real_type = 'Class';
            if ($trait_storage->is_enum) {
                $trait_real_type = 'Enum';
            } elseif ($trait_storage->is_interface) {
                $trait_real_type = 'Interface';
            }
            Issue_Buffer::maybe_add(new Undefined_Trait($trait_real_type . ' ' . $trait_storage->name . ' is not a trait', $location), $storage->suppressed_issues);
            return;
        }
        foreach ($trait_storage->constants as $constant_name => $class_constant_storage) {
            $trait_alias_map_cased = array_flip($storage->trait_alias_map_cased);
            if (isset($trait_alias_map_cased[$constant_name])) {
                $aliased_constant_name_lc = strtolower($trait_alias_map_cased[$constant_name]);
                $aliased_constant_name = $trait_alias_map_cased[$constant_name];
            } else {
                $aliased_constant_name_lc = strtolower($constant_name);
                $aliased_constant_name = $constant_name;
            }
            $visibility = $storage->trait_visibility_map[$aliased_constant_name_lc] ?? $class_constant_storage->visibility;
            $final = $storage->trait_final_map[$aliased_constant_name_lc] ?? $class_constant_storage->final;
            $storage->constants[$aliased_constant_name] = new Class_Constant_Storage($class_constant_storage->type, $class_constant_storage->inferred_type, $visibility, $class_constant_storage->location, $class_constant_storage->type_location, $class_constant_storage->stmt_location, $class_constant_storage->deprecated, $final, $class_constant_storage->unresolved_node, $class_constant_storage->attributes, $class_constant_storage->suppressed_issues, $class_constant_storage->description);
        }
    }
    private function inherit_methods_from_parent(Class_Like_Storage $storage, Class_Like_Storage $parent_storage): void
    {
        $fq_class_name = $storage->name;
        $fq_class_name_lc = strtolower($fq_class_name);
        if ($parent_storage->sealed_methods !== null) {
            $storage->sealed_methods = $parent_storage->sealed_methods;
        }
        // register where they appear (can never be in a trait)
        foreach ($parent_storage->appearing_method_ids as $method_name_lc => $appearing_method_id) {
            $aliased_method_names = [$method_name_lc];
            if ($parent_storage->is_trait && $storage->trait_alias_map) {
                $aliased_method_names = [...$aliased_method_names, ...array_keys($storage->trait_alias_map, $method_name_lc, true)];
            }
            foreach ($aliased_method_names as $aliased_method_name) {
                if (isset($storage->appearing_method_ids[$aliased_method_name])) {
                    continue;
                }
                $implemented_method_id = new Method_Identifier($fq_class_name, $aliased_method_name);
                $storage->appearing_method_ids[$aliased_method_name] = $parent_storage->is_trait ? $implemented_method_id : $appearing_method_id;
                $this_method_id = $fq_class_name_lc . '::' . $method_name_lc;
                if (isset($storage->methods[$aliased_method_name])) {
                    $storage->potential_declaring_method_ids[$aliased_method_name] = [$this_method_id => true];
                } else {
                    if (isset($parent_storage->potential_declaring_method_ids[$aliased_method_name])) {
                        $storage->potential_declaring_method_ids[$aliased_method_name] = $parent_storage->potential_declaring_method_ids[$aliased_method_name];
                    }
                    $storage->potential_declaring_method_ids[$aliased_method_name][$this_method_id] = true;
                    $parent_method_id = strtolower($parent_storage->name) . '::' . $method_name_lc;
                    $storage->potential_declaring_method_ids[$aliased_method_name][$parent_method_id] = true;
                }
            }
        }
        // register where they're declared
        foreach ($parent_storage->inheritable_method_ids as $method_name_lc => $declaring_method_id) {
            if ($method_name_lc !== '__construct' || $parent_storage->preserve_constructor_signature) {
                if ($parent_storage->is_trait) {
                    $declaring_class = $declaring_method_id->fq_class_name;
                    $declaring_class_storage = $this->classlike_storage_provider->get($declaring_class);
                    if (isset($declaring_class_storage->methods[$method_name_lc]) && $declaring_class_storage->methods[$method_name_lc]->abstract) {
                        $storage->overridden_method_ids[$method_name_lc][$declaring_method_id->fq_class_name] = $declaring_method_id;
                    }
                } else {
                    $storage->overridden_method_ids[$method_name_lc][$declaring_method_id->fq_class_name] = $declaring_method_id;
                }
                if (isset($parent_storage->overridden_method_ids[$method_name_lc]) && isset($storage->overridden_method_ids[$method_name_lc])) {
                    $storage->overridden_method_ids[$method_name_lc] += $parent_storage->overridden_method_ids[$method_name_lc];
                }
            }
            $aliased_method_names = [$method_name_lc];
            if ($parent_storage->is_trait && $storage->trait_alias_map) {
                $aliased_method_names = [...$aliased_method_names, ...array_keys($storage->trait_alias_map, $method_name_lc, true)];
            }
            foreach ($aliased_method_names as $aliased_method_name) {
                if (isset($storage->declaring_method_ids[$aliased_method_name])) {
                    $implementing_method_id = $storage->declaring_method_ids[$aliased_method_name];
                    $implementing_class_storage = $this->classlike_storage_provider->get($implementing_method_id->fq_class_name);
                    $method = $implementing_class_storage->methods[$implementing_method_id->method_name] ?? $implementing_class_storage->pseudo_methods[$implementing_method_id->method_name] ?? $implementing_class_storage->pseudo_static_methods[$implementing_method_id->method_name];
                    if (!$method->abstract) {
                        continue;
                    }
                    if (!empty($storage->methods[$implementing_method_id->method_name]->abstract)) {
                        continue;
                    }
                }
                $storage->declaring_method_ids[$aliased_method_name] = $declaring_method_id;
                $storage->inheritable_method_ids[$aliased_method_name] = $declaring_method_id;
            }
        }
    }
    private function inherit_properties_from_parent(Class_Like_Storage $storage, Class_Like_Storage $parent_storage): void
    {
        if ($parent_storage->sealed_properties !== null) {
            $storage->sealed_properties = $parent_storage->sealed_properties;
        }
        // register where they appear (can never be in a trait)
        foreach ($parent_storage->appearing_property_ids as $property_name => $appearing_property_id) {
            if (isset($storage->appearing_property_ids[$property_name])) {
                continue;
            }
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            $implemented_property_id = $storage->name . '::$' . $property_name;
            $storage->appearing_property_ids[$property_name] = $parent_storage->is_trait ? $implemented_property_id : $appearing_property_id;
        }
        // register where they're declared
        foreach ($parent_storage->declaring_property_ids as $property_name => $declaring_property_class) {
            if (isset($storage->declaring_property_ids[$property_name])) {
                continue;
            }
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            $storage->declaring_property_ids[$property_name] = $declaring_property_class;
        }
        // register where they're declared
        foreach ($parent_storage->inheritable_property_ids as $property_name => $inheritable_property_id) {
            if (!$parent_storage->is_trait && isset($parent_storage->properties[$property_name]) && $parent_storage->properties[$property_name]->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                continue;
            }
            if (!$parent_storage->is_trait) {
                $storage->overridden_property_ids[$property_name][] = $inheritable_property_id;
            }
            $storage->inheritable_property_ids[$property_name] = $inheritable_property_id;
        }
    }
}