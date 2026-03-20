<?php

declare (strict_types=1);
namespace Psalm\Internal\Stubs\Generator;

use Psalm\Codebase;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Storage_Provider;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Atomic\T_False;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_True;
use Psalm\Type\Atomic\Scalar;
use Php_Parser;
use Psalm\Internal\Scanner\Parsed_Docblock;
use Psalm\Node\Expr\Virtual_Array;
use Psalm\Node\Virtual_Array_Item;
use Psalm\Node\Expr\Virtual_Class_Const_Fetch;
use Psalm\Node\Expr\Virtual_Const_Fetch;
use Psalm\Node\Expr\Virtual_Variable;
use Psalm\Node\Name\Virtual_Fully_Qualified;
use Psalm\Node\Scalar\Virtual_Float;
use Psalm\Node\Scalar\Virtual_Int;
use Psalm\Node\Scalar\Virtual_String;
use Psalm\Node\Stmt\Virtual_Function;
use Psalm\Node\Stmt\Virtual_Namespace;
use Psalm\Node\Virtual_Const;
use Psalm\Node\Stmt\Virtual_Const as StmtVirtualConst_;
use Psalm\Node\Virtual_Identifier;
use Psalm\Node\Virtual_Name;
use Psalm\Node\Virtual_Nullable_Type;
use Psalm\Node\Virtual_Param;
use Psalm\Type;
use Psalm\Type\Union;
use UnexpectedValueException;
use function dirname;
use function is_int;
use function rtrim;
use function strpos;
/**
 * @internal
 */
