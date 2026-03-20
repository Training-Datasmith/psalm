<?php

declare (strict_types=1);
namespace Psalm\Internal\Type;

use Psalm\Exception\Type_Parse_Tree_Exception;
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
use Psalm\Internal\Type\Parse_Tree\Method_Param_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_Tree;
use Psalm\Internal\Type\Parse_Tree\Method_With_Return_Type_Tree;
use Psalm\Internal\Type\Parse_Tree\Nullable_Tree;
use Psalm\Internal\Type\Parse_Tree\Root;
use Psalm\Internal\Type\Parse_Tree\Template_As_Tree;
use Psalm\Internal\Type\Parse_Tree\Template_Is_Tree;
use Psalm\Internal\Type\Parse_Tree\Union_Tree;
use Psalm\Internal\Type\Parse_Tree\Value;
use function array_pop;
use function count;
use function in_array;
use function preg_match;
use function str_contains;
use function strlen;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Parse_Tree_Creator
{
    private Parse_Tree $parse_tree;
    private Parse_Tree $current_leaf;
    private readonly int $type_token_count;
    private int $t = 0;
    /**
     * @param list<array{0: string, 1: int, 2?: string}> $type_tokens
     */
    public function __construct(private array $type_tokens)
    {
        $this->type_token_count = count($type_tokens);
        $this->parse_tree = new Root();
        $this->current_leaf = $this->parse_tree;
    }
    public function create(): Parse_Tree
    {
        while ($this->t < $this->type_token_count) {
            $type_token = $this->type_tokens[$this->t];
            switch ($type_token[0]) {
                case '{':
                case ']':
                    throw new Type_Parse_Tree_Exception('Unexpected token ' . $type_token[0]);
                case '<':
                    $this->handle_less_than();
                    break;
                case '[':
                    $this->handle_open_square_bracket();
                    break;
                case '(':
                    $this->handle_open_round_bracket();
                    break;
                case ')':
                    $this->handle_closed_round_bracket();
                    break;
                case '>':
                    do {
                        if ($this->current_leaf->parent === null) {
                            throw new Type_Parse_Tree_Exception('Cannot parse generic type');
                        }
                        $this->current_leaf = $this->current_leaf->parent;
                    } while (!$this->current_leaf instanceof Generic_Tree);
                    $this->current_leaf->terminated = true;
                    break;
                case '}':
                    do {
                        if ($this->current_leaf->parent === null) {
                            throw new Type_Parse_Tree_Exception('Cannot parse array type');
                        }
                        $this->current_leaf = $this->current_leaf->parent;
                    } while (!$this->current_leaf instanceof Keyed_Array_Tree);
                    $this->current_leaf->terminated = true;
                    break;
                case ',':
                    $this->handle_comma();
                    break;
                case '...':
                case '=':
                    $this->handle_ellipsis_or_equals($type_token);
                    break;
                case ':':
                    $this->handle_colon();
                    break;
                case ' ':
                    $this->handle_space();
                    break;
                case '?':
                    $this->handle_question_mark();
                    break;
                case '|':
                    $this->handle_bar();
                    break;
                case '&':
                    $this->handle_ampersand();
                    break;
                case 'is':
                case 'as':
                case 'of':
                    $this->handle_is_or_as($type_token);
                    break;
                default:
                    $this->handle_value($type_token);
                    break;
            }
            $this->t++;
        }
        $this->parse_tree->clean_parents();
        if ($this->current_leaf !== $this->parse_tree && ($this->parse_tree instanceof Generic_Tree || $this->parse_tree instanceof Callable_Tree || $this->parse_tree instanceof Keyed_Array_Tree)) {
            throw new Type_Parse_Tree_Exception('Unterminated bracket');
        }
        return $this->parse_tree;
    }
    /**
     * @param  array{0: string, 1: int, 2?: string} $current_token
     */
    private function create_method_param(array $current_token, Parse_Tree $current_parent): void
    {
        $byref = false;
        $variadic = false;
        $has_default = false;
        $default = '';
        if ($current_token[0] === '&') {
            $byref = true;
            ++$this->t;
            $current_token = $this->t < $this->type_token_count ? $this->type_tokens[$this->t] : null;
        } elseif ($current_token[0] === '...') {
            $variadic = true;
            ++$this->t;
            $current_token = $this->t < $this->type_token_count ? $this->type_tokens[$this->t] : null;
        }
        if (!$current_token || $current_token[0][0] !== '$') {
            throw new Type_Parse_Tree_Exception('Unexpected token after space');
        }
        $new_parent_leaf = new Method_Param_Tree($current_token[0], $byref, $variadic, $current_parent);
        for ($j = $this->t + 1; $j < $this->type_token_count; ++$j) {
            $ahead_type_token = $this->type_tokens[$j];
            if ($ahead_type_token[0] === ',' || $ahead_type_token[0] === ')' && $this->type_tokens[$j - 1][0] !== '(') {
                $this->t = $j - 1;
                break;
            }
            if ($has_default) {
                $default .= $ahead_type_token[0];
            }
            if ($ahead_type_token[0] === '=') {
                $has_default = true;
                continue;
            }
            if ($j === $this->type_token_count - 1) {
                throw new Type_Parse_Tree_Exception('Unterminated method');
            }
        }
        $new_parent_leaf->default = $default;
        if ($this->current_leaf !== $current_parent) {
            $new_parent_leaf->children = [$this->current_leaf];
            array_pop($current_parent->children);
        }
        $current_parent->children[] = $new_parent_leaf;
        $this->current_leaf = $new_parent_leaf;
    }
    /**
     * @param  array{0: string, 1: int, 2?: string} $current_token
     */
    private function parse_callable_param(array $current_token, Parse_Tree $current_parent): void
    {
        $variadic = false;
        $has_default = false;
        if ($current_token[0] === '&') {
            ++$this->t;
            $current_token = $this->t < $this->type_token_count ? $this->type_tokens[$this->t] : null;
        } elseif ($current_token[0] === '...') {
            $variadic = true;
            ++$this->t;
            $current_token = $this->t < $this->type_token_count ? $this->type_tokens[$this->t] : null;
        } elseif ($current_token[0] === '=') {
            $has_default = true;
            ++$this->t;
            $current_token = $this->t < $this->type_token_count ? $this->type_tokens[$this->t] : null;
        }
        if (!$current_token || $current_token[0][0] !== '$' || strlen($current_token[0]) < 2) {
            throw new Type_Parse_Tree_Exception('Unexpected token after space');
        }
        $new_leaf = new Callable_Param_Tree($current_parent);
        $new_leaf->has_default = $has_default;
        $new_leaf->variadic = $variadic;
        $potential_name = substr($current_token[0], 1);
        if ($potential_name !== '') {
            $new_leaf->name = $potential_name;
        }
        if ($current_parent !== $this->current_leaf) {
            $new_leaf->children = [$this->current_leaf];
            array_pop($current_parent->children);
        }
        $current_parent->children[] = $new_leaf;
        $this->current_leaf = $new_leaf;
    }
    private function handle_less_than(): void
    {
        if (!$this->current_leaf instanceof Field_Ellipsis) {
            throw new Type_Parse_Tree_Exception('Unexpected token <');
        }
        $current_parent = $this->current_leaf->parent;
        if (!$current_parent instanceof Keyed_Array_Tree) {
            throw new Type_Parse_Tree_Exception('Unexpected token <');
        }
        array_pop($current_parent->children);
        $generic_leaf = new Generic_Tree('', $current_parent);
        $current_parent->children[] = $generic_leaf;
        $this->current_leaf = $generic_leaf;
    }
    private function handle_open_square_bracket(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected token [');
        }
        $indexed_access = false;
        $next_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
        if (!$next_token || $next_token[0] !== ']') {
            $next_next_token = $this->t + 2 < $this->type_token_count ? $this->type_tokens[$this->t + 2] : null;
            if ($next_next_token !== null && $next_next_token[0] === ']') {
                $indexed_access = true;
                ++$this->t;
            } else {
                throw new Type_Parse_Tree_Exception('Unexpected token [');
            }
        }
        $current_parent = $this->current_leaf->parent;
        if ($indexed_access) {
            if ($next_token === null) {
                throw new Type_Parse_Tree_Exception('Unexpected token [');
            }
            $new_parent_leaf = new Indexed_Access_Tree($next_token[0], $current_parent);
        } else {
            if ($this->current_leaf instanceof Keyed_Array_Property_Tree) {
                throw new Type_Parse_Tree_Exception('Unexpected token [');
            }
            $new_parent_leaf = new Generic_Tree('array', $current_parent);
        }
        $this->current_leaf->parent = $new_parent_leaf;
        $new_parent_leaf->children = [$this->current_leaf];
        if ($current_parent) {
            array_pop($current_parent->children);
            $current_parent->children[] = $new_parent_leaf;
        } else {
            $this->parse_tree = $new_parent_leaf;
        }
        $this->current_leaf = $new_parent_leaf;
        ++$this->t;
    }
    private function handle_open_round_bracket(): void
    {
        if ($this->current_leaf instanceof Value) {
            throw new Type_Parse_Tree_Exception('Unrecognised token (');
        }
        $new_parent = !$this->current_leaf instanceof Root ? $this->current_leaf : null;
        $new_leaf = new Encapsulation_Tree($new_parent);
        if ($this->current_leaf instanceof Root) {
            $this->current_leaf = $this->parse_tree = $new_leaf;
            return;
        }
        if ($new_leaf->parent) {
            $new_leaf->parent->children[] = $new_leaf;
        }
        $this->current_leaf = $new_leaf;
    }
    private function handle_closed_round_bracket(): void
    {
        $prev_token = $this->t > 0 ? $this->type_tokens[$this->t - 1] : null;
        if ($prev_token !== null && $prev_token[0] === '(' && $this->current_leaf instanceof Callable_Tree) {
            return;
        }
        do {
            if ($this->current_leaf->parent === null) {
                break;
            }
            $this->current_leaf = $this->current_leaf->parent;
        } while (!$this->current_leaf instanceof Encapsulation_Tree && !$this->current_leaf instanceof Callable_Tree && !$this->current_leaf instanceof Method_Tree);
        if ($this->current_leaf instanceof Encapsulation_Tree || $this->current_leaf instanceof Callable_Tree) {
            $this->current_leaf->terminated = true;
        }
    }
    private function handle_comma(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected token ,');
        }
        if (!$this->current_leaf->parent) {
            throw new Type_Parse_Tree_Exception('Cannot parse comma without a parent node');
        }
        $context_node = $this->current_leaf;
        if ($context_node instanceof Generic_Tree || $context_node instanceof Keyed_Array_Tree || $context_node instanceof Callable_Tree || $context_node instanceof Method_Tree) {
            $context_node = $context_node->parent;
        }
        while ($context_node && !$context_node instanceof Generic_Tree && !$context_node instanceof Keyed_Array_Tree && !$context_node instanceof Callable_Tree && !$context_node instanceof Method_Tree) {
            $context_node = $context_node->parent;
        }
        if (!$context_node) {
            throw new Type_Parse_Tree_Exception('Cannot parse comma in non-generic/array type');
        }
        $this->current_leaf = $context_node;
    }
    /** @param array{0: string, 1: int, 2?: string} $type_token */
    private function handle_ellipsis_or_equals(array $type_token): void
    {
        $prev_token = $this->t > 0 ? $this->type_tokens[$this->t - 1] : null;
        if ($prev_token && ($prev_token[0] === '...' || $prev_token[0] === '=')) {
            throw new Type_Parse_Tree_Exception('Cannot have duplicate tokens');
        }
        $current_parent = $this->current_leaf->parent;
        if ($this->current_leaf instanceof Method_Tree && $type_token[0] === '...') {
            $this->create_method_param($type_token, $this->current_leaf);
            return;
        }
        if ($this->current_leaf instanceof Keyed_Array_Tree && $type_token[0] === '...') {
            $leaf = new Field_Ellipsis($this->current_leaf);
            $this->current_leaf->children[] = $leaf;
            $this->current_leaf = $leaf;
            return;
        }
        while ($current_parent && !$current_parent instanceof Callable_Tree && !$current_parent instanceof Callable_Param_Tree) {
            $this->current_leaf = $current_parent;
            $current_parent = $current_parent->parent;
        }
        if (!$current_parent) {
            if ($type_token[0] === '...') {
                if ($this->current_leaf instanceof Callable_Tree) {
                    $current_parent = $this->current_leaf;
                } else {
                    throw new Type_Parse_Tree_Exception('Unexpected token ' . $type_token[0]);
                }
            } else {
                throw new Type_Parse_Tree_Exception('Unexpected token ' . $type_token[0]);
            }
        }
        if ($current_parent instanceof Callable_Param_Tree) {
            throw new Type_Parse_Tree_Exception('Cannot have variadic param with a default');
        }
        $new_leaf = new Callable_Param_Tree($current_parent);
        $new_leaf->has_default = $type_token[0] === '=';
        $new_leaf->variadic = $type_token[0] === '...';
        if ($current_parent !== $this->current_leaf) {
            $new_leaf->children = [$this->current_leaf];
            array_pop($current_parent->children);
        }
        $current_parent->children[] = $new_leaf;
        $this->current_leaf = $new_leaf;
    }
    private function handle_colon(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected token :');
        }
        $current_parent = $this->current_leaf->parent;
        if ($this->current_leaf instanceof Callable_Tree) {
            $new_parent_leaf = new Callable_With_Return_Type_Tree($current_parent);
            $this->current_leaf->parent = $new_parent_leaf;
            $new_parent_leaf->children = [$this->current_leaf];
            if ($current_parent) {
                array_pop($current_parent->children);
                $current_parent->children[] = $new_parent_leaf;
            } else {
                $this->parse_tree = $new_parent_leaf;
            }
            $this->current_leaf = $new_parent_leaf;
            return;
        }
        if ($this->current_leaf instanceof Method_Tree) {
            $new_parent_leaf = new Method_With_Return_Type_Tree($current_parent);
            $this->current_leaf->parent = $new_parent_leaf;
            $new_parent_leaf->children = [$this->current_leaf];
            if ($current_parent) {
                array_pop($current_parent->children);
                $current_parent->children[] = $new_parent_leaf;
            } else {
                $this->parse_tree = $new_parent_leaf;
            }
            $this->current_leaf = $new_parent_leaf;
            return;
        }
        if ($current_parent instanceof Keyed_Array_Property_Tree) {
            return;
        }
        while (($current_parent instanceof Union_Tree || $current_parent instanceof Callable_With_Return_Type_Tree) && $this->current_leaf->parent) {
            $this->current_leaf = $this->current_leaf->parent;
            $current_parent = $this->current_leaf->parent;
        }
        if ($current_parent instanceof Conditional_Tree) {
            if (count($current_parent->children) > 1) {
                throw new Type_Parse_Tree_Exception('Cannot process colon in conditional twice');
            }
            $this->current_leaf = $current_parent;
            return;
        }
        if (!$current_parent) {
            throw new Type_Parse_Tree_Exception('Cannot process colon without parent');
        }
        if (!$this->current_leaf instanceof Value) {
            throw new Type_Parse_Tree_Exception('Unexpected LHS of property');
        }
        if (!$current_parent instanceof Keyed_Array_Tree) {
            throw new Type_Parse_Tree_Exception('Saw : outside of object-like array');
        }
        $prev_token = $this->t > 0 ? $this->type_tokens[$this->t - 1] : null;
        $new_parent_leaf = new Keyed_Array_Property_Tree($this->current_leaf->value, $current_parent);
        $new_parent_leaf->possibly_undefined = $prev_token !== null && $prev_token[0] === '?';
        array_pop($current_parent->children);
        $current_parent->children[] = $new_parent_leaf;
        $this->current_leaf = $new_parent_leaf;
    }
    private function handle_space(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected space');
        }
        if ($this->current_leaf instanceof Keyed_Array_Tree) {
            return;
        }
        $current_parent = $this->current_leaf->parent;
        //while ($current_parent && !$method_or_callable_parent) {
        while ($current_parent && !$current_parent instanceof Method_Tree && !$current_parent instanceof Callable_Tree) {
            $this->current_leaf = $current_parent;
            $current_parent = $current_parent->parent;
        }
        $next_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
        if (!($current_parent instanceof Method_Tree || $current_parent instanceof Callable_Tree) || !$next_token) {
            throw new Type_Parse_Tree_Exception('Unexpected space');
        }
        if ($current_parent instanceof Method_Tree) {
            ++$this->t;
            $this->create_method_param($next_token, $current_parent);
        }
        if ($current_parent instanceof Callable_Tree) {
            ++$this->t;
            $this->parse_callable_param($next_token, $current_parent);
        }
    }
    private function handle_question_mark(): void
    {
        $next_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
        if ($next_token === null || $next_token[0] !== ':') {
            while (($this->current_leaf instanceof Value || $this->current_leaf instanceof Union_Tree || $this->current_leaf instanceof Keyed_Array_Tree && $this->current_leaf->terminated || $this->current_leaf instanceof Generic_Tree && $this->current_leaf->terminated || $this->current_leaf instanceof Encapsulation_Tree && $this->current_leaf->terminated || $this->current_leaf instanceof Callable_Tree && $this->current_leaf->terminated || $this->current_leaf instanceof Intersection_Tree) && $this->current_leaf->parent) {
                $this->current_leaf = $this->current_leaf->parent;
            }
            if ($this->current_leaf instanceof Template_Is_Tree && $this->current_leaf->parent) {
                $current_parent = $this->current_leaf->parent;
                $new_leaf = new Conditional_Tree($this->current_leaf, $this->current_leaf->parent);
                array_pop($current_parent->children);
                $current_parent->children[] = $new_leaf;
                $this->current_leaf = $new_leaf;
            } else {
                $new_parent = !$this->current_leaf instanceof Root ? $this->current_leaf : null;
                if (!$next_token) {
                    throw new Type_Parse_Tree_Exception('Unexpected token ?');
                }
                $new_leaf = new Nullable_Tree($new_parent);
                if ($this->current_leaf instanceof Root) {
                    $this->current_leaf = $this->parse_tree = $new_leaf;
                    return;
                }
                if ($new_leaf->parent) {
                    $new_leaf->parent->children[] = $new_leaf;
                }
                $this->current_leaf = $new_leaf;
            }
        }
    }
    private function handle_bar(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected token |');
        }
        $current_parent = $this->current_leaf->parent;
        if ($current_parent instanceof Callable_With_Return_Type_Tree) {
            $this->current_leaf = $current_parent;
            $current_parent = $current_parent->parent;
        }
        if ($current_parent instanceof Nullable_Tree) {
            $this->current_leaf = $current_parent;
            $current_parent = $current_parent->parent;
        }
        if ($this->current_leaf instanceof Union_Tree) {
            throw new Type_Parse_Tree_Exception('Unexpected token |');
        }
        if ($current_parent instanceof Union_Tree) {
            $this->current_leaf = $current_parent;
            return;
        }
        if ($current_parent instanceof Intersection_Tree) {
            $this->current_leaf = $current_parent;
            $current_parent = $this->current_leaf->parent;
        }
        if ($current_parent instanceof Template_Is_Tree) {
            $new_parent_leaf = new Union_Tree($this->current_leaf);
            $new_parent_leaf->children = [$this->current_leaf];
            $new_parent_leaf->parent = $current_parent;
        } else {
            $new_parent_leaf = new Union_Tree($current_parent);
            $new_parent_leaf->children = [$this->current_leaf];
        }
        if ($current_parent) {
            array_pop($current_parent->children);
            $current_parent->children[] = $new_parent_leaf;
        } else {
            $this->parse_tree = $new_parent_leaf;
        }
        $this->current_leaf = $new_parent_leaf;
    }
    private function handle_ampersand(): void
    {
        if ($this->current_leaf instanceof Root) {
            throw new Type_Parse_Tree_Exception('Unexpected &');
        }
        $current_parent = $this->current_leaf->parent;
        if ($current_parent instanceof Method_Tree) {
            $this->create_method_param($this->type_tokens[$this->t], $current_parent);
            return;
        }
        if ($current_parent instanceof Intersection_Tree) {
            $this->current_leaf = $current_parent;
            return;
        }
        $new_parent_leaf = new Intersection_Tree($current_parent);
        $new_parent_leaf->children = [$this->current_leaf];
        if ($current_parent) {
            array_pop($current_parent->children);
            $current_parent->children[] = $new_parent_leaf;
        } else {
            $this->parse_tree = $new_parent_leaf;
        }
        $this->current_leaf = $new_parent_leaf;
    }
    /** @param array{0: string, 1: int, 2?: string} $type_token */
    private function handle_is_or_as(array $type_token): void
    {
        if ($this->t === 0) {
            $this->handle_value($type_token);
        } else {
            $current_parent = $this->current_leaf->parent;
            if ($current_parent) {
                array_pop($current_parent->children);
            }
            if ($type_token[0] === 'as' || $type_token[0] == 'of') {
                $next_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
                if (!$this->current_leaf instanceof Value || !$current_parent instanceof Generic_Tree || !$next_token) {
                    throw new Type_Parse_Tree_Exception('Unexpected token ' . $type_token[0]);
                }
                $this->current_leaf = new Template_As_Tree($this->current_leaf->value, $next_token[0], $current_parent);
                $current_parent->children[] = $this->current_leaf;
                ++$this->t;
            } elseif ($this->current_leaf instanceof Value) {
                $this->current_leaf = new Template_Is_Tree($this->current_leaf->value, $current_parent);
                if ($current_parent) {
                    $current_parent->children[] = $this->current_leaf;
                }
            }
        }
    }
    /** @param array{0: string, 1: int, 2?: string} $type_token */
    private function handle_value(array $type_token): void
    {
        $new_parent = !$this->current_leaf instanceof Root ? $this->current_leaf : null;
        if ($this->current_leaf instanceof Method_Tree && $type_token[0][0] === '$') {
            $this->create_method_param($type_token, $this->current_leaf);
            return;
        }
        $next_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
        switch ($next_token[0] ?? null) {
            case '<':
                $new_leaf = new Generic_Tree($type_token[0], $new_parent);
                ++$this->t;
                break;
            case '{':
                ++$this->t;
                $nexter_token = $this->t + 1 < $this->type_token_count ? $this->type_tokens[$this->t + 1] : null;
                if ($nexter_token && str_contains($nexter_token[0], '@') && $type_token[0] !== 'list' && $type_token[0] !== 'array') {
                    $this->t = $this->type_token_count;
                    if ($type_token[0] === '$this') {
                        $type_token[0] = 'static';
                    }
                    $new_leaf = new Value($type_token[0], $type_token[1], $type_token[1] + strlen($type_token[0]), $type_token[2] ?? null, $new_parent);
                    break;
                }
                $new_leaf = new Keyed_Array_Tree($type_token[0], $new_parent);
                if ($nexter_token !== null && $nexter_token[0] === '}') {
                    $new_leaf->terminated = true;
                    ++$this->t;
                } elseif ($nexter_token === null) {
                    throw new Type_Parse_Tree_Exception('Unclosed bracket in keyed array');
                }
                break;
            case '(':
                if (in_array($type_token[0], ['callable', 'pure-callable', 'Closure', '\Closure', 'pure-Closure'], true)) {
                    $new_leaf = new Callable_Tree($type_token[0], $new_parent);
                } elseif ($type_token[0][0] !== '\\' && $this->current_leaf instanceof Root) {
                    $new_leaf = new Method_Tree($type_token[0], $new_parent);
                } else {
                    throw new Type_Parse_Tree_Exception('Parenthesis must be preceded by “Closure”, “callable”, "pure-callable" or a valid @method' . ' name');
                }
                ++$this->t;
                break;
            case '::':
                $nexter_token = $this->t + 2 < $this->type_token_count ? $this->type_tokens[$this->t + 2] : null;
                if (!$nexter_token || !preg_match('/^([a-zA-Z_][a-zA-Z_0-9]*\*?|\*)$/', $nexter_token[0]) && strtolower($nexter_token[0]) !== 'class') {
                    throw new Type_Parse_Tree_Exception('Invalid class constant ' . ($nexter_token[0] ?? '<empty>'));
                }
                $new_leaf = new Value($type_token[0] . '::' . $nexter_token[0], $type_token[1], $type_token[1] + 2 + strlen($nexter_token[0]), $type_token[2] ?? null, $new_parent);
                $this->t += 2;
                break;
            default:
                if ($type_token[0] === '$this') {
                    $type_token[0] = 'static';
                }
                $new_leaf = new Value($type_token[0], $type_token[1], $type_token[1] + strlen($type_token[0]), $type_token[2] ?? null, $new_parent);
                break;
        }
        if ($this->current_leaf instanceof Root) {
            $this->current_leaf = $this->parse_tree = $new_leaf;
            return;
        }
        if ($new_leaf->parent) {
            $new_leaf->parent->children[] = $new_leaf;
        }
        $this->current_leaf = $new_leaf;
    }
}