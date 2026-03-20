<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use InvalidArgumentException;
use LogicException;
use Psalm\Codebase;
use Psalm\Exception\Type_Parse_Tree_Exception;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Array_Analyzer;
use Psalm\Internal\Type\Parse_Tree\Callable_Param_Tree;
use Psalm\Internal\Type\Parse_Tree\Callable_Tree;
use Psalm\Internal\Type\Parse_Tree\Callable_With_Return_Type_Tree;
use Psalm\Internal\Type\Parse_Tree\Conditional_Tree;
use Psalm\Internal\Type\Parse_Tree\Encapsulation_Tree;
use Psalm\Internal\Type\Parse_Tree\Field_Ellipsis;
use Psalm\Internal\Type\Parse_Tree\Generic_Tree;
use Psalm\Internal\Type\Parse_Tree\Indexed_Access_Tree;
use Psalm\Internal\Type\Parse_Tree\Intersection_Tree;
use Psalm\Internal\Type\Parse_Tree\Keyed_Array_Property_Tree;
use Psalm\Internal\Type\Parse_Tree\Keyed_Array_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_With_Return_Type_Tree;
use Psalm\Internal\Type\Parse_Tree\Nullable_Tree;
use Psalm\Internal\Type\Parse_Tree\Template_As_Tree;
use Psalm\Internal\Type\Parse_Tree\Union_Tree;
use Psalm\Internal\Type\Parse_Tree\Value;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Array_Key;
use Psalm\Type\Atomic\T_Callable;
use Psalm\Type\Atomic\T_Callable_Keyed_Array;
use Psalm\Type\Atomic\T_Callable_Object;
use Psalm\Type\Atomic\T_Class_Constant;
use Psalm\Type\Atomic\T_Class_String;
use Psalm\Type\Atomic\T_Class_String_Map;
use Psalm\Type\Atomic\T_Closure;
use Psalm\Type\Atomic\T_Conditional;
use Psalm\Type\Atomic\T_Generic_Object;
use Psalm\Type\Atomic\T_Int;
use Psalm\Type\Atomic\T_Int_Mask;
use Psalm\Type\Atomic\T_Int_Mask_Of;
use Psalm\Type\Atomic\T_Int_Range;
use Psalm\Type\Atomic\T_Iterable;
use Psalm\Type\Atomic\T_Key_Of;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Literal_Class_String;
use Psalm\Type\Atomic\T_Literal_Float;
use Psalm\Type\Atomic\T_Literal_Int;
use Psalm\Type\Atomic\T_Literal_String;
use Psalm\Type\Atomic\T_Mixed;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Atomic\T_Never;
use Psalm\Type\Atomic\T_Non_Empty_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Atomic\T_Object;
use Psalm\Type\Atomic\T_Object_With_Properties;
use Psalm\Type\Atomic\T_Properties_Of;
use Psalm\Type\Atomic\T_String;
use Psalm\Type\Atomic\T_Template_Indexed_Access;
use Psalm\Type\Atomic\T_Template_Key_Of;
use Psalm\Type\Atomic\T_Template_Param;
use Psalm\Type\Atomic\T_Template_Param_Class;
use Psalm\Type\Atomic\T_Template_Properties_Of;
use Psalm\Type\Atomic\T_Template_Value_Of;
use Psalm\Type\Atomic\T_Type_Alias;
use Psalm\Type\Atomic\T_Unknown_Class_String;
use Psalm\Type\Atomic\T_Value_Of;
use Psalm\Type\Type_Node;
use Psalm\Type\Union;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_pop;
use function array_shift;
use function array_unique;
use function array_unshift;
use function array_values;
use function assert;
use function constant;
use function count;
use function defined;
use function end;
use function explode;
use function in_array;
use function is_int;
use function is_numeric;
use function preg_match;
use function preg_replace;
use function reset;
use function str_contains;
use function str_starts_with;
use function stripslashes;
use function strlen;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
/**
 * @psalm-suppress InaccessibleProperty Allowed during construction
 * @internal
 */