final class Stubs_Generator
{
    public static function get_all(Codebase $codebase, Class_Like_Storage_Provider $class_provider, File_Storage_Provider $file_provider): string
    {
        $namespaced_nodes = [];
        $psalm_base = dirname(__DIR__, 5);
        foreach ($class_provider->get_all() as $storage) {
            if (str_starts_with($storage->name, 'Psalm\\')) {
                continue;
            }
            if ($storage->location && str_starts_with($storage->location->file_path, $psalm_base)) {
                continue;
            }
            if ($storage->stubbed) {
                continue;
            }
            $name_parts = explode('\\', $storage->name);
            $classlike_name = array_pop($name_parts);
            $namespace_name = implode('\\', $name_parts);
            if (!isset($namespaced_nodes[$namespace_name])) {
                $namespaced_nodes[$namespace_name] = [];
            }
            $namespaced_nodes[$namespace_name][$classlike_name] = Class_Like_Stub_Generator::get_class_like_node($codebase, $storage, $classlike_name);
        }
        $all_function_names = [];
        foreach ($codebase->functions->get_all_stubbed_functions() as $function_storage) {
            if ($function_storage->location && str_starts_with($function_storage->location->file_path, $psalm_base)) {
                continue;
            }
            if (!$function_storage->cased_name) {
                throw new UnexpectedValueException('very bad');
            }
            $fq_name = $function_storage->cased_name;
            $all_function_names[$fq_name] = true;
            $name_parts = explode('\\', $fq_name);
            $function_name = array_pop($name_parts);
            $namespace_name = implode('\\', $name_parts);
            $namespaced_nodes[$namespace_name][$fq_name] = self::get_function_node($function_storage, $function_name, $namespace_name);
        }
        foreach ($codebase->get_all_stubbed_constants() as $fq_name => $type) {
            if ($type->is_mixed()) {
                continue;
            }
            $name_parts = explode('\\', $fq_name);
            $constant_name = array_pop($name_parts);
            $namespace_name = implode('\\', $name_parts);
            $namespaced_nodes[$namespace_name][$fq_name] = new Stmt_Virtual_Const_([new Virtual_Const($constant_name, self::get_expression_from_type($type))]);
        }
        foreach ($file_provider->get_all() as $file_storage) {
            if (str_starts_with($file_storage->file_path, $psalm_base)) {
                continue;
            }
            foreach ($file_storage->functions as $function_storage) {
                if (!$function_storage->cased_name) {
                    continue;
                }
                $fq_name = $function_storage->cased_name;
                if (isset($all_function_names[$fq_name])) {
                    continue;
                }
                $all_function_names[$fq_name] = true;
                $name_parts = explode('\\', $fq_name);
                $function_name = array_pop($name_parts);
                $namespace_name = implode('\\', $name_parts);
                $namespaced_nodes[$namespace_name][$fq_name] = self::get_function_node($function_storage, $function_name, $namespace_name);
            }
            foreach ($file_storage->constants as $fq_name => $type) {
                if ($type->is_mixed()) {
                    continue;
                }
                $name_parts = explode('\\', $fq_name);
                $constant_name = array_pop($name_parts);
                $namespace_name = implode('\\', $name_parts);
                $namespaced_nodes[$namespace_name][$fq_name] = new Stmt_Virtual_Const_([new Virtual_Const($constant_name, self::get_expression_from_type($type))]);
            }
        }
        ksort($namespaced_nodes);
        $namespace_stmts = [];
        foreach ($namespaced_nodes as $namespace_name => $stmts) {
            ksort($stmts);
            $namespace_stmts[] = new Virtual_Namespace($namespace_name ? new Virtual_Name($namespace_name) : null, array_values($stmts), ['kind' => Php_Parser\Node\Stmt\Namespace_::KIND_BRACED]);
        }
        $pretty_printer = new Php_Parser\Pretty_Printer\Standard();
        return $pretty_printer->pretty_print_file($namespace_stmts);
    }
    private static function get_function_node(Function_Like_Storage $function_storage, string $function_name, string $namespace_name): \Psalm\Node\Stmt\Virtual_Function
    {
        $docblock = new Parsed_Docblock('', []);
        foreach ($function_storage->template_types ?: [] as $template_name => $map) {
            $type = array_values($map)[0];
            $docblock->tags['template'][] = $template_name . ' as ' . $type->to_namespaced_string($namespace_name, [], null, false);
        }
        foreach ($function_storage->params as $param) {
            if ($param->type && $param->type !== $param->signature_type) {
                $docblock->tags['param'][] = $param->type->to_namespaced_string($namespace_name, [], null, false) . ' $' . $param->name;
            }
        }
        if ($function_storage->return_type && $function_storage->signature_return_type !== $function_storage->return_type) {
            $docblock->tags['return'][] = $function_storage->return_type->to_namespaced_string($namespace_name, [], null, false);
        }
        foreach ($function_storage->throws ?: [] as $exception_name => $_) {
            $docblock->tags['throws'][] = Type::get_string_from_fqcln($exception_name, $namespace_name, [], null, false);
        }
        return new Virtual_Function($function_name, ['params' => self::get_function_param_nodes($function_storage), 'returnType' => $function_storage->signature_return_type ? self::get_parser_type_from_psalm_type($function_storage->signature_return_type) : null, 'stmts' => []], ['comments' => $docblock->tags ? [new Php_Parser\Comment\Doc(rtrim($docblock->render('        ')))] : []]);
    }
    /**
     * @return list<PhpParser\Node\Param>
     */
    public static function get_function_param_nodes(Function_Like_Storage $method_storage): array
    {
        $param_nodes = [];
        foreach ($method_storage->params as $param) {
            $param_nodes[] = new Virtual_Param(new Virtual_Variable($param->name), $param->default_type instanceof Union ? self::get_expression_from_type($param->default_type) : null, $param->signature_type ? self::get_parser_type_from_psalm_type($param->signature_type) : null, $param->by_ref, $param->is_variadic);
        }
        return $param_nodes;
    }
    /**
     * @return PhpParser\Node\Identifier|PhpParser\Node\Name\FullyQualified|PhpParser\Node\NullableType|null
     */
    public static function get_parser_type_from_psalm_type(Union $type): ?Php_Parser\Node_Abstract
    {
        $nullable = $type->is_nullable();
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Null) {
                continue;
            }
            if ($atomic_type instanceof Scalar || $atomic_type instanceof T_Object || $atomic_type instanceof T_Array || $atomic_type instanceof T_Iterable) {
                $identifier_string = $atomic_type->to_php_string(null, [], null, 80000);
                if ($identifier_string === null) {
                    throw new UnexpectedValueException($atomic_type->get_id() . ' could not be converted to an identifier');
                }
                $identifier = new Virtual_Identifier($identifier_string);
                if ($nullable) {
                    return new Virtual_Nullable_Type($identifier);
                }
                return $identifier;
            }
            if ($atomic_type instanceof T_Named_Object) {
                $name_node = new Virtual_Fully_Qualified($atomic_type->value);
                if ($nullable) {
                    return new Virtual_Nullable_Type($name_node);
                }
                return $name_node;
            }
        }
        return null;
    }
    public static function get_expression_from_type(Union $type): Php_Parser\Node\Expr
    {
        foreach ($type->get_atomic_types() as $atomic_type) {
            if ($atomic_type instanceof T_Literal_Class_String) {
                return new Virtual_Class_Const_Fetch(new Virtual_Name('\\' . $atomic_type->value), new Virtual_Identifier('class'));
            }
            if ($atomic_type instanceof T_Literal_String) {
                return new Virtual_String($atomic_type->value);
            }
            if ($atomic_type instanceof T_Literal_Int) {
                return new Virtual_Int($atomic_type->value);
            }
            if ($atomic_type instanceof T_Literal_Float) {
                return new Virtual_Float($atomic_type->value);
            }
            if ($atomic_type instanceof T_False) {
                return new Virtual_Const_Fetch(new Virtual_Name('false'));
            }
            if ($atomic_type instanceof T_True) {
                return new Virtual_Const_Fetch(new Virtual_Name('true'));
            }
            if ($atomic_type instanceof T_Null) {
                return new Virtual_Const_Fetch(new Virtual_Name('null'));
            }
            if ($atomic_type instanceof T_Array) {
                return new Virtual_Array([]);
            }
            if ($atomic_type instanceof T_Keyed_Array) {
                $new_items = [];
                foreach ($atomic_type->properties as $property_name => $property_type) {
                    if ($atomic_type->is_list) {
                        $key_type = null;
                    } elseif (is_int($property_name)) {
                        $key_type = new Virtual_Int($property_name);
                    } else {
                        $key_type = new Virtual_String($property_name);
                    }
                    $new_items[] = new Virtual_Array_Item(self::get_expression_from_type($property_type), $key_type);
                }
                return new Virtual_Array($new_items);
            }
            if ($atomic_type instanceof T_Enum_Case) {
                return new Virtual_Class_Const_Fetch(new Virtual_Name('\\' . $atomic_type->value), new Virtual_Identifier($atomic_type->case_name));
            }
        }
        return new Virtual_String('Psalm could not infer this type');
    }
}