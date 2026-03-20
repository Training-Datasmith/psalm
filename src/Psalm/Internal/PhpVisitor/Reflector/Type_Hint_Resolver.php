<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Php_Parser;
use Php_Parser\Node\Identifier;
use Php_Parser\Node\Intersection_Type;
use Php_Parser\Node\Name;
use Php_Parser\Node\Nullable_Type;
use Php_Parser\Node\Union_Type;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Issue\ParseError;
use Psalm\Issue_Buffer;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use UnexpectedValueException;
use function strtolower;
/**
 * @internal
 */
final class Type_Hint_Resolver
{
    /**
     * @param Identifier|IntersectionType|Name|NullableType|UnionType $hint
     */
    public static function resolve(Php_Parser\Node_Abstract $hint, Code_Location $code_location, Codebase $codebase, File_Storage $file_storage, ?Class_Like_Storage $classlike_storage, Aliases $aliases, int $analysis_php_version_id): Union
    {
        if ($hint instanceof Php_Parser\Node\Union_Type) {
            $type = null;
            if (!$hint->types) {
                throw new UnexpectedValueException('Union type should not be empty');
            }
            if ($analysis_php_version_id < 80000) {
                Issue_Buffer::maybe_add(new ParseError('Union types are not supported in PHP < 8', $code_location));
            }
            foreach ($hint->types as $atomic_typehint) {
                $resolved_type = self::resolve($atomic_typehint, $code_location, $codebase, $file_storage, $classlike_storage, $aliases, $analysis_php_version_id);
                $type = Type::combine_union_types($resolved_type, $type);
            }
            return $type;
        }
        if ($hint instanceof Php_Parser\Node\Intersection_Type) {
            $type = null;
            if (!$hint->types) {
                throw new UnexpectedValueException('Intersection type should not be empty');
            }
            if ($analysis_php_version_id < 80100) {
                Issue_Buffer::maybe_add(new ParseError('Intersection types are not supported in PHP < 8.1', $code_location));
            }
            foreach ($hint->types as $atomic_typehint) {
                $resolved_type = self::resolve($atomic_typehint, $code_location, $codebase, $file_storage, $classlike_storage, $aliases, $analysis_php_version_id);
                if ($resolved_type->has_scalar_type()) {
                    Issue_Buffer::maybe_add(new ParseError('Intersection types cannot contain scalar types', $code_location));
                }
                $type = Type::intersect_union_types($resolved_type, $type, $codebase);
            }
            if ($type === null) {
                return Type::get_never();
            }
            return $type;
        }
        $is_nullable = false;
        if ($hint instanceof Php_Parser\Node\Nullable_Type) {
            $is_nullable = true;
            $hint = $hint->type;
        }
        $type_string = null;
        if ($hint instanceof Php_Parser\Node\Identifier) {
            $fq_type_string = $hint->name;
        } elseif ($hint instanceof Php_Parser\Node\Name\Fully_Qualified) {
            $fq_type_string = (string) $hint;
            $codebase->scanner->queue_class_like_for_scanning($fq_type_string);
            $file_storage->referenced_classlikes[strtolower($fq_type_string)] = $fq_type_string;
        } else {
            $lower_hint = strtolower($hint->get_first());
            if ($classlike_storage && ($lower_hint === 'self' || $lower_hint === 'static') && !$classlike_storage->is_trait) {
                $fq_type_string = $classlike_storage->name;
                if ($lower_hint === 'static') {
                    $fq_type_string .= '&static';
                }
            } else {
                $type_string = $hint->to_string();
                $fq_type_string = Class_Like_Analyzer::get_fqcln_from_name_object($hint, $aliases);
                $codebase->scanner->queue_class_like_for_scanning($fq_type_string);
                $file_storage->referenced_classlikes[strtolower($fq_type_string)] = $fq_type_string;
            }
        }
        $type = Type::parse_string($fq_type_string, $analysis_php_version_id, []);
        if ($type_string) {
            $atomic_type = $type->get_single_atomic();
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $atomic_type->text = $type_string;
        }
        if ($is_nullable) {
            return $type->get_builder()->add_type(new T_Null())->freeze();
        }
        return $type;
    }
}