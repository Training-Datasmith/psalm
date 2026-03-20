<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Provider\Property_Existence_Provider;
use Psalm\Internal\Provider\Property_Type_Provider;
use Psalm\Internal\Provider\Property_Visibility_Provider;
use Psalm\Statements_Source;
use Psalm\Storage\Property_Storage;
use Psalm\Type\Union;
use UnexpectedValueException;
use function explode;
use function ltrim;
use function strtolower;
/**
 * @internal
 *
 * Handles information about class properties
 */
final class Properties
{
    public bool $collect_locations = false;
    public Property_Existence_Provider $property_existence_provider;
    public Property_Type_Provider $property_type_provider;
    public Property_Visibility_Provider $property_visibility_provider;
    public function __construct(private readonly Class_Like_Storage_Provider $classlike_storage_provider, public File_Reference_Provider $file_reference_provider, private readonly Class_Likes $classlikes)
    {
        $this->property_existence_provider = new Property_Existence_Provider();
        $this->property_visibility_provider = new Property_Visibility_Provider();
        $this->property_type_provider = new Property_Type_Provider();
    }
    /**
     * Whether or not a given property exists
     */
    public function property_exists(string $property_id, bool $read_mode, ?Statements_Source $source = null, ?Context $context = null, ?Code_Location $code_location = null): bool
    {
        // remove leading backslash if it exists
        $property_id = ltrim($property_id, '\\');
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        $fq_class_name_lc = strtolower($fq_class_name);
        if ($this->property_existence_provider->has($fq_class_name)) {
            $property_exists = $this->property_existence_provider->does_property_exist($fq_class_name, $property_name, $read_mode, $source, $context, $code_location);
            if ($property_exists !== null) {
                return $property_exists;
            }
        }
        $class_storage = $this->classlikes->get_storage_for($fq_class_name);
        if (!$class_storage) {
            return false;
        }
        if ($source && $context && $context->self !== $fq_class_name && !$context->collect_initializations && !$context->collect_mutations) {
            if ($context->calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class($context->calling_method_id, $fq_class_name_lc);
            } else {
                $this->file_reference_provider->add_non_method_reference_to_class($source->get_file_path(), $fq_class_name_lc);
            }
        }
        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = strtolower($class_storage->declaring_property_ids[$property_name]);
            if ($context && $context->calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class_member($context->calling_method_id, $declaring_property_class . '::$' . $property_name, false);
                if ($read_mode) {
                    $this->file_reference_provider->add_method_reference_to_class_property($context->calling_method_id, $declaring_property_class . '::$' . $property_name);
                }
            } elseif ($source) {
                $this->file_reference_provider->add_file_reference_to_class_member($source->get_file_path(), $declaring_property_class . '::$' . $property_name, false);
                if ($read_mode) {
                    $this->file_reference_provider->add_file_reference_to_class_property($source->get_file_path(), $declaring_property_class . '::$' . $property_name);
                }
            }
            if ($this->collect_locations && $code_location) {
                $this->file_reference_provider->add_calling_location_for_class_property($code_location, $declaring_property_class . '::$' . $property_name);
            }
            return true;
        }
        if ($context && $context->calling_method_id) {
            $this->file_reference_provider->add_method_reference_to_missing_class_member($context->calling_method_id, $fq_class_name_lc . '::$' . $property_name);
        } elseif ($source) {
            $this->file_reference_provider->add_file_reference_to_missing_class_member($source->get_file_path(), $fq_class_name_lc . '::$' . $property_name);
        }
        return false;
    }
    public function get_declaring_class_for_property(string $property_id, bool $read_mode, ?Statements_Source $source = null): ?string
    {
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        if ($this->property_existence_provider->has($fq_class_name)) {
            if ($this->property_existence_provider->does_property_exist($fq_class_name, $property_name, $read_mode, $source)) {
                return $fq_class_name;
            }
        }
        $class_storage = $this->classlikes->get_storage_for($fq_class_name);
        if ($class_storage && isset($class_storage->declaring_property_ids[$property_name])) {
            return $class_storage->declaring_property_ids[$property_name];
        }
        return null;
    }
    /**
     * Get the class this property appears in (vs is declared in, which could give a trait)
     */
    public function get_appearing_class_for_property(string $property_id, bool $read_mode, ?Statements_Source $source = null): ?string
    {
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        if ($this->property_existence_provider->has($fq_class_name)) {
            if ($this->property_existence_provider->does_property_exist($fq_class_name, $property_name, $read_mode, $source)) {
                return $fq_class_name;
            }
        }
        $class_storage = $this->classlikes->get_storage_for($fq_class_name);
        if ($class_storage && isset($class_storage->appearing_property_ids[$property_name])) {
            $appearing_property_id = $class_storage->appearing_property_ids[$property_name];
            return explode('::$', $appearing_property_id)[0];
        }
        return null;
    }
    public function get_storage(string $property_id): Property_Storage
    {
        // remove leading backslash if it exists
        $property_id = ltrim($property_id, '\\');
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);
            if (isset($declaring_class_storage->properties[$property_name])) {
                return $declaring_class_storage->properties[$property_name];
            }
        }
        throw new UnexpectedValueException('Property ' . $property_id . ' should exist');
    }
    public function has_storage(string $property_id): bool
    {
        // remove leading backslash if it exists
        $property_id = ltrim($property_id, '\\');
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        if (isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);
            return isset($declaring_class_storage->properties[$property_name]);
        }
        return false;
    }
    public function get_property_type(string $property_id, bool $property_set, ?Statements_Source $source = null, ?Context $context = null): ?Union
    {
        // remove leading backslash if it exists
        $property_id = ltrim($property_id, '\\');
        [$fq_class_name, $property_name] = explode('::$', $property_id);
        if ($this->property_type_provider->has($fq_class_name)) {
            $property_type = $this->property_type_provider->get_property_type($fq_class_name, $property_name, !$property_set, $source, $context);
            if ($property_type !== null) {
                return $property_type;
            }
        }
        $class_storage = $this->classlikes->get_storage_for($fq_class_name);
        if ($class_storage && isset($class_storage->declaring_property_ids[$property_name])) {
            $declaring_property_class = $class_storage->declaring_property_ids[$property_name];
            $declaring_class_storage = $this->classlike_storage_provider->get($declaring_property_class);
            if (isset($declaring_class_storage->properties[$property_name])) {
                $storage = $declaring_class_storage->properties[$property_name];
            } else {
                throw new UnexpectedValueException('Property ' . $property_id . ' should exist');
            }
        } else {
            throw new UnexpectedValueException('Property ' . $property_id . ' should exist');
        }
        if ($storage->type) {
            if ($property_set) {
                if (isset($class_storage->pseudo_property_set_types['$' . $property_name])) {
                    return $class_storage->pseudo_property_set_types['$' . $property_name];
                }
            } else if (isset($class_storage->pseudo_property_get_types['$' . $property_name])) {
                return $class_storage->pseudo_property_get_types['$' . $property_name];
            }
            return $storage->type;
        }
        if (!isset($class_storage->overridden_property_ids[$property_name])) {
            return null;
        }
        foreach ($class_storage->overridden_property_ids[$property_name] as $overridden_property_id) {
            $overridden_storage = $this->get_storage($overridden_property_id);
            if ($overridden_storage->type) {
                return $overridden_storage->type;
            }
        }
        return null;
    }
}