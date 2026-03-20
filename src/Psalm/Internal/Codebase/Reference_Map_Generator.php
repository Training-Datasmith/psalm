<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Internal\Provider\Class_Like_Storage_Provider;
/**
 * @internal
 */
final class Reference_Map_Generator
{
    /**
     * @return array<string, string>
     */
    public static function get_reference_map(Class_Like_Storage_Provider $classlike_storage_provider, array $expected_references): array
    {
        $reference_dictionary = [];
        foreach ($classlike_storage_provider->get_all() as $storage) {
            if (!$storage->location) {
                continue;
            }
            $fq_classlike_name = $storage->name;
            if (isset($expected_references[$fq_classlike_name])) {
                $reference_dictionary[$fq_classlike_name] = $storage->location->file_name . ':' . $storage->location->get_line_number() . ':' . $storage->location->get_column();
            }
            foreach ($storage->methods as $method_name => $method_storage) {
                if (!$method_storage->location) {
                    continue;
                }
                if (isset($expected_references[$fq_classlike_name . '::' . $method_name . '()'])) {
                    $reference_dictionary[$fq_classlike_name . '::' . $method_name . '()'] = $method_storage->location->file_name . ':' . $method_storage->location->get_line_number() . ':' . $method_storage->location->get_column();
                }
            }
            foreach ($storage->properties as $property_name => $property_storage) {
                if (!$property_storage->location) {
                    continue;
                }
                if (isset($expected_references[$fq_classlike_name . '::$' . $property_name])) {
                    $reference_dictionary[$fq_classlike_name . '::$' . $property_name] = $property_storage->location->file_name . ':' . $property_storage->location->get_line_number() . ':' . $property_storage->location->get_column();
                }
            }
        }
        return $reference_dictionary;
    }
}