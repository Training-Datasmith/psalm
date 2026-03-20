<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use AssertionError;
use Php_Parser;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Code_Location\Docblock_Type_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Invalid_Method_Override_Exception;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Internal\Scanner\Function_Docblock_Comment;
use Psalm\Internal\Type\Comparator\Union_Type_Comparator;
use Psalm\Internal\Type\Type_Alias;
use Psalm\Internal\Type\Type_Parser;
use Psalm\Internal\Type\Type_Tokenizer;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Possibly_Invalid_Docblock_Tag;
use Psalm\Storage\Assertion;
use Psalm\Storage\Assertion\Empty_;
use Psalm\Storage\Assertion\Falsy;
use Psalm\Storage\Assertion\Is_Identical;
use Psalm\Storage\Assertion\Is_Loosely_Equal;
use Psalm\Storage\Assertion\Is_Not_Identical;
use Psalm\Storage\Assertion\Is_Not_Loosely_Equal;
use Psalm\Storage\Assertion\Is_Not_Type;
use Psalm\Storage\Assertion\Is_Type;
use Psalm\Storage\Assertion\Non_Empty;
use Psalm\Storage\Assertion\Truthy;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Possibilities;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Taint_Kind_Group;
use Psalm\Type\Union;
use function array_any;
use function array_filter;
use function array_merge;
use function array_search;
use function array_splice;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function in_array;
use function preg_last_error_msg;
use function preg_match;
use function preg_replace;
use function preg_split;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_replace;
use function trim;
/**
 * @internal
 */
