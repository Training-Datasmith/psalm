<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use Php_Parser;
use Psalm\Aliases;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\File_Include_Exception;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\Arguments_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Fetch\Const_Fetch_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Include_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Type;
use Symfony\Component\Filesystem\Path;
use function assert;
use function defined;
use function dirname;
use function explode;
use function in_array;
use function str_contains;
use function strtolower;
use function substr;
use const DIRECTORY_SEPARATOR;
/**
 * @internal
 */
final class Expression_Scanner
{
    public static function scan(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, Php_Parser\Node\Expr $node, ?Function_Like_Storage $functionlike_storage, ?int $skip_if_descendants): void
    {
        if ($node instanceof Php_Parser\Node\Expr\Include_ && !$skip_if_descendants) {
            self::visit_include($codebase, $file_storage, $node, $file_scanner->will_analyze);
        } elseif ($node instanceof Php_Parser\Node\Expr\Yield_ || $node instanceof Php_Parser\Node\Expr\Yield_From) {
            if ($functionlike_storage) {
                $functionlike_storage->has_yield = true;
            }
        } elseif ($node instanceof Php_Parser\Node\Expr\Cast\Object_) {
            $codebase->scanner->queue_class_like_for_scanning('stdClass', false, false);
            $file_storage->referenced_classlikes['stdclass'] = 'stdClass';
        } elseif (($node instanceof Php_Parser\Node\Expr\New_ || $node instanceof Php_Parser\Node\Expr\Instanceof_ || $node instanceof Php_Parser\Node\Expr\Static_Property_Fetch || $node instanceof Php_Parser\Node\Expr\Class_Const_Fetch || $node instanceof Php_Parser\Node\Expr\Static_Call) && $node->class instanceof Php_Parser\Node\Name) {
            $fq_classlike_name = Class_Like_Analyzer::get_fqcln_from_name_object($node->class, $aliases);
            if (!in_array(strtolower($fq_classlike_name), ['self', 'static', 'parent'], true)) {
                $codebase->scanner->queue_class_like_for_scanning($fq_classlike_name, false, !$node instanceof Php_Parser\Node\Expr\Class_Const_Fetch || !$node->name instanceof Php_Parser\Node\Identifier || strtolower($node->name->name) !== 'class');
                $file_storage->referenced_classlikes[strtolower($fq_classlike_name)] = $fq_classlike_name;
            }
        } elseif ($node instanceof Php_Parser\Node\Expr\Func_Call && $node->name instanceof Php_Parser\Node\Name) {
            $function_id = $node->name->to_string();
            if (Internal_Call_Map_Handler::in_call_map($function_id)) {
                self::register_class_map_function_call($codebase, $file_storage, $file_scanner, $aliases, $function_id, $node, $functionlike_storage, $skip_if_descendants);
            }
        }
    }
    private static function register_class_map_function_call(Codebase $codebase, File_Storage $file_storage, File_Scanner $file_scanner, Aliases $aliases, string $function_id, Php_Parser\Node\Expr\Func_Call $node, ?Function_Like_Storage $functionlike_storage, ?int $skip_if_descendants): void
    {
        $callables = Internal_Call_Map_Handler::get_callables_from_call_map($function_id);
        if ($callables) {
            foreach ($callables as $callable) {
                assert($callable->params !== null);
                foreach ($callable->params as $function_param) {
                    if ($function_param->type) {
                        /** @psalm-suppress UnusedMethodCall */
                        $function_param->type->queue_class_likes_for_scanning($codebase, $file_storage);
                    }
                }
                if ($callable->return_type && !$callable->return_type->has_mixed()) {
                    /** @psalm-suppress UnusedMethodCall */
                    $callable->return_type->queue_class_likes_for_scanning($codebase, $file_storage);
                }
            }
        }
        if ($node->is_first_class_callable()) {
            return;
        }
        if ($function_id === 'define') {
            $first_arg_value = isset($node->get_args()[0]) ? $node->get_args()[0]->value : null;
            $second_arg_value = isset($node->get_args()[1]) ? $node->get_args()[1]->value : null;
            if ($first_arg_value && $second_arg_value) {
                $type_provider = new Node_Data_Provider();
                $const_name = Const_Fetch_Analyzer::get_const_name($first_arg_value, $type_provider, $codebase, $aliases);
                if ($const_name !== null) {
                    $const_type = Simple_Type_Inferer::infer($codebase, $type_provider, $second_arg_value, $aliases);
                    // allow docblocks to override the declared value to make constants in stubs configurable
                    $doc_comment = $second_arg_value->get_doc_comment();
                    if ($doc_comment) {
                        try {
                            $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $file_scanner, $aliases);
                            foreach ($var_comments as $var_comment) {
                                if ($var_comment->type) {
                                    $const_type = $var_comment->type;
                                }
                                // only check the first @var comment
                                break;
                            }
                        } catch (Docblock_Parse_Exception) {
                            // do nothing
                        }
                    }
                    if ($const_type === null) {
                        $const_type = Type::get_mixed();
                    }
                    $config = Config::get_instance();
                    if ($functionlike_storage && !$config->hoist_constants) {
                        $functionlike_storage->defined_constants[$const_name] = $const_type;
                    } else {
                        $file_storage->constants[$const_name] = $const_type;
                        $file_storage->declaring_constants[$const_name] = $file_storage->file_path;
                    }
                    if (($codebase->register_stub_files || $codebase->register_autoload_files || $codebase->all_constants_global) && (!defined($const_name) || !$const_type->is_mixed())) {
                        $codebase->add_global_constant_type($const_name, $const_type);
                    }
                }
            }
        }
        $mapping_function_ids = [];
        if ($function_id === 'array_map' && isset($node->get_args()[0]) || in_array($function_id, Arguments_Analyzer::ARRAY_FILTERLIKE, true) && isset($node->get_args()[1])) {
            $node_arg_value = $function_id === 'array_map' ? $node->get_args()[0]->value : $node->get_args()[1]->value;
            if ($node_arg_value instanceof Php_Parser\Node\Scalar\String_ || $node_arg_value instanceof Php_Parser\Node\Expr\Array_ || $node_arg_value instanceof Php_Parser\Node\Expr\Binary_Op\Concat) {
                $mapping_function_ids = Call_Analyzer::get_function_ids_from_callable_arg($file_scanner, $node_arg_value);
            }
            foreach ($mapping_function_ids as $potential_method_id) {
                if (!str_contains($potential_method_id, '::')) {
                    continue;
                }
                [$callable_fqcln] = explode('::', $potential_method_id);
                if (!in_array(strtolower($callable_fqcln), ['self', 'parent', 'static'], true)) {
                    $codebase->scanner->queue_class_like_for_scanning($callable_fqcln);
                }
            }
        }
        if ($function_id === 'func_get_arg' || $function_id === 'func_get_args' || $function_id === 'func_num_args') {
            if ($functionlike_storage) {
                $functionlike_storage->variadic = true;
            }
        }
        if ($function_id === 'is_a' || $function_id === 'is_subclass_of') {
            $second_arg = $node->get_args()[1]->value ?? null;
            if ($second_arg instanceof Php_Parser\Node\Scalar\String_) {
                $codebase->scanner->queue_class_like_for_scanning($second_arg->value);
            }
        }
        if ($function_id === 'class_alias' && !$skip_if_descendants) {
            $first_arg = $node->get_args()[0]->value ?? null;
            $second_arg = $node->get_args()[1]->value ?? null;
            if ($first_arg instanceof Php_Parser\Node\Scalar\String_) {
                $first_arg_value = $first_arg->value;
            } elseif ($first_arg instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $first_arg->class instanceof Php_Parser\Node\Name && $first_arg->name instanceof Php_Parser\Node\Identifier && strtolower($first_arg->name->name) === 'class') {
                /** @var string */
                $first_arg_value = $first_arg->class->get_attribute('resolvedName');
            } else {
                $first_arg_value = null;
            }
            if ($second_arg instanceof Php_Parser\Node\Scalar\String_) {
                $second_arg_value = $second_arg->value;
            } elseif ($second_arg instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $second_arg->class instanceof Php_Parser\Node\Name && $second_arg->name instanceof Php_Parser\Node\Identifier && strtolower($second_arg->name->name) === 'class') {
                /** @var string */
                $second_arg_value = $second_arg->class->get_attribute('resolvedName');
            } else {
                $second_arg_value = null;
            }
            if ($first_arg_value !== null && $second_arg_value !== null) {
                if ($first_arg_value[0] === '\\') {
                    $first_arg_value = substr($first_arg_value, 1);
                }
                if ($second_arg_value[0] === '\\') {
                    $second_arg_value = substr($second_arg_value, 1);
                }
                $codebase->classlikes->add_class_alias($first_arg_value, $second_arg_value);
                $file_storage->classlike_aliases[$second_arg_value] = $first_arg_value;
            }
        }
    }
    public static function visit_include(Codebase $codebase, File_Storage $file_storage, Php_Parser\Node\Expr\Include_ $stmt, bool $scan_deep): void
    {
        $config = Config::get_instance();
        if (!$config->allow_includes) {
            throw new File_Include_Exception('File includes are not allowed per your Psalm config - check the allowFileIncludes flag.');
        }
        if ($stmt->expr instanceof Php_Parser\Node\Scalar\String_) {
            $path_to_file = $stmt->expr->value;
            // attempts to resolve using get_include_path dirs
            $include_path = Include_Analyzer::resolve_include_path($path_to_file, dirname($file_storage->file_path));
            $path_to_file = $include_path ?: $path_to_file;
            if (Path::is_relative($path_to_file)) {
                $path_to_file = $config->base_dir . DIRECTORY_SEPARATOR . $path_to_file;
            }
        } else {
            $path_to_file = Include_Analyzer::get_path_to($stmt->expr, null, null, $file_storage->file_path, $config);
        }
        if ($path_to_file) {
            $path_to_file = Include_Analyzer::normalize_file_path($path_to_file);
            if ($file_storage->file_path === $path_to_file) {
                return;
            }
            if ($codebase->file_exists($path_to_file)) {
                if ($scan_deep) {
                    $codebase->scanner->add_file_to_deep_scan($path_to_file);
                } else {
                    $codebase->scanner->add_file_to_shallow_scan($path_to_file);
                }
                $file_storage->required_file_paths[strtolower($path_to_file)] = $path_to_file;
                return;
            }
        }
    }
}