final class Type_Parser
{
    /**
     * Parses a string type representation
     *
     * @param  list<array{0: string, 1: int, 2?: string}> $type_tokens
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     */
    public static function parse_tokens(array $type_tokens, ?int $analysis_php_version_id = null, array $template_type_map = [], array $type_aliases = [], bool $from_docblock = false): Union
    {
        if (count($type_tokens) === 1) {
            $only_token = $type_tokens[0];
            // Note: valid identifiers can include class names or $this
            if (!preg_match('@^(\$this|\\\\?[a-zA-Z_\x7f-\xff][\\\\\\-0-9a-zA-Z_\x7f-\xff]*)$@', $only_token[0])) {
                if (!is_numeric($only_token[0]) && str_contains($only_token[0], '\'') && str_contains($only_token[0], '"')) {
                    throw new Type_Parse_Tree_Exception("Invalid type '{$only_token[0]}'");
                }
            } else {
                $only_token[0] = Type_Tokenizer::fix_scalar_terms($only_token[0], $analysis_php_version_id);
                $atomic = Atomic::create($only_token[0], $analysis_php_version_id, $template_type_map, $type_aliases, 0, strlen($only_token[0]), isset($only_token[2]) && $only_token[2] !== $only_token[0] ? $only_token[2] : null, $from_docblock);
                return new Union([$atomic], ['from_docblock' => $from_docblock]);
            }
        }
        $parse_tree = (new Parse_Tree_Creator($type_tokens))->create();
        $codebase = Project_Analyzer::get_instance()->get_codebase();
        $parsed_type = self::get_type_from_tree($parse_tree, $codebase, $analysis_php_version_id, $template_type_map, $type_aliases, $from_docblock);
        if (!$parsed_type instanceof Union) {
            return new Union([$parsed_type], ['from_docblock' => $from_docblock]);
        }
        return $parsed_type;
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias>            $type_aliases
     * @return  Atomic|Union
     */
    public static function get_type_from_tree(Parse_Tree $parse_tree, Codebase $codebase, ?int $analysis_php_version_id = null, array $template_type_map = [], array $type_aliases = [], bool $from_docblock = false): Type_Node
    {
        if ($parse_tree instanceof Generic_Tree) {
            return self::get_type_from_generic_tree($parse_tree, $codebase, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Union_Tree) {
            return self::get_type_from_union_tree($parse_tree, $codebase, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Intersection_Tree) {
            return self::get_type_from_intersection_tree($parse_tree, $codebase, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Keyed_Array_Tree) {
            return self::get_type_from_keyed_array_tree($parse_tree, $codebase, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Callable_With_Return_Type_Tree) {
            $callable_type = self::get_type_from_tree($parse_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            if (!$callable_type instanceof T_Callable && !$callable_type instanceof T_Closure) {
                throw new InvalidArgumentException('Parsing callable tree node should return TCallable');
            }
            if (!isset($parse_tree->children[1])) {
                throw new Type_Parse_Tree_Exception('Invalid return type');
            }
            $return_type = self::get_type_from_tree($parse_tree->children[1], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            $callable_type->return_type = $return_type instanceof Union ? $return_type : new Union([$return_type], ['from_docblock' => $from_docblock]);
            return $callable_type;
        }
        if ($parse_tree instanceof Callable_Tree) {
            return self::get_type_from_callable_tree($parse_tree, $codebase, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Encapsulation_Tree) {
            if (!$parse_tree->terminated) {
                throw new Type_Parse_Tree_Exception('Unterminated parentheses');
            }
            if (!isset($parse_tree->children[0])) {
                throw new Type_Parse_Tree_Exception('Empty parentheses');
            }
            return self::get_type_from_tree($parse_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
        }
        if ($parse_tree instanceof Nullable_Tree) {
            if (!isset($parse_tree->children[0])) {
                throw new Type_Parse_Tree_Exception('Misplaced question mark');
            }
            $non_nullable_type = self::get_type_from_tree($parse_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            if ($non_nullable_type instanceof Union) {
                $non_nullable_type = $non_nullable_type->get_builder()->add_type(new T_Null($from_docblock))->freeze();
                return $non_nullable_type;
            }
            return Type_Combiner::combine([new T_Null($from_docblock), $non_nullable_type]);
        }
        if ($parse_tree instanceof Method_Tree || $parse_tree instanceof Method_With_Return_Type_Tree) {
            throw new Type_Parse_Tree_Exception('Misplaced brackets');
        }
        if ($parse_tree instanceof Indexed_Access_Tree) {
            return self::get_type_from_index_access_tree($parse_tree, $template_type_map, $from_docblock);
        }
        if ($parse_tree instanceof Template_As_Tree) {
            return new T_Template_Param($parse_tree->param_name, new Union([new T_Named_Object($parse_tree->as)]), 'class-string-map', [], $from_docblock);
        }
        if ($parse_tree instanceof Conditional_Tree) {
            $template_param_name = $parse_tree->condition->param_name;
            if (!isset($template_type_map[$template_param_name])) {
                throw new Type_Parse_Tree_Exception('Unrecognized template \'' . $template_param_name . '\'');
            }
            if (count($parse_tree->children) !== 2) {
                throw new Type_Parse_Tree_Exception('Invalid conditional');
            }
            $first_class = array_keys($template_type_map[$template_param_name])[0];
            $conditional_type = self::get_type_from_tree($parse_tree->condition->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            $if_type = self::get_type_from_tree($parse_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            $else_type = self::get_type_from_tree($parse_tree->children[1], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            if ($conditional_type instanceof Atomic) {
                $conditional_type = new Union([$conditional_type], ['from_docblock' => $from_docblock]);
            }
            if ($if_type instanceof Atomic) {
                $if_type = new Union([$if_type], ['from_docblock' => $from_docblock]);
            }
            if ($else_type instanceof Atomic) {
                $else_type = new Union([$else_type], ['from_docblock' => $from_docblock]);
            }
            return new T_Conditional($template_param_name, $first_class, $template_type_map[$template_param_name][$first_class], $conditional_type, $if_type, $else_type, $from_docblock);
        }
        if (!$parse_tree instanceof Value) {
            throw new InvalidArgumentException('Unrecognised parse tree type ' . $parse_tree::class);
        }
        if ($parse_tree->value[0] === '"' || $parse_tree->value[0] === '\'') {
            return Type::get_atomic_string_from_literal(substr($parse_tree->value, 1, -1), $from_docblock);
        }
        if (strpos($parse_tree->value, '::')) {
            [$fq_classlike_name, $const_name] = explode('::', $parse_tree->value);
            if (isset($template_type_map[$fq_classlike_name]) && $const_name === 'class') {
                $first_class = array_keys($template_type_map[$fq_classlike_name])[0];
                return self::get_generic_param_class($fq_classlike_name, $template_type_map[$fq_classlike_name][$first_class], $first_class, $from_docblock);
            }
            if ($const_name === 'class') {
                return new T_Literal_Class_String($fq_classlike_name, false, $from_docblock);
            }
            return new T_Class_Constant($fq_classlike_name, $const_name, $from_docblock);
        }
        if (preg_match('/^\-?(0|[1-9][0-9]*)(\.[0-9]{1,})$/', $parse_tree->value)) {
            return new T_Literal_Float((float) $parse_tree->value, $from_docblock);
        }
        if (preg_match('/^\-?(0|[1-9]([0-9_]*[0-9])?)$/', $parse_tree->value)) {
            return new T_Literal_Int((int) strtr($parse_tree->value, ['_' => '']), $from_docblock);
        }
        if (!preg_match('@^(\$this|\\\\?[a-zA-Z_\x7f-\xff][\\\\\\-0-9a-zA-Z_\x7f-\xff]*)$@', $parse_tree->value)) {
            throw new Type_Parse_Tree_Exception('Invalid type \'' . $parse_tree->value . '\'');
        }
        $atomic_type_string = Type_Tokenizer::fix_scalar_terms($parse_tree->value, $analysis_php_version_id);
        return Atomic::create($atomic_type_string, $analysis_php_version_id, $template_type_map, $type_aliases, $parse_tree->offset_start, $parse_tree->offset_end, $parse_tree->text, $from_docblock);
    }
    private static function get_generic_param_class(string $param_name, Union &$as, string $defining_class, bool $from_docblock = false): T_Template_Param_Class
    {
        if ($as->has_mixed()) {
            return new T_Template_Param_Class($param_name, 'object', null, $defining_class, $from_docblock);
        }
        foreach ($as->get_atomic_types() as $t) {
            if ($t instanceof T_Object) {
                return new T_Template_Param_Class($param_name, 'object', null, $defining_class, $from_docblock);
            }
            if ($t instanceof T_Iterable) {
                $traversable = new T_Generic_Object('Traversable', $t->type_params, false, false, [], $from_docblock);
                $as = $as->get_builder()->substitute(new Union([$t]), new Union([$traversable]))->freeze();
                return new T_Template_Param_Class($param_name, $traversable->value, $traversable, $defining_class, $from_docblock);
            }
            if ($t instanceof T_Template_Param) {
                $t_atomic_type = count($t->as->get_atomic_types()) === 1 ? $t->as->get_single_atomic() : null;
                if (!$t_atomic_type instanceof T_Named_Object) {
                    $t_atomic_type = null;
                }
                return new T_Template_Param_Class($t->param_name, $t_atomic_type->value ?? 'object', $t_atomic_type, $t->defining_class, $from_docblock);
            }
            if (!$t instanceof T_Named_Object) {
                throw new Type_Parse_Tree_Exception('Invalid templated classname \'' . $t->get_id() . '\'');
            }
            return new T_Template_Param_Class($param_name, $t->value, $t, $defining_class, $from_docblock);
        }
        throw new LogicException('Should never get here');
    }
    /**
     * @param  non-empty-list<int>  $potential_ints
     * @return  non-empty-list<TLiteralInt>
     */
    public static function get_computed_ints_from_mask(array $potential_ints, bool $from_docblock = false): array
    {
        /** @var list<int> */
        $potential_values = [];
        foreach ($potential_ints as $ith) {
            $new_values = [];
            $new_values[] = $ith;
            if ($ith !== 0) {
                foreach ($potential_values as $potential_value) {
                    $new_values[] = $ith | $potential_value;
                }
            }
            $potential_values = [...$new_values, ...$potential_values];
        }
        array_unshift($potential_values, 0);
        $potential_values = array_unique($potential_values);
        return array_map(static fn($int): T_Literal_Int => new T_Literal_Int($int, $from_docblock), array_values($potential_values));
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     * @throws TypeParseTreeException
     * @psalm-suppress ComplexMethod to be refactored
     */
    private static function get_type_from_generic_tree(Generic_Tree $parse_tree, Codebase $codebase, array $template_type_map, array $type_aliases, bool $from_docblock = false): Atomic|Union
    {
        $generic_type = $parse_tree->value;
        $generic_params = [];
        foreach ($parse_tree->children as $i => $child_tree) {
            $tree_type = self::get_type_from_tree($child_tree, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            if ($generic_type === 'class-string-map' && $i === 0) {
                if ($tree_type instanceof T_Template_Param) {
                    $template_type_map[$tree_type->param_name] = ['class-string-map' => $tree_type->as];
                } elseif ($tree_type instanceof T_Named_Object) {
                    $template_type_map[$tree_type->value] = ['class-string-map' => Type::get_object()];
                }
            }
            $generic_params[] = $tree_type instanceof Union ? $tree_type : new Union([$tree_type], ['from_docblock' => $from_docblock]);
        }
        $generic_type_value = Type_Tokenizer::fix_scalar_terms($generic_type);
        if (($generic_type_value === 'array' || $generic_type_value === 'non-empty-array' || $generic_type_value === 'associative-array') && count($generic_params) === 1) {
            array_unshift($generic_params, new Union([new T_Array_Key($from_docblock)]));
        } elseif (count($generic_params) === 1 && in_array($generic_type_value, ['iterable', 'Traversable', 'Iterator', 'IteratorAggregate', 'arraylike-object'], true)) {
            array_unshift($generic_params, new Union([new T_Mixed(false, $from_docblock)]));
        } elseif ($generic_type_value === 'Generator') {
            if (count($generic_params) === 1) {
                array_unshift($generic_params, new Union([new T_Mixed(false, $from_docblock)]));
            }
            for ($i = 0, $l = 4 - count($generic_params); $i < $l; ++$i) {
                $generic_params[] = new Union([new T_Mixed(false, $from_docblock)]);
            }
        }
        if (!$generic_params) {
            throw new Type_Parse_Tree_Exception('No generic params provided for type');
        }
        if ($generic_type_value === 'array' || $generic_type_value === 'associative-array' || $generic_type_value === 'non-empty-array') {
            if ($generic_type_value !== 'non-empty-array') {
                $generic_type_value = 'array';
            }
            if ($generic_params[0]->is_mixed()) {
                $generic_params[0] = Type::get_array_key($from_docblock);
            }
            if (count($generic_params) !== 2) {
                throw new Type_Parse_Tree_Exception('Too many template parameters for ' . $generic_type_value);
            }
            if ($type_aliases !== []) {
                $intersection_types = self::resolve_type_aliases($codebase, $generic_params[0]->get_atomic_types());
                if ($intersection_types !== []) {
                    $generic_params[0] = $generic_params[0]->set_types($intersection_types);
                }
            }
            foreach ($generic_params[0]->get_atomic_types() as $key => $atomic_type) {
                if ($atomic_type instanceof T_Literal_String && ($string_to_int = Array_Analyzer::get_literal_array_key_int($atomic_type->value)) !== false) {
                    $builder = $generic_params[0]->get_builder();
                    $builder->remove_type($key);
                    $generic_params[0] = $builder->add_type(new T_Literal_Int($string_to_int, $from_docblock))->freeze();
                    continue;
                }
                if ($atomic_type instanceof T_Int) {
                    continue;
                }
                if ($atomic_type instanceof T_String) {
                    continue;
                }
                if ($atomic_type instanceof T_Array_Key) {
                    continue;
                }
                if ($atomic_type instanceof T_Class_Constant) {
                    continue;
                }
                if ($atomic_type instanceof T_Mixed) {
                    continue;
                }
                if ($atomic_type instanceof T_Never) {
                    continue;
                }
                if ($atomic_type instanceof T_Template_Param) {
                    continue;
                }
                if ($atomic_type instanceof T_Template_Indexed_Access) {
                    continue;
                }
                if ($atomic_type instanceof T_Template_Value_Of) {
                    continue;
                }
                if ($atomic_type instanceof T_Template_Key_Of) {
                    continue;
                }
                if ($atomic_type instanceof T_Template_Param_Class) {
                    continue;
                }
                if ($atomic_type instanceof T_Type_Alias) {
                    continue;
                }
                if ($atomic_type instanceof T_Value_Of) {
                    continue;
                }
                if ($atomic_type instanceof T_Conditional) {
                    continue;
                }
                if ($atomic_type instanceof T_Key_Of) {
                    continue;
                }
                if (!$from_docblock) {
                    continue;
                }
                if ($codebase->register_stub_files || $codebase->register_autoload_files) {
                    $builder = $generic_params[0]->get_builder();
                    $builder->remove_type($key);
                    if (count($generic_params[0]->get_atomic_types()) <= 1) {
                        $builder = $builder->add_type(new T_Array_Key($from_docblock));
                    }
                    $generic_params[0] = $builder->freeze();
                    continue;
                }
                throw new Type_Parse_Tree_Exception('Invalid array key type ' . $atomic_type->get_key());
            }
            return $generic_type_value === 'array' ? new T_Array($generic_params, $from_docblock) : new T_Non_Empty_Array($generic_params, null, null, 'non-empty-array', $from_docblock);
        }
        if ($generic_type_value === 'arraylike-object') {
            $array_access = new T_Generic_Object('ArrayAccess', $generic_params, false, false, [], $from_docblock);
            $countable = new T_Named_Object('Countable', false, false, [], $from_docblock);
            return new T_Generic_Object('Traversable', $generic_params, false, false, [$array_access->get_key() => $array_access, $countable->get_key() => $countable], $from_docblock);
        }
        if ($generic_type_value === 'iterable') {
            if (count($generic_params) > 2) {
                throw new Type_Parse_Tree_Exception('Too many template parameters for iterable');
            }
            return new T_Iterable($generic_params, [], $from_docblock);
        }
        if ($generic_type_value === 'list') {
            if (count($generic_params) > 1) {
                throw new Type_Parse_Tree_Exception('Too many template parameters for list');
            }
            return Type::get_list_atomic($generic_params[0], $from_docblock);
        }
        if ($generic_type_value === 'non-empty-list') {
            return Type::get_non_empty_list_atomic($generic_params[0], $from_docblock);
        }
        if ($generic_type_value === 'class-string' || $generic_type_value === 'interface-string' || $generic_type_value === 'enum-string') {
            $class_name = $generic_params[0]->get_id(false);
            if (isset($template_type_map[$class_name])) {
                $first_class = array_keys($template_type_map[$class_name])[0];
                return self::get_generic_param_class($class_name, $template_type_map[$class_name][$first_class], $first_class, $from_docblock);
            }
            $types = [];
            foreach ($generic_params[0]->get_atomic_types() as $type) {
                if ($type instanceof T_Named_Object) {
                    $types[] = new T_Class_String($type->value, $type, false, false, false, $from_docblock);
                    continue;
                }
                if ($type instanceof T_Callable_Object) {
                    $types[] = new T_Unknown_Class_String($type, false, $from_docblock);
                    continue;
                }
                throw new Type_Parse_Tree_Exception('class-string param can only target to named or callable objects');
            }
            assert($types !== [], 'Since `Union` cannot be empty and all non-supported atomics lead to thrown exception,' . ' we can safely assert that the types array is non-empty.');
            return new Union($types);
        }
        if ($generic_type_value === 'class-string-map') {
            if (count($generic_params) !== 2) {
                throw new Type_Parse_Tree_Exception('There should only be two params for class-string-map, ' . count($generic_params) . ' provided');
            }
            $template_marker_parts = array_values($generic_params[0]->get_atomic_types());
            $template_marker = $template_marker_parts[0];
            $template_as_type = null;
            if ($template_marker instanceof T_Named_Object) {
                $template_param_name = $template_marker->value;
            } elseif ($template_marker instanceof T_Template_Param) {
                $template_param_name = $template_marker->param_name;
                $template_as_type = $template_marker->as->get_single_atomic();
                if (!$template_as_type instanceof T_Named_Object) {
                    throw new Type_Parse_Tree_Exception('Unrecognised as type');
                }
            } else {
                throw new Type_Parse_Tree_Exception('Unrecognised class-string-map templated param');
            }
            return new T_Class_String_Map($template_param_name, $template_as_type, $generic_params[1], $from_docblock);
        }
        if (in_array($generic_type_value, T_Properties_Of::token_names())) {
            if (count($generic_params) !== 1) {
                throw new Type_Parse_Tree_Exception($generic_type_value . ' requires exactly one parameter.');
            }
            $param_name = (string) $generic_params[0];
            if (isset($template_type_map[$param_name]) && ($defining_class = array_key_first($template_type_map[$param_name])) !== null) {
                $template_param = $generic_params[0]->get_single_atomic();
                if (!$template_param instanceof T_Template_Param) {
                    throw new Type_Parse_Tree_Exception($generic_type_value . '<' . $param_name . '> must be a TTemplateParam.');
                }
                if ($template_param->get_intersection_types()) {
                    throw new Type_Parse_Tree_Exception($generic_type_value . '<' . $param_name . '> must be a TTemplateParam' . ' with no intersection types.');
                }
                return new T_Template_Properties_Of($param_name, $defining_class, $template_param, T_Properties_Of::filter_for_token_name($generic_type_value), $from_docblock);
            }
            $param_union_types = array_values($generic_params[0]->get_atomic_types());
            if (count($param_union_types) > 1) {
                throw new Type_Parse_Tree_Exception('Union types are not allowed in ' . $generic_type_value . ' param');
            }
            if (!$param_union_types[0] instanceof T_Named_Object) {
                throw new Type_Parse_Tree_Exception('Param should be a named object in ' . $generic_type_value);
            }
            return new T_Properties_Of($param_union_types[0], T_Properties_Of::filter_for_token_name($generic_type_value), $from_docblock);
        }
        if ($generic_type_value === 'key-of') {
            $param_name = $generic_params[0]->get_id(false);
            if (isset($template_type_map[$param_name]) && ($defining_class = array_key_first($template_type_map[$param_name])) !== null) {
                return new T_Template_Key_Of($param_name, $defining_class, $generic_params[0], $from_docblock);
            }
            if (!T_Key_Of::is_viable_template_type($generic_params[0])) {
                throw new Type_Parse_Tree_Exception('Untemplated key-of param ' . $param_name . ' should be an array');
            }
            return new T_Key_Of($generic_params[0], $from_docblock);
        }
        if ($generic_type_value === 'value-of') {
            $param_name = $generic_params[0]->get_id(false);
            if (isset($template_type_map[$param_name]) && ($defining_class = array_key_first($template_type_map[$param_name])) !== null) {
                return new T_Template_Value_Of($param_name, $defining_class, $generic_params[0], $from_docblock);
            }
            if (!T_Value_Of::is_viable_template_type($generic_params[0])) {
                throw new Type_Parse_Tree_Exception('Untemplated value-of param ' . $param_name . ' should be an array');
            }
            return new T_Value_Of($generic_params[0]);
        }
        if ($generic_type_value === 'int-mask') {
            $atomic_types = [];
            foreach ($generic_params as $generic_param) {
                if (!$generic_param->is_single()) {
                    throw new Type_Parse_Tree_Exception('int-mask types must all be non-union');
                }
                $generic_param_atomics = $generic_param->get_atomic_types();
                $atomic_type = reset($generic_param_atomics);
                if ($atomic_type instanceof T_Named_Object) {
                    if (defined($atomic_type->value)) {
                        $constant_value = constant($atomic_type->value);
                        if (!is_int($constant_value)) {
                            throw new Type_Parse_Tree_Exception('int-mask types must all be integer values');
                        }
                        $atomic_type = new T_Literal_Int($constant_value, $from_docblock);
                    } else {
                        throw new Type_Parse_Tree_Exception('int-mask types must all be integer values');
                    }
                }
                if (!$atomic_type instanceof T_Literal_Int && !($atomic_type instanceof T_Class_Constant && !str_contains($atomic_type->const_name, '*'))) {
                    throw new Type_Parse_Tree_Exception('int-mask types must all be integer values or scalar class constants');
                }
                $atomic_types[] = $atomic_type;
            }
            $potential_ints = [];
            foreach ($atomic_types as $atomic_type) {
                if (!$atomic_type instanceof T_Literal_Int) {
                    return new T_Int_Mask($atomic_types, $from_docblock);
                }
                $potential_ints[] = $atomic_type->value;
            }
            return new Union(self::get_computed_ints_from_mask($potential_ints, $from_docblock));
        }
        if ($generic_type_value === 'int-mask-of') {
            $param_union_types = array_values($generic_params[0]->get_atomic_types());
            if (count($param_union_types) > 1) {
                throw new Type_Parse_Tree_Exception('Union types are not allowed in value-of type');
            }
            $param_type = $param_union_types[0];
            if (!$param_type instanceof T_Class_Constant && !$param_type instanceof T_Value_Of && !$param_type instanceof T_Key_Of) {
                throw new Type_Parse_Tree_Exception('Invalid reference passed to int-mask-of');
            }
            if ($param_type instanceof T_Class_Constant && !str_contains($param_type->const_name, '*')) {
                throw new Type_Parse_Tree_Exception('Class constant passed to int-mask-of must be a wildcard type');
            }
            return new T_Int_Mask_Of($param_type, $from_docblock);
        }
        if ($generic_type_value === 'int') {
            if (count($generic_params) !== 2) {
                throw new Type_Parse_Tree_Exception('int range must have 2 params');
            }
            assert(count($parse_tree->children) === 2);
            $get_int_range_bound = static function (Parse_Tree $parse_tree, Union $generic_param, string $bound_name): ?int {
                if (!$parse_tree instanceof Value || count($generic_param->get_atomic_types()) > 1 || !$generic_param->get_single_atomic() instanceof T_Literal_Int && $parse_tree->value !== $bound_name && $parse_tree->text !== $bound_name) {
                    throw new Type_Parse_Tree_Exception("Invalid type \"{$generic_param->get_id()}\" as int {$bound_name} boundary");
                }
                $generic_param_atomic = $generic_param->get_single_atomic();
                return $generic_param_atomic instanceof T_Literal_Int ? $generic_param_atomic->value : null;
            };
            $min_bound = $get_int_range_bound($parse_tree->children[0], $generic_params[0], T_Int_Range::BOUND_MIN);
            $max_bound = $get_int_range_bound($parse_tree->children[1], $generic_params[1], T_Int_Range::BOUND_MAX);
            if ($min_bound === null && $max_bound === null) {
                return new T_Int($from_docblock);
            }
            if (is_int($min_bound) && is_int($max_bound) && $min_bound > $max_bound) {
                throw new Type_Parse_Tree_Exception("Min bound can't be greater than max bound, int<{$min_bound}, {$max_bound}> given");
            }
            if (is_int($min_bound) && is_int($max_bound) && $min_bound > $max_bound) {
                throw new Type_Parse_Tree_Exception("Min bound can't be greater than max bound, int<{$min_bound}, {$max_bound}> given");
            }
            return new T_Int_Range($min_bound, $max_bound, $from_docblock);
        }
        if (isset(Type_Tokenizer::PSALM_RESERVED_WORDS[$generic_type_value]) && $generic_type_value !== 'self' && $generic_type_value !== 'static') {
            throw new Type_Parse_Tree_Exception('Cannot create generic object with reserved word');
        }
        return new T_Generic_Object($generic_type_value, $generic_params, false, false, [], $from_docblock);
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     * @throws TypeParseTreeException
     */
    private static function get_type_from_union_tree(Union_Tree $parse_tree, Codebase $codebase, array $template_type_map, array $type_aliases, bool $from_docblock): Union
    {
        $has_null = false;
        $atomic_types = [];
        foreach ($parse_tree->children as $child_tree) {
            if ($child_tree instanceof Nullable_Tree) {
                if (!isset($child_tree->children[0])) {
                    throw new Type_Parse_Tree_Exception('Invalid ? character');
                }
                $atomic_type = self::get_type_from_tree($child_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
                $has_null = true;
            } else {
                $atomic_type = self::get_type_from_tree($child_tree, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            }
            if ($atomic_type instanceof Union) {
                foreach ($atomic_type->get_atomic_types() as $type) {
                    $atomic_types[] = $type;
                }
                continue;
            }
            $atomic_types[] = $atomic_type;
        }
        if ($has_null) {
            $atomic_types[] = new T_Null($from_docblock);
        }
        if (!$atomic_types) {
            throw new Type_Parse_Tree_Exception('No atomic types found');
        }
        return Type_Combiner::combine($atomic_types);
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     * @throws TypeParseTreeException
     */
    private static function get_type_from_intersection_tree(Intersection_Tree $parse_tree, Codebase $codebase, array $template_type_map, array $type_aliases, bool $from_docblock): Atomic
    {
        $intersection_types = [];
        foreach ($parse_tree->children as $name => $child_tree) {
            $atomic_type = self::get_type_from_tree($child_tree, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            if (!$atomic_type instanceof Atomic) {
                throw new Type_Parse_Tree_Exception('Intersection types cannot contain unions');
            }
            $intersection_types[$name] = $atomic_type;
        }
        if ($intersection_types === []) {
            return new T_Mixed();
        }
        $first_type = reset($intersection_types);
        $last_type = end($intersection_types);
        $only_t_keyed_array = $first_type instanceof T_Keyed_Array || $last_type instanceof T_Keyed_Array;
        foreach ($intersection_types as $intersection_type) {
            if (!$intersection_type instanceof T_Keyed_Array && ($intersection_type !== $first_type || !$first_type instanceof T_Array) && ($intersection_type !== $last_type || !$last_type instanceof T_Array)) {
                $only_t_keyed_array = false;
                break;
            }
        }
        if ($only_t_keyed_array) {
            /**
             * @var array<TKeyedArray> $intersection_types
             * @var TKeyedArray $first_type
             * @var TKeyedArray $last_type
             */
            return self::get_type_from_keyed_arrays($codebase, $intersection_types, $first_type, $last_type, $from_docblock);
        }
        $keyed_intersection_types = self::extract_keyed_intersection_types($codebase, $intersection_types);
        $intersect_static = false;
        if (isset($keyed_intersection_types['static'])) {
            unset($keyed_intersection_types['static']);
            $intersect_static = true;
        }
        if ($keyed_intersection_types === [] && $intersect_static) {
            return new T_Named_Object('static', false, false, [], $from_docblock);
        }
        $first_type = array_shift($keyed_intersection_types);
        assert($first_type !== null);
        // Keyed array intersection are merged together and are not combinable with object-types
        if ($first_type instanceof T_Keyed_Array) {
            // assume all types are keyed arrays
            array_unshift($keyed_intersection_types, $first_type);
            /** @var TKeyedArray $last_type */
            $last_type = end($keyed_intersection_types);
            /** @var array<TKeyedArray> $keyed_intersection_types */
            return self::get_type_from_keyed_arrays($codebase, $keyed_intersection_types, $first_type, $last_type, $from_docblock);
        }
        if ($intersect_static && $first_type instanceof T_Named_Object) {
            $first_type->is_static = true;
        }
        if ($keyed_intersection_types) {
            /** @var non-empty-array<string,TIterable|TNamedObject|TCallableObject|TTemplateParam|TObjectWithProperties> $keyed_intersection_types */
            return $first_type->set_intersection_types($keyed_intersection_types);
        }
        return $first_type;
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     * @throws TypeParseTreeException
     */
    private static function get_type_from_callable_tree(Callable_Tree $parse_tree, Codebase $codebase, array $template_type_map, array $type_aliases, bool $from_docblock): T_Callable|T_Closure
    {
        $params = [];
        foreach ($parse_tree->children as $child_tree) {
            $is_variadic = false;
            $is_optional = false;
            $param_name = '';
            if ($child_tree instanceof Callable_Param_Tree) {
                if (isset($child_tree->children[0])) {
                    $tree_type = self::get_type_from_tree($child_tree->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
                } else {
                    $tree_type = new T_Mixed(false, $from_docblock);
                }
                $is_variadic = $child_tree->variadic;
                $is_optional = $child_tree->has_default;
                $param_name = $child_tree->name ?? '';
            } else {
                if ($child_tree instanceof Value && strpos($child_tree->value, '$') > 0) {
                    $child_tree->value = (string) preg_replace('/(.+)\$.*/', '$1', $child_tree->value);
                }
                $tree_type = self::get_type_from_tree($child_tree, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
            }
            $param = new Function_Like_Parameter($param_name, false, $tree_type instanceof Union ? $tree_type : new Union([$tree_type]), null, null, null, $is_optional, false, $is_variadic);
            $params[] = $param;
        }
        $pure = str_starts_with($parse_tree->value, 'pure-') ? true : null;
        if (in_array(strtolower($parse_tree->value), ['closure', '\closure', 'pure-closure'], true)) {
            return new T_Closure('Closure', $params, null, $pure, [], [], $from_docblock);
        }
        return new T_Callable('callable', $params, null, $pure, $from_docblock);
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @throws TypeParseTreeException
     */
    private static function get_type_from_index_access_tree(Indexed_Access_Tree $parse_tree, array $template_type_map, bool $from_docblock): T_Template_Indexed_Access
    {
        if (!isset($parse_tree->children[0]) || !$parse_tree->children[0] instanceof Value) {
            throw new Type_Parse_Tree_Exception('Unrecognised indexed access');
        }
        $offset_param_name = $parse_tree->value;
        $array_param_name = $parse_tree->children[0]->value;
        if (!isset($template_type_map[$offset_param_name])) {
            throw new Type_Parse_Tree_Exception('Unrecognised template param ' . $offset_param_name);
        }
        if (!isset($template_type_map[$array_param_name])) {
            throw new Type_Parse_Tree_Exception('Unrecognised template param ' . $array_param_name);
        }
        $offset_template_data = $template_type_map[$offset_param_name];
        $offset_defining_class = array_keys($offset_template_data)[0];
        if (!$offset_defining_class && isset($offset_template_data['']) && $offset_template_data['']->is_single()) {
            $offset_template_type = $offset_template_data['']->get_single_atomic();
            if ($offset_template_type instanceof T_Template_Key_Of) {
                $offset_defining_class = $offset_template_type->defining_class;
            }
        }
        $array_defining_class = array_keys($template_type_map[$array_param_name])[0];
        if ($offset_defining_class !== $array_defining_class && !str_starts_with($offset_defining_class, 'fn-')) {
            throw new Type_Parse_Tree_Exception('Template params are defined in different locations');
        }
        return new T_Template_Indexed_Access($array_param_name, $offset_param_name, $array_defining_class, $from_docblock);
    }
    /**
     * @param  array<string, array<string, Union>> $template_type_map
     * @param  array<string, TypeAlias> $type_aliases
     * @throws TypeParseTreeException
     */
    private static function get_type_from_keyed_array_tree(Keyed_Array_Tree $parse_tree, Codebase $codebase, array $template_type_map, array $type_aliases, bool $from_docblock): T_Callable_Keyed_Array|T_Keyed_Array|T_Object_With_Properties|T_Array
    {
        $properties = [];
        $class_strings = [];
        $type = $parse_tree->value;
        $had_optional = false;
        $had_explicit = false;
        $had_implicit = false;
        $previous_property_key = -1;
        $is_list = true;
        $sealed = true;
        $extra_params = null;
        $last_property_branch = end($parse_tree->children);
        if ($last_property_branch instanceof Generic_Tree && $last_property_branch->value === '') {
            $extra_params = $last_property_branch->children;
            array_pop($parse_tree->children);
        }
        foreach ($parse_tree->children as $i => $property_branch) {
            $class_string = false;
            if ($property_branch instanceof Field_Ellipsis) {
                if ($i !== count($parse_tree->children) - 1) {
                    throw new Type_Parse_Tree_Exception('Unexpected ...');
                }
                $sealed = false;
                break;
            }
            if (!$property_branch instanceof Keyed_Array_Property_Tree) {
                $property_type = self::get_type_from_tree($property_branch, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
                $property_maybe_undefined = false;
                $property_key = $i;
                $had_implicit = true;
            } elseif (count($property_branch->children) === 1) {
                $property_type = self::get_type_from_tree($property_branch->children[0], $codebase, null, $template_type_map, $type_aliases, $from_docblock);
                $property_maybe_undefined = $property_branch->possibly_undefined;
                if (strpos($property_branch->value, '::')) {
                    [$fq_classlike_name, $const_name] = explode('::', $property_branch->value);
                    if ($const_name === 'class') {
                        $property_key = $fq_classlike_name;
                        $class_string = true;
                    } elseif ($property_branch->value[0] === '"' || $property_branch->value[0] === "'") {
                        $property_key = $property_branch->value;
                    } else {
                        throw new Type_Parse_Tree_Exception(':: in array key is only allowed for ::class');
                    }
                } else {
                    $property_key = $property_branch->value;
                }
                if ($is_list && (Array_Analyzer::get_literal_array_key_int($property_key) === false || $had_optional && !$property_maybe_undefined || $type === 'array' || $type === 'callable-array' || $previous_property_key != $property_key - 1)) {
                    $is_list = false;
                }
                $had_explicit = true;
                $previous_property_key = $property_key;
                if ($property_key[0] === '\'' || $property_key[0] === '"') {
                    $property_key = stripslashes(substr($property_key, 1, -1));
                }
            } else {
                throw new Type_Parse_Tree_Exception('Missing property type');
            }
            if (!$property_type instanceof Union) {
                $property_type = new Union([$property_type], ['from_docblock' => $from_docblock]);
            }
            if ($property_maybe_undefined) {
                $property_type->possibly_undefined = true;
                $had_optional = true;
            }
            if (isset($properties[$property_key])) {
                throw new Type_Parse_Tree_Exception("Duplicate key {$property_key} detected");
            }
            $properties[$property_key] = $property_type;
            if ($class_string) {
                $class_strings[$property_key] = true;
            }
        }
        if ($had_explicit && $had_implicit) {
            throw new Type_Parse_Tree_Exception('Cannot mix explicit and implicit keys');
        }
        if ($type === 'object') {
            return new T_Object_With_Properties($properties, [], [], $from_docblock);
        }
        $callable = str_starts_with($type, 'callable-');
        $class = T_Keyed_Array::class;
        if ($callable) {
            $class = T_Callable_Keyed_Array::class;
            $type = substr($type, 9);
        }
        if ($callable && !$properties) {
            throw new Type_Parse_Tree_Exception('A callable array cannot be empty');
        }
        if ($type !== 'array' && $type !== 'list') {
            throw new Type_Parse_Tree_Exception('Unexpected brace character');
        }
        if ($type === 'list' && !$is_list) {
            throw new Type_Parse_Tree_Exception('A list shape cannot describe a non-list');
        }
        if (!$properties) {
            return new T_Array([Type::get_never($from_docblock), Type::get_never($from_docblock)], $from_docblock);
        }
        if ($extra_params) {
            if ($is_list && count($extra_params) !== 1) {
                throw new Type_Parse_Tree_Exception('Must have exactly one extra field!');
            }
            if (!$is_list && count($extra_params) !== 2) {
                throw new Type_Parse_Tree_Exception('Must have exactly two extra fields!');
            }
            $final_extra_params = $is_list ? [Type::get_list_key(true)] : [];
            foreach ($extra_params as $child_tree) {
                $child_type = self::get_type_from_tree($child_tree, $codebase, null, $template_type_map, $type_aliases, $from_docblock);
                if ($child_type instanceof Atomic) {
                    $child_type = new Union([$child_type]);
                }
                $final_extra_params[] = $child_type;
            }
            $extra_params = $final_extra_params;
        }
        return new $class($properties, $class_strings, $extra_params ?? ($sealed ? null : [$is_list ? Type::get_list_key() : Type::get_array_key(), Type::get_mixed()]), $is_list, $from_docblock);
    }
    /**
     * @param TNamedObject|TObjectWithProperties|TCallableObject|TIterable|TTemplateParam|TKeyedArray $intersection_type
     */
    private static function extract_intersection_key(Atomic $intersection_type): string
    {
        return $intersection_type instanceof T_Iterable || $intersection_type instanceof T_Keyed_Array ? $intersection_type->get_id() : $intersection_type->get_key();
    }
    /**
     * @param non-empty-array<Atomic> $intersection_types
     * @return non-empty-array<string,TIterable|TNamedObject|TCallableObject|TTemplateParam|TObjectWithProperties|TKeyedArray>
     */
    private static function extract_keyed_intersection_types(Codebase $codebase, array $intersection_types): array
    {
        $keyed_intersection_types = [];
        $callable_intersection = null;
        $any_object_type_found = $any_array_found = false;
        $normalized_intersection_types = self::resolve_type_aliases($codebase, $intersection_types);
        foreach ($normalized_intersection_types as $intersection_type) {
            if ($intersection_type instanceof T_Keyed_Array && !$intersection_type instanceof T_Callable_Keyed_Array) {
                $any_array_found = true;
                if ($any_object_type_found) {
                    throw new Type_Parse_Tree_Exception('The intersection type must not mix array and object types!');
                }
                $keyed_intersection_types[self::extract_intersection_key($intersection_type)] = $intersection_type;
                continue;
            }
            $any_object_type_found = true;
            if ($intersection_type instanceof T_Iterable || $intersection_type instanceof T_Named_Object || $intersection_type instanceof T_Template_Param || $intersection_type instanceof T_Object_With_Properties) {
                $keyed_intersection_types[self::extract_intersection_key($intersection_type)] = $intersection_type;
                continue;
            }
            if ($intersection_type::class === T_Object::class) {
                continue;
            }
            if ($intersection_type instanceof T_Callable) {
                if ($callable_intersection !== null) {
                    throw new Type_Parse_Tree_Exception('The intersection type must not contain more than one callable type!');
                }
                $callable_intersection = $intersection_type;
                continue;
            }
            throw new Type_Parse_Tree_Exception('Intersection types must be all objects, ' . $intersection_type::class . ' provided');
        }
        if ($callable_intersection !== null) {
            $callable_object_type = new T_Callable_Object($callable_intersection->from_docblock, $callable_intersection);
            $keyed_intersection_types[self::extract_intersection_key($callable_object_type)] = $callable_object_type;
        }
        if ($any_object_type_found && $any_array_found) {
            throw new Type_Parse_Tree_Exception('Intersection types must be all objects or all keyed array.');
        }
        assert($keyed_intersection_types !== []);
        return $keyed_intersection_types;
    }
    /**
     * @param array<Atomic> $intersection_types
     * @return array<Atomic>
     */
    private static function resolve_type_aliases(Codebase $codebase, array $intersection_types): array
    {
        $normalized_intersection_types = [];
        $modified = false;
        foreach ($intersection_types as $intersection_type) {
            if (!$intersection_type instanceof T_Type_Alias || !$codebase->classlike_storage_provider->has($intersection_type->declaring_fq_classlike_name)) {
                $normalized_intersection_types[] = [$intersection_type];
                continue;
            }
            $expanded_intersection_type = Type_Expander::expand_atomic($codebase, $intersection_type, null, null, null, true, false, false, true, true, true);
            $modified = $modified || $expanded_intersection_type[0] !== $intersection_type;
            $normalized_intersection_types[] = $expanded_intersection_type;
        }
        if ($modified === false) {
            return $intersection_types;
        }
        return self::resolve_type_aliases($codebase, array_merge(...$normalized_intersection_types));
    }
    /**
     * @param array<TKeyedArray> $intersection_types
     * @param TKeyedArray|TArray $first_type
     * @param TKeyedArray|TArray $last_type
     */
    private static function get_type_from_keyed_arrays(Codebase $codebase, array $intersection_types, Atomic $first_type, Atomic $last_type, bool $from_docblock): \Psalm\Type\Atomic\T_Keyed_Array
    {
        /** @var non-empty-array<string|int, Union> */
        $properties = [];
        if ($first_type instanceof T_Array) {
            array_shift($intersection_types);
        } elseif ($last_type instanceof T_Array) {
            array_pop($intersection_types);
        }
        $all_sealed = true;
        foreach ($intersection_types as $intersection_type) {
            if ($intersection_type->fallback_params !== null) {
                $all_sealed = false;
            }
            foreach ($intersection_type->properties as $property => $property_type) {
                if (!array_key_exists($property, $properties)) {
                    $properties[$property] = $property_type;
                    continue;
                }
                $new_type = Type::intersect_union_types($properties[$property], $property_type, $codebase);
                if ($new_type === null) {
                    throw new Type_Parse_Tree_Exception('Incompatible intersection types for "' . $property . '", ' . $properties[$property] . ' and ' . $property_type . ' provided');
                }
                $properties[$property] = $new_type;
            }
        }
        $first_or_last_type = $first_type instanceof T_Array ? $first_type : ($last_type instanceof T_Array ? $last_type : null);
        $fallback_params = null;
        if ($first_or_last_type !== null) {
            $fallback_params = [$first_or_last_type->type_params[0], $first_or_last_type->type_params[1]];
        } elseif (!$all_sealed) {
            $fallback_params = [Type::get_array_key(), Type::get_mixed()];
        }
        return new T_Keyed_Array($properties, null, $fallback_params, false, $from_docblock);
    }
}