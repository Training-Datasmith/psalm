<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Php_Parser;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Storage\Attribute_Arg;
use Psalm\Storage\Attribute_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Type;
use function strtolower;
/**
 * @internal
 */
final class Attribute_Resolver
{
    public static function resolve(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, Php_Parser\Node\Attribute $stmt, ?string $fq_classlike_name): Attribute_Storage
    {
        if ($stmt->name instanceof Php_Parser\Node\Name\Fully_Qualified) {
            $fq_type_string = (string) $stmt->name;
        } else {
            $fq_type_string = Class_Like_Analyzer::get_fqcln_from_name_object($stmt->name, $aliases);
        }
        $codebase->scanner->queue_class_like_for_scanning($fq_type_string);
        $file_storage->referenced_classlikes[strtolower($fq_type_string)] = $fq_type_string;
        $args = [];
        foreach ($stmt->args as $arg_node) {
            $key = $arg_node->name->name ?? null;
            $const_type = Simple_Type_Inferer::infer($codebase, new Node_Data_Provider(), $arg_node->value, $aliases, null, [], $fq_classlike_name);
            if (!$const_type) {
                $const_type = Expression_Resolver::get_unresolved_class_const_expr($arg_node->value, $aliases, $fq_classlike_name);
            }
            if (!$const_type) {
                $const_type = Type::get_mixed();
            }
            $args[] = new Attribute_Arg($key, $const_type, new Code_Location($file_scanner, $arg_node->value));
        }
        return new Attribute_Storage($fq_type_string, $args, new Code_Location($file_scanner, $stmt), new Code_Location($file_scanner, $stmt->name));
    }
}