final class Function_Like_Docblock_Scanner
{
    /**
     * @param array<string, non-empty-array<string, Union>> $existing_function_template_types
     * @param array<string, TypeAlias> $type_aliases
     */
    public static function add_docblock_info(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, array $type_aliases, ?Class_Like_Storage $classlike_storage, array $existing_function_template_types, Function_Like_Storage $storage, Php_Parser\Node\Function_Like $stmt, Function_Docblock_Comment $docblock_info, bool $is_functionlike_override, bool $fake_method, string $cased_function_id): void
    {
        self::handle_unexpected_tags($docblock_info, $storage, $stmt, $file_scanner, $cased_function_id);
        $config = Config::get_instance();
        if ($docblock_info->mutation_free) {
            $storage->mutation_free = true;
            if ($storage instanceof Method_Storage) {
                $storage->external_mutation_free = true;
                $storage->mutation_free_inferred = false;
            }
        }
        if ($storage instanceof Method_Storage && $docblock_info->external_mutation_free) {
            $storage->external_mutation_free = true;
        }
        if ($docblock_info->deprecated) {
            $storage->deprecated = true;
        }
        if (count($docblock_info->psalm_internal) !== 0) {
            $storage->internal = $docblock_info->psalm_internal;
        } elseif ($docblock_info->internal && $aliases->namespace) {
            $storage->internal = [Namespace_Analyzer::get_name_space_root($aliases->namespace)];
        }
        if (($storage->internal || $classlike_storage && $classlike_storage->internal) && !$config->allow_internal_named_arg_calls) {
            $storage->allow_named_arg_calls = false;
        } elseif ($docblock_info->no_named_args) {
            $storage->allow_named_arg_calls = false;
        }
        if ($docblock_info->variadic) {
            $storage->variadic = true;
        }
        if ($docblock_info->pure) {
            $storage->pure = true;
            $storage->specialize_call = true;
            $storage->mutation_free = true;
            if ($storage instanceof Method_Storage) {
                $storage->external_mutation_free = true;
            }
        }
        if ($docblock_info->specialize_call) {
            $storage->specialize_call = true;
        }
        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_nullable_return && $storage->return_type && ($codebase->config->ignore_internal_nullable_issues || !in_array($file_storage->file_path, $codebase->config->internal_stubs))) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_nullable_issues = true;
        }
        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_falsable_return && $storage->return_type && ($codebase->config->ignore_internal_falsable_issues || !in_array($file_storage->file_path, $codebase->config->internal_stubs))) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_falsable_issues = true;
        }
        if ($docblock_info->stub_override && !$is_functionlike_override) {
            throw new Invalid_Method_Override_Exception('Method ' . $cased_function_id . ' is marked as stub override,' . ' but no original counterpart found');
        }
        $storage->suppressed_issues = $docblock_info->suppressed_issues;
        foreach ($docblock_info->throws as [$throw, $offset, $line]) {
            $throw_location = new Docblock_Type_Location($file_scanner, $offset, $offset + strlen($throw), $line);
            foreach (explode('|', $throw) as $throw_class) {
                $throw_class = trim($throw_class);
                if ($throw_class === '') {
                    continue;
                }
                if ($throw_class !== 'self' && $throw_class !== 'static' && $throw_class !== 'parent') {
                    $exception_fqcln = Type::get_fqcln_from_string($throw_class, $aliases);
                } else {
                    $exception_fqcln = $throw_class;
                }
                $codebase->scanner->queue_class_like_for_scanning($exception_fqcln);
                $file_storage->referenced_classlikes[strtolower($exception_fqcln)] = $exception_fqcln;
                $storage->throws[$exception_fqcln] = true;
                $storage->throw_locations[$exception_fqcln] = $throw_location;
            }
        }
        if (!$config->use_docblock_types) {
            return;
        }
        if ($storage instanceof Method_Storage && $docblock_info->inheritdoc) {
            $storage->inheritdoc = true;
        }
        $template_types = $classlike_storage && $classlike_storage->template_types ? $classlike_storage->template_types : null;
        $function_template_types = $existing_function_template_types;
        $class_template_types = $classlike_storage ? $classlike_storage->template_types ?: [] : [];
        if ($docblock_info->templates) {
            $function_template_types = self::handle_templates($storage, $docblock_info, $aliases, $template_types, $type_aliases, $file_scanner, $stmt, $cased_function_id);
        }
        self::handle_assertions($docblock_info, $storage, $codebase, $file_scanner, $file_storage, $aliases, $stmt, $class_template_types, $function_template_types, $type_aliases, $classlike_storage);
        foreach ($docblock_info->globals as $global) {
            try {
                $storage->global_types[$global['name']] = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($global['type'], $aliases, null, $type_aliases));
            } catch (Type_Parse_Tree_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($file_scanner, $stmt, null, true));
                continue;
            }
        }
        if ($docblock_info->params) {
            self::improve_params_from_docblock($codebase, $file_scanner, $file_storage, $aliases, $type_aliases, $classlike_storage, $storage, $function_template_types, $class_template_types, $docblock_info->params, $stmt, $fake_method, $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null);
        }
        if ($storage instanceof Method_Storage) {
            $storage->has_docblock_param_types = array_any($storage->params, static fn(Function_Like_Parameter $p): bool => $p->type !== null && $p->has_docblock_type);
        }
        foreach ($docblock_info->params_out as $docblock_param_out) {
            self::handle_param_out($docblock_param_out, $aliases, $function_template_types, $class_template_types, $type_aliases, $cased_function_id, $file_scanner, $stmt, $storage, $codebase, $file_storage);
        }
        if ($docblock_info->self_out && $storage instanceof Method_Storage) {
            $out_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_info->self_out['type'], $aliases, $function_template_types + $class_template_types, $type_aliases, $classlike_storage ? $classlike_storage->name : null), null, $function_template_types + $class_template_types, $type_aliases);
            $storage->self_out_type = $out_type;
        }
        if ($docblock_info->if_this_is && $storage instanceof Method_Storage) {
            $out_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_info->if_this_is['type'], $aliases, $function_template_types + $class_template_types, $type_aliases, $classlike_storage ? $classlike_storage->name : null), null, $function_template_types + $class_template_types, $type_aliases);
            $storage->if_this_is_type = $out_type;
        }
        foreach ($docblock_info->taint_sink_params as $taint_sink_param) {
            $param_name = substr($taint_sink_param['name'], 1);
            foreach ($storage->params as $param_storage) {
                if ($param_storage->name === $param_name) {
                    $param_storage->sinks[] = $taint_sink_param['taint'];
                }
            }
        }
        $docblock_info->taint_source_types = array_values(array_unique($docblock_info->taint_source_types));
        // expand 'input' group to all items, e.g. `['other', 'input']` -> `['other', 'html', 'sql', 'shell', ...]`
        $input_index = array_search(Taint_Kind_Group::GROUP_INPUT, $docblock_info->taint_source_types, true);
        if ($input_index !== false) {
            array_splice($docblock_info->taint_source_types, $input_index, 1, Taint_Kind_Group::ALL_INPUT);
        }
        // merge taints from doc block to storage, enforce uniqueness and having consecutive index keys
        $storage->taint_source_types = array_merge($storage->taint_source_types, $docblock_info->taint_source_types);
        $storage->taint_source_types = array_values(array_unique($storage->taint_source_types));
        $storage->added_taints = $docblock_info->added_taints;
        foreach ($docblock_info->removed_taints as $removed_taint) {
            if ($removed_taint[0] === '(') {
                self::handle_removed_taint($codebase, $stmt, $aliases, $removed_taint, $function_template_types, $class_template_types, $type_aliases, $storage, $classlike_storage, $cased_function_id, $file_storage, $file_scanner);
            } else {
                $storage->removed_taints[] = $removed_taint;
            }
        }
        self::handle_taint_flow($docblock_info, $storage);
        foreach ($docblock_info->assert_untainted_params as $untainted_assert_param) {
            $param_name = substr($untainted_assert_param['name'], 1);
            foreach ($storage->params as $param_storage) {
                if ($param_storage->name === $param_name) {
                    $param_storage->assert_untainted = true;
                }
            }
        }
        if ($docblock_info->return_type !== null) {
            self::handle_return($codebase, $docblock_info, $docblock_info->return_type, $fake_method, $file_scanner, $storage, $stmt, $aliases, $function_template_types, $class_template_types, $type_aliases, $classlike_storage, $cased_function_id, $file_storage);
        }
        if ($docblock_info->description) {
            $storage->description = $docblock_info->description;
        }
        $storage->public_api = $docblock_info->public_api;
    }
    /**
     * @param  array<string, array<string, Union>> $template_types
     * @param  array<string, TypeAlias>|null   $type_aliases
     * @param  array<string, array<string, Union>> $function_template_types
     * @return array{
     *     array<int, array{0: string, 1: int, 2?: string}>,
     *     array<string, array<string, Union>>
     * }
     */
    private static function get_conditional_sanitized_type_tokens(string $docblock_return_type, Aliases $aliases, array $template_types, ?array $type_aliases, Function_Like_Storage $storage, ?Class_Like_Storage $classlike_storage, string $cased_function_id, array $function_template_types): array
    {
        $fixed_type_tokens = Type_Tokenizer::get_fully_qualified_tokens($docblock_return_type, $aliases, $template_types, $type_aliases, $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null);
        $param_type_mapping = [];
        $template_function_id = 'fn-' . strtolower($cased_function_id);
        // This checks for param references in the return type tokens
        // If found, the param is replaced with a generated template param
        foreach ($fixed_type_tokens as $i => $type_token) {
            $token_body = $type_token[0];
            if ($token_body[0] === '$') {
                foreach ($storage->params as $j => $param_storage) {
                    if ('$' . $param_storage->name === $token_body) {
                        if (!isset($param_type_mapping[$token_body])) {
                            $template_name = 'TGeneratedFromParam' . $j;
                            if (isset($storage->template_types[$template_name])) {
                                $function_template_types[$template_name] = $storage->template_types[$template_name];
                                $param_type_mapping[$token_body] = $template_name;
                            } else {
                                $template_as_type = $param_storage->type ?: Type::get_mixed();
                                $storage->template_types[$template_name] = [$template_function_id => $template_as_type];
                                $function_template_types[$template_name] = $storage->template_types[$template_name];
                                $param_type_mapping[$token_body] = $template_name;
                                $param_storage->type = new Union([new T_Template_Param($template_name, $template_as_type, $template_function_id)]);
                            }
                        }
                        // spaces are allowed before $foo in get(string $foo) magic method
                        // definitions, but we want to remove them in this instance
                        if ($i > 0 && isset($fixed_type_tokens[$i - 1]) && $fixed_type_tokens[$i - 1][0][0] === ' ') {
                            unset($fixed_type_tokens[$i - 1]);
                        }
                        $fixed_type_tokens[$i][0] = $param_type_mapping[$token_body];
                        continue 2;
                    }
                }
            }
            if ($token_body === 'func_num_args()') {
                $template_name = 'TFunctionArgCount';
                $storage->template_types[$template_name] = ['fn-' . strtolower($storage->cased_name ?? '') => Type::get_int()];
                $function_template_types[$template_name] = $storage->template_types[$template_name];
                $fixed_type_tokens[$i][0] = $template_name;
            }
            if ($token_body === 'PHP_MAJOR_VERSION') {
                $template_name = 'TPhpMajorVersion';
                $storage->template_types[$template_name] = ['fn-' . strtolower($storage->cased_name ?? '') => Type::get_int()];
                $function_template_types[$template_name] = $storage->template_types[$template_name];
                $fixed_type_tokens[$i][0] = $template_name;
            }
            if ($token_body === 'PHP_VERSION_ID') {
                $template_name = 'TPhpVersionId';
                $storage->template_types[$template_name] = ['fn-' . strtolower($storage->cased_name ?? '') => Type::get_int()];
                $function_template_types[$template_name] = $storage->template_types[$template_name];
                $fixed_type_tokens[$i][0] = $template_name;
            }
        }
        return [$fixed_type_tokens, $function_template_types];
    }
    /**
     * @param array<string, array<string, Union>> $class_template_types
     * @param array<string, array<string, Union>> $function_template_types
     * @param array<string, TypeAlias> $type_aliases
     * @return non-empty-list<Assertion>|null
     */
    private static function get_assertion_parts(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, Php_Parser\Node\Function_Like $stmt, Function_Like_Storage $storage, string $assertion_type, array $class_template_types, array $function_template_types, array $type_aliases, ?string $self_fqcln): ?array
    {
        $is_negation = false;
        $is_loose_equality = false;
        $is_strict_equality = false;
        if ($assertion_type[0] === '!') {
            $is_negation = true;
            $assertion_type = substr($assertion_type, 1);
        }
        if ($assertion_type[0] === '~') {
            $is_loose_equality = true;
            $assertion_type = substr($assertion_type, 1);
        }
        if ($assertion_type[0] === '=') {
            $is_strict_equality = true;
            $assertion_type = substr($assertion_type, 1);
        }
        $class_template_types = !$stmt instanceof Php_Parser\Node\Stmt\Class_Method || !$stmt->is_static() ? $class_template_types : [];
        if ($assertion_type === 'falsy') {
            return [$is_negation ? new Truthy() : new Falsy()];
        }
        if ($assertion_type === 'truthy') {
            return [$is_negation ? new Falsy() : new Truthy()];
        }
        if ($assertion_type === 'empty') {
            return [$is_negation ? new Non_Empty() : new Empty_()];
        }
        $template_types = $function_template_types + $class_template_types;
        try {
            $namespaced_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($assertion_type, $aliases, $template_types, $type_aliases, $self_fqcln, null, true), null, $template_types, $type_aliases);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock('Invalid @psalm-assert union type: ' . $e->get_message(), new Code_Location($file_scanner, $stmt, null, true));
            return null;
        }
        if (($is_negation || $is_loose_equality || $is_strict_equality) && count($namespaced_type->get_atomic_types()) > 1) {
            $storage->docblock_issues[] = new Invalid_Docblock('Docblock assertions cannot contain | characters together with a prefix', new Code_Location($file_scanner, $stmt, null, true));
            return null;
        }
        /** @psalm-suppress UnusedMethodCall */
        $namespaced_type->queue_class_likes_for_scanning($codebase, $file_storage, $function_template_types + $class_template_types);
        $assertion_type_parts = [];
        foreach ($namespaced_type->get_atomic_types() as $namespaced_type_part) {
            if ($is_negation) {
                if ($is_strict_equality) {
                    $assertion_type_parts[] = new Is_Not_Identical($namespaced_type_part);
                } elseif ($is_loose_equality) {
                    $assertion_type_parts[] = new Is_Not_Loosely_Equal($namespaced_type_part);
                } else {
                    $assertion_type_parts[] = new Is_Not_Type($namespaced_type_part);
                }
            } else if ($is_strict_equality) {
                $assertion_type_parts[] = new Is_Identical($namespaced_type_part);
            } elseif ($is_loose_equality) {
                $assertion_type_parts[] = new Is_Loosely_Equal($namespaced_type_part);
            } else {
                $assertion_type_parts[] = new Is_Type($namespaced_type_part);
            }
        }
        return $assertion_type_parts;
    }
    /**
     * @param array<string, array<string, Union>> $class_template_types
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, TypeAlias> $type_aliases
     * @param array<
     *     int,
     *     array{
     *         type:string,
     *         name:string,
     *         line_number:int,
     *         start:int,
     *         end:int,
     *         description?:string
     *     }
     * > $docblock_params
     */
    private static function improve_params_from_docblock(Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, array $type_aliases, ?Class_Like_Storage $classlike_storage, Function_Like_Storage $storage, array &$function_template_types, array $class_template_types, array $docblock_params, Php_Parser\Node\Function_Like $function, bool $fake_method, ?string $fq_classlike_name): void
    {
        $base = $classlike_storage ? $classlike_storage->name . '::' : '';
        $cased_method_id = $base . $storage->cased_name;
        $unused_docblock_params = [];
        $class_template_types = !$function instanceof Php_Parser\Node\Stmt\Class_Method || !$function->is_static() ? $class_template_types : [];
        foreach ($docblock_params as $docblock_param) {
            $param_name = $docblock_param['name'];
            $docblock_param_variadic = false;
            if (str_starts_with($param_name, '...')) {
                $docblock_param_variadic = true;
                $param_name = substr($param_name, 3);
            }
            $param_name = substr($param_name, 1);
            $storage_param = null;
            foreach ($storage->params as $function_signature_param) {
                if ($function_signature_param->name === $param_name) {
                    $storage_param = $function_signature_param;
                    break;
                }
            }
            if (!$fake_method) {
                $docblock_type_location = new Docblock_Type_Location($file_scanner, $docblock_param['start'], $docblock_param['end'], $docblock_param['line_number']);
            } else {
                $docblock_type_location = new Code_Location($file_scanner, $function, null, false, Code_Location::FUNCTION_PHPDOC_METHOD);
            }
            if ($storage_param === null) {
                $param_location = new Code_Location($file_scanner, $function, null, true, Code_Location::FUNCTION_PARAM_VAR, null, $docblock_param['line_number']);
                $unused_docblock_params[$param_name] = $param_location;
                if (!$docblock_param_variadic) {
                    continue;
                }
                if ($storage->params) {
                    continue;
                }
                if ($file_scanner->will_analyze) {
                    continue;
                }
                $storage_param = new Function_Like_Parameter($param_name, false, null, null, null, null, false, false, true);
                $storage->add_param($storage_param);
            }
            try {
                $new_param_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_param['type'], $aliases, $function_template_types + $class_template_types, $type_aliases, $fq_classlike_name), null, $function_template_types + $class_template_types, $type_aliases, true);
            } catch (Type_Parse_Tree_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_method_id, $docblock_type_location);
                continue;
            }
            $storage_param->has_docblock_type = true;
            /** @psalm-suppress UnusedMethodCall */
            $new_param_type->queue_class_likes_for_scanning($codebase, $file_storage, $storage->template_types ?: []);
            if ($storage->template_types) {
                foreach ($storage->template_types as $t => $type_map) {
                    foreach ($type_map as $obj => $type) {
                        if ($type->is_mixed() && $docblock_param['type'] === 'class-string<' . $t . '>') {
                            $storage->template_types[$t][$obj] = Type::get_object();
                            if (isset($function_template_types[$t])) {
                                $function_template_types[$t][$obj] = $storage->template_types[$t][$obj];
                            }
                        }
                    }
                }
            }
            if (!$docblock_param_variadic && $storage_param->is_variadic && $new_param_type->has_array()) {
                /**
                 * @var TArray|TKeyedArray
                 */
                $array_type = $new_param_type->get_array();
                if ($array_type instanceof T_Keyed_Array) {
                    $new_param_type = $array_type->get_generic_value_type();
                } else {
                    $new_param_type = $array_type->type_params[1];
                }
            }
            $existing_param_type_nullable = $storage_param->is_nullable;
            if (isset($docblock_param['description'])) {
                $storage_param->description = $docblock_param['description'];
            }
            if (!$storage_param->type || $storage_param->type->has_mixed() || $storage->template_types) {
                if ($existing_param_type_nullable && !$new_param_type->is_nullable() && !$new_param_type->has_template()) {
                    $new_param_type = $new_param_type->get_builder()->add_type(new T_Null())->freeze();
                }
                $config = Config::get_instance();
                if ($config->add_param_default_to_docblock_type && $storage_param->default_type instanceof Union && !$storage_param->default_type->has_mixed() && (!$storage_param->type || !$storage_param->type->has_mixed())) {
                    $new_param_type = Type::combine_union_types($new_param_type, $storage_param->default_type);
                }
                $storage_param->type = $new_param_type;
                $storage_param->type_location = $docblock_type_location;
                continue;
            }
            $storage_param_atomic_types = $storage_param->type->get_atomic_types();
            $all_typehint_types_match = true;
            foreach ($new_param_type->get_atomic_types() as $key => $type) {
                if (isset($storage_param_atomic_types[$key])) {
                    /** @psalm-suppress InaccessibleProperty We just created this type */
                    $type->from_docblock = false;
                    if ($storage_param_atomic_types[$key] instanceof T_Array && $type instanceof T_Array && $type->type_params[0]->has_array_key()) {
                        /** @psalm-suppress InaccessibleProperty We just created this type */
                        $type->type_params[0]->from_docblock = false;
                    }
                } else {
                    $all_typehint_types_match = false;
                }
            }
            if ($all_typehint_types_match) {
                /** @psalm-suppress InaccessibleProperty We just created this type */
                $new_param_type->from_docblock = false;
            }
            if ($existing_param_type_nullable && !$new_param_type->is_nullable()) {
                $new_param_type = $new_param_type->get_builder()->add_type(new T_Null())->freeze();
            }
            $storage_param->type = $new_param_type;
            $storage_param->type_location = $docblock_type_location;
        }
        $params_without_docblock_type = array_filter($storage->params, static fn(Function_Like_Parameter $p): bool => !$p->has_docblock_type && (!$p->type || $p->type->has_array()));
        $storage->has_undertyped_native_parameters = $params_without_docblock_type !== [];
        $storage->unused_docblock_parameters = $unused_docblock_params;
    }
    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handle_return(Codebase $codebase, Function_Docblock_Comment $docblock_info, string $docblock_return_type, bool $fake_method, File_Scanner $file_scanner, Function_Like_Storage $storage, Php_Parser\Node\Function_Like $stmt, Aliases $aliases, array $function_template_types, array $class_template_types, array $type_aliases, ?Class_Like_Storage $classlike_storage, string $cased_function_id, File_Storage $file_storage): void
    {
        if (!$fake_method && $docblock_info->return_type_line_number && $docblock_info->return_type_start && $docblock_info->return_type_end) {
            $storage->return_type_location = new Docblock_Type_Location($file_scanner, $docblock_info->return_type_start, $docblock_info->return_type_end, $docblock_info->return_type_line_number);
        } else {
            $storage->return_type_location = new Code_Location($file_scanner, $stmt, null, false, !$fake_method ? Code_Location::FUNCTION_PHPDOC_RETURN_TYPE : Code_Location::FUNCTION_PHPDOC_METHOD, $docblock_info->return_type, $docblock_info->return_type_line_number && !$fake_method ? $docblock_info->return_type_line_number : null);
        }
        try {
            [$fixed_type_tokens, $function_template_types] = self::get_conditional_sanitized_type_tokens($docblock_return_type, $aliases, $function_template_types + $class_template_types, $type_aliases, $storage, $classlike_storage, $cased_function_id, $function_template_types);
            $storage->return_type = Type_Parser::parse_tokens(array_values($fixed_type_tokens), null, $function_template_types + $class_template_types, $type_aliases, true);
            if ($storage instanceof Method_Storage) {
                $storage->has_docblock_return_type = true;
            }
            if ($storage->signature_return_type) {
                $all_typehint_types_match = true;
                $signature_return_atomic_types = $storage->signature_return_type->get_atomic_types();
                foreach ($storage->return_type->get_atomic_types() as $key => $type) {
                    if (isset($signature_return_atomic_types[$key])) {
                        /** @psalm-suppress InaccessibleProperty We just created this atomic type */
                        $type->from_docblock = false;
                    } else {
                        $all_typehint_types_match = false;
                    }
                }
                if ($all_typehint_types_match) {
                    /** @psalm-suppress InaccessibleProperty We just created this type */
                    $storage->return_type->from_docblock = false;
                    if ($storage instanceof Method_Storage) {
                        $storage->has_docblock_return_type = true;
                    }
                }
                // if the signature type contains null, we add null into the final return type too
                if ($storage->signature_return_type->is_nullable() && !$storage->return_type->is_nullable() && !$storage->return_type->has_template() && !$storage->return_type->has_conditional()) {
                    //don't add null to final type if signature type don't match the docblock type
                    // however, we can't check for object types at this point (#6931), so we'll assume it's ok
                    if ($storage->return_type->has_object_type() || Union_Type_Comparator::is_contained_by($codebase, $storage->return_type, $storage->signature_return_type)) {
                        $storage->return_type = $storage->return_type->get_builder()->add_type(new T_Null())->freeze();
                    }
                }
            }
            /** @psalm-suppress UnusedMethodCall */
            $storage->return_type->queue_class_likes_for_scanning($codebase, $file_storage);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($file_scanner, $stmt, null, true));
        }
        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_nullable_return && $storage->return_type && ($codebase->config->ignore_internal_nullable_issues || !in_array($file_storage->file_path, $codebase->config->internal_stubs))) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_nullable_issues = true;
        }
        // we make sure we only add ignore flag for internal stubs if the config is set to true
        if ($docblock_info->ignore_falsable_return && $storage->return_type && ($codebase->config->ignore_internal_falsable_issues || !in_array($file_storage->file_path, $codebase->config->internal_stubs))) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->ignore_falsable_issues = true;
        }
        if ($stmt->returns_by_ref() && $storage->return_type) {
            /** @psalm-suppress InaccessibleProperty We just created this type */
            $storage->return_type->by_ref = true;
        }
        $storage->return_type_description = $docblock_info->return_type_description;
    }
    private static function handle_taint_flow(Function_Docblock_Comment $docblock_info, Function_Like_Storage $storage): void
    {
        foreach ($docblock_info->flows as $flow) {
            $path_type = 'arg';
            $fancy_path_regex = '/-\(([a-z\-]+)\)->/';
            if (preg_match($fancy_path_regex, $flow, $matches)) {
                if (isset($matches[1])) {
                    $path_type = $matches[1];
                }
                $flow = (string) preg_replace($fancy_path_regex, '->', $flow);
            }
            $flow_parts = explode('->', $flow);
            if (isset($flow_parts[1]) && trim($flow_parts[1]) === 'return') {
                $source_param_string = trim($flow_parts[0]);
                if ($source_param_string[0] === '(' && str_ends_with($source_param_string, ')')) {
                    $source_params = preg_split('/, ?/', substr($source_param_string, 1, -1));
                    if ($source_params === false) {
                        throw new AssertionError(preg_last_error_msg());
                    }
                    foreach ($source_params as $source_param) {
                        $source_param = substr($source_param, 1);
                        foreach ($storage->params as $i => $param_storage) {
                            if ($param_storage->name === $source_param) {
                                $storage->return_source_params[$i] = $path_type;
                            }
                        }
                    }
                }
            }
            if (isset($flow_parts[0]) && str_starts_with(trim($flow_parts[0]), 'proxy')) {
                $proxy_call = trim(substr($flow_parts[0], strlen('proxy')));
                [$fully_qualified_name, $source_param_string] = explode('(', $proxy_call, 2);
                if (!empty($fully_qualified_name) && !empty($source_param_string)) {
                    $source_params = preg_split('/, ?/', substr($source_param_string, 0, -1)) ?: [];
                    $call_params = [];
                    foreach ($source_params as $source_param) {
                        $source_param = substr($source_param, 1);
                        foreach ($storage->params as $i => $param_storage) {
                            if ($param_storage->name === $source_param) {
                                $call_params[] = $i;
                            }
                        }
                    }
                    if ($storage->proxy_calls === null) {
                        $storage->proxy_calls = [];
                    }
                    $storage->proxy_calls[] = ['fqn' => $fully_qualified_name, 'params' => $call_params, 'return' => isset($flow_parts[1]) && trim($flow_parts[1]) === 'return'];
                }
            }
        }
    }
    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handle_removed_taint(Codebase $codebase, Php_Parser\Node\Function_Like $stmt, Aliases $aliases, string $removed_taint, array $function_template_types, array $class_template_types, array $type_aliases, Function_Like_Storage $storage, ?Class_Like_Storage $classlike_storage, string $cased_function_id, File_Storage $file_storage, File_Scanner $file_scanner): void
    {
        try {
            [$fixed_type_tokens, $function_template_types] = self::get_conditional_sanitized_type_tokens($removed_taint, $aliases, $function_template_types + $class_template_types, $type_aliases, $storage, $classlike_storage, $cased_function_id, $function_template_types);
            $removed_taint = Type_Parser::parse_tokens(array_values($fixed_type_tokens), null, $function_template_types + $class_template_types, $type_aliases);
            /** @psalm-suppress UnusedMethodCall */
            $removed_taint->queue_class_likes_for_scanning($codebase, $file_storage);
            $removed_taint_single = $removed_taint->get_single_atomic();
            if (!$removed_taint_single instanceof T_Conditional) {
                throw new Type_Parse_Tree_Exception('Escaped taint must be a conditional');
            }
            $storage->conditionally_removed_taints[] = $removed_taint;
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($file_scanner, $stmt, null, true));
        }
    }
    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, non-empty-array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     */
    private static function handle_assertions(Function_Docblock_Comment $docblock_info, Function_Like_Storage $storage, Codebase $codebase, File_Scanner $file_scanner, File_Storage $file_storage, Aliases $aliases, Php_Parser\Node\Function_Like $stmt, array $class_template_types, array $function_template_types, array $type_aliases, ?Class_Like_Storage $classlike_storage): void
    {
        if ($docblock_info->assertions) {
            $storage->assertions = [];
            foreach ($docblock_info->assertions as $assertion) {
                $assertion_type_parts = self::get_assertion_parts($codebase, $file_scanner, $file_storage, $aliases, $stmt, $storage, $assertion['type'], $class_template_types, $function_template_types, $type_aliases, $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null);
                if (!$assertion_type_parts) {
                    continue;
                }
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->assertions[] = new Possibilities($i, $assertion_type_parts);
                        continue 2;
                    }
                    if (str_starts_with($assertion['param_name'], $param->name . '->')) {
                        $storage->assertions[] = new Possibilities(substr_replace($assertion['param_name'], (string) $i, 0, strlen($param->name)), $assertion_type_parts);
                        continue 2;
                    }
                }
                $storage->assertions[] = new Possibilities((!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'], $assertion_type_parts);
            }
        }
        if ($docblock_info->if_true_assertions) {
            $storage->if_true_assertions = [];
            foreach ($docblock_info->if_true_assertions as $assertion) {
                $assertion_type_parts = self::get_assertion_parts($codebase, $file_scanner, $file_storage, $aliases, $stmt, $storage, $assertion['type'], $class_template_types, $function_template_types, $type_aliases, $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null);
                if (!$assertion_type_parts) {
                    continue;
                }
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->if_true_assertions[] = new Possibilities($i, $assertion_type_parts);
                        continue 2;
                    }
                    if (str_starts_with($assertion['param_name'], $param->name . '->')) {
                        $storage->if_true_assertions[] = new Possibilities(str_replace($param->name, (string) $i, $assertion['param_name']), $assertion_type_parts);
                        continue 2;
                    }
                }
                $storage->if_true_assertions[] = new Possibilities((!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'], $assertion_type_parts);
            }
        }
        if ($docblock_info->if_false_assertions) {
            $storage->if_false_assertions = [];
            foreach ($docblock_info->if_false_assertions as $assertion) {
                $assertion_type_parts = self::get_assertion_parts($codebase, $file_scanner, $file_storage, $aliases, $stmt, $storage, $assertion['type'], $class_template_types, $function_template_types, $type_aliases, $classlike_storage && !$classlike_storage->is_trait ? $classlike_storage->name : null);
                if (!$assertion_type_parts) {
                    continue;
                }
                foreach ($storage->params as $i => $param) {
                    if ($param->name === $assertion['param_name']) {
                        $storage->if_false_assertions[] = new Possibilities($i, $assertion_type_parts);
                        continue 2;
                    }
                    if (str_starts_with($assertion['param_name'], $param->name . '->')) {
                        $storage->if_false_assertions[] = new Possibilities(str_replace($param->name, (string) $i, $assertion['param_name']), $assertion_type_parts);
                        continue 2;
                    }
                }
                $storage->if_false_assertions[] = new Possibilities((!str_contains($assertion['param_name'], '$') ? '$' : '') . $assertion['param_name'], $assertion_type_parts);
            }
        }
    }
    /**
     * @param array<string, TypeAlias> $type_aliases
     * @param array<string, array<string, Union>> $function_template_types
     * @param array<string, non-empty-array<string, Union>> $class_template_types
     * @param  array{name:string, type:string, line_number: int} $docblock_param_out
     */
    private static function handle_param_out(array $docblock_param_out, Aliases $aliases, array $function_template_types, array $class_template_types, array $type_aliases, string $cased_function_id, File_Scanner $file_scanner, Php_Parser\Node\Function_Like $stmt, Function_Like_Storage $storage, Codebase $codebase, File_Storage $file_storage): void
    {
        $param_name = substr($docblock_param_out['name'], 1);
        try {
            $out_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($docblock_param_out['type'], $aliases, $function_template_types + $class_template_types, $type_aliases), null, $function_template_types + $class_template_types, $type_aliases);
        } catch (Type_Parse_Tree_Exception $e) {
            $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($file_scanner, $stmt, null, true));
            return;
        }
        /** @psalm-suppress UnusedMethodCall */
        $out_type->queue_class_likes_for_scanning($codebase, $file_storage, $storage->template_types ?: []);
        foreach ($storage->params as $param_storage) {
            if ($param_storage->name === $param_name) {
                $param_storage->out_type = $out_type;
            }
        }
    }
    /**
     * @param ?array<string, non-empty-array<string, Union>> $template_types
     * @param array<string, TypeAlias> $type_aliases
     * @return array<string, non-empty-array<string, Union>>
     */
    private static function handle_templates(Function_Like_Storage $storage, Function_Docblock_Comment $docblock_info, Aliases $aliases, ?array $template_types, array $type_aliases, File_Scanner $file_scanner, Php_Parser\Node\Function_Like $stmt, string $cased_function_id): array
    {
        $storage->template_types = [];
        foreach ($docblock_info->templates as $template_map) {
            $template_name = $template_map[0];
            if ($template_map[1] !== null && $template_map[2] !== null) {
                if (trim($template_map[2])) {
                    $type_string = $template_map[2];
                    try {
                        $type_string = Comment_Analyzer::split_doc_line($type_string)[0];
                    } catch (Docblock_Parse_Exception $e) {
                        throw new Docblock_Parse_Exception($type_string . ' is not a valid type: ' . $e->get_message());
                    }
                    $type_string = Comment_Analyzer::sanitize_docblock_type($type_string);
                    try {
                        $template_type = Type_Parser::parse_tokens(Type_Tokenizer::get_fully_qualified_tokens($type_string, $aliases, $storage->template_types + ($template_types ?: []), $type_aliases), null, $storage->template_types + ($template_types ?: []), $type_aliases);
                    } catch (Type_Parse_Tree_Exception $e) {
                        $storage->docblock_issues[] = new Invalid_Docblock('Template ' . $template_name . ' has invalid as type - ' . $e->get_message(), new Code_Location($file_scanner, $stmt, null, true));
                        $template_type = Type::get_mixed();
                    }
                } else {
                    $storage->docblock_issues[] = new Invalid_Docblock('Template ' . $template_name . ' missing as type', new Code_Location($file_scanner, $stmt, null, true));
                    $template_type = Type::get_mixed();
                }
            } else {
                $template_type = Type::get_mixed();
            }
            if (isset($template_types[$template_name])) {
                $storage->docblock_issues[] = new Invalid_Docblock('Duplicate template param ' . $template_name . ' in docblock for ' . $cased_function_id, new Code_Location($file_scanner, $stmt, null, true));
            } else {
                $storage->template_types[$template_name] = ['fn-' . strtolower($cased_function_id) => $template_type];
            }
        }
        return array_merge($template_types ?: [], $storage->template_types);
    }
    private static function handle_unexpected_tags(Function_Docblock_Comment $docblock_info, Function_Like_Storage $storage, Php_Parser\Node\Function_Like $stmt, File_Scanner $file_scanner, string $cased_function_id): void
    {
        foreach ($docblock_info->unexpected_tags as $tag => $details) {
            foreach ($details['lines'] as $line) {
                $tag_location = new Code_Location($file_scanner, $stmt, null, true, null, null, $line);
                $message = 'Docblock tag @' . $tag . ' is not recognized in the function docblock ' . 'for ' . $cased_function_id;
                if (isset($details['suggested_replacement'])) {
                    $message .= ', did you mean to use @' . $details['suggested_replacement'] . '?';
                }
                $storage->docblock_issues[] = new Possibly_Invalid_Docblock_Tag($message, $tag_location);
            }
        }
    }
}