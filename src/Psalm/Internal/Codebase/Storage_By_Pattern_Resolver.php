<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\Enum_Case_Storage;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_replace;
/**
 * @internal
 */
final class Storage_By_Pattern_Resolver
{
    public const RESOLVE_CONSTANTS = 1;
    public const RESOLVE_ENUMS = 2;
    /**
     * @return array<string,ClassConstantStorage>
     */
    public function resolve_constants(Class_Like_Storage $class_like_storage, string $pattern): array
    {
        $constants = $class_like_storage->constants;
        if (!str_contains($pattern, '*')) {
            if (isset($constants[$pattern])) {
                return [$pattern => $constants[$pattern]];
            }
            return [];
        }
        if ($pattern === '*') {
            return $constants;
        }
        $regex_pattern = sprintf('#^%s$#', str_replace('*', '.*?', $pattern));
        $matched_constants = [];
        foreach ($constants as $constant => $class_constant_storage) {
            if (preg_match($regex_pattern, $constant) === 0) {
                continue;
            }
            $matched_constants[$constant] = $class_constant_storage;
        }
        return $matched_constants;
    }
    /**
     * @return array<string,EnumCaseStorage>
     */
    public function resolve_enums(Class_Like_Storage $class_like_storage, string $pattern): array
    {
        $enum_cases = $class_like_storage->enum_cases;
        if (!str_contains($pattern, '*')) {
            if (isset($enum_cases[$pattern])) {
                return [$pattern => $enum_cases[$pattern]];
            }
            return [];
        }
        if ($pattern === '*') {
            return $enum_cases;
        }
        $regex_pattern = sprintf('#^%s$#', str_replace('*', '.*?', $pattern));
        $matched_enums = [];
        foreach ($enum_cases as $enum_case_name => $enum_case_storage) {
            if (preg_match($regex_pattern, $enum_case_name) === 0) {
                continue;
            }
            $matched_enums[$enum_case_name] = $enum_case_storage;
        }
        return $matched_enums;
    }
}