<?php

declare (strict_types=1);
namespace Psalm\Internal\Php_Visitor\Reflector;

use LogicException;
use Php_Parser;
use Php_Parser\Modifiers;
use Php_Parser\Node\Identifier;
use Php_Parser\Node\Intersection_Type;
use Php_Parser\Node\Name;
use Php_Parser\Node\Nullable_Type;
use Php_Parser\Node\Union_Type;
use Psalm\Aliases;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Exception\Complicated_Expression_Exception;
use Psalm\Exception\Docblock_Parse_Exception;
use Psalm\Exception\Incorrect_Docblock_Exception;
use Psalm\Internal\Algebra;
use Psalm\Internal\Algebra\Formula_Generator;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Comment_Analyzer;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Analyzer\Scope_Analyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Simple_Type_Inferer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Internal\Scanner\File_Scanner;
use Psalm\Internal\Type\Type_Alias;
use Psalm\Issue\Duplicate_Function;
use Psalm\Issue\Duplicate_Method;
use Psalm\Issue\Duplicate_Param;
use Psalm\Issue\Invalid_Docblock;
use Psalm\Issue\Missing_Docblock_Type;
use Psalm\Issue\ParseError;
use Psalm\Issue\Private_Final_Method;
use Psalm\Issue_Buffer;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Storage\File_Storage;
use Psalm\Storage\Function_Like_Parameter;
use Psalm\Storage\Function_Like_Storage;
use Psalm\Storage\Function_Storage;
use Psalm\Storage\Method_Storage;
use Psalm\Storage\Possibilities;
use Psalm\Storage\Property_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Null;
use Psalm\Type\Union;
use ReflectionFunction;
use UnexpectedValueException;
use function array_keys;
use function array_pop;
use function array_search;
use function count;
use function end;
use function explode;
use function in_array;
use function is_string;
use function spl_object_id;
use function str_contains;
use function str_starts_with;
use function strtolower;
/**
 * @internal
 */
final class Function_Like_Node_Scanner
{
    private readonly string $file_path;
    private readonly Config $config;
    public ?Function_Like_Storage $storage = null;
    /**
     * @param array<string, non-empty-array<string, Union>> $existing_function_template_types
     * @param array<string, TypeAlias> $type_aliases
     */
    public function __construct(private readonly Codebase $codebase, private readonly File_Scanner $file_scanner, private readonly File_Storage $file_storage, private readonly Aliases $aliases, private readonly array $type_aliases, private readonly ?Class_Like_Storage $classlike_storage, private readonly array $existing_function_template_types)
    {
        $this->file_path = $file_storage->file_path;
        $this->config = Config::get_instance();
    }
    /**
     * @param  bool $fake_method in the case of @method annotations we do something a little strange
     */
    public function start(Php_Parser\Node\Function_Like $stmt, bool $fake_method = false, ?Php_Parser\Comment\Doc $doc_comment = null): Function_Storage|Method_Storage|false
    {
        if ($stmt instanceof Php_Parser\Node\Expr\Closure || $stmt instanceof Php_Parser\Node\Expr\Arrow_Function) {
            $this->codebase->scanner->queue_class_like_for_scanning('Closure');
        }
        $functionlike_info = $this->create_storage_for_function_like($stmt, $fake_method);
        if ($functionlike_info === false) {
            return false;
        }
        [$cased_function_id, $storage, $function_id, $fq_classlike_name, $method_name_lc, $classlike_storage, $is_functionlike_override, $method_id, $is_dupe] = $functionlike_info;
        if ($is_dupe) {
            return $storage;
        }
        if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
            $storage->cased_name = $stmt->name->name;
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Function_) {
            $storage->cased_name = ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $stmt->name->name;
        }
        if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method || $stmt instanceof Php_Parser\Node\Stmt\Function_) {
            $storage->location = new Code_Location($this->file_scanner, $stmt->name, null, true);
        } else {
            $storage->location = new Code_Location($this->file_scanner, $stmt, null, true);
        }
        $storage->stmt_location = new Code_Location($this->file_scanner, $stmt);
        $required_param_count = 0;
        $i = 0;
        $has_optional_param = false;
        $existing_params = [];
        $storage->set_params([]);
        foreach ($stmt->get_params() as $param) {
            if ($param->var instanceof Php_Parser\Node\Expr\Error) {
                $storage->docblock_issues[] = new Invalid_Docblock('Param' . ($i + 1) . ' of ' . $cased_function_id . ' has invalid syntax', new Code_Location($this->file_scanner, $param, null, true));
                ++$i;
                continue;
            }
            $param_storage = $this->get_translated_function_param($param, $stmt, $fake_method, $fq_classlike_name);
            foreach ($param->attr_groups as $attr_group) {
                foreach ($attr_group->attrs as $attr) {
                    $param_storage->attributes[] = Attribute_Resolver::resolve($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $attr, $this->classlike_storage->name ?? null);
                }
            }
            if ($param_storage->name === 'haystack' && in_array($this->file_path, $this->codebase->config->internal_stubs)) {
                $param_storage->expect_variable = true;
            }
            if (isset($existing_params['$' . $param_storage->name])) {
                $storage->docblock_issues[] = new Duplicate_Param('Duplicate param $' . $param_storage->name . ' in docblock for ' . $cased_function_id, new Code_Location($this->file_scanner, $param, null, true));
                ++$i;
                continue;
            }
            $existing_params['$' . $param_storage->name] = $i;
            $storage->add_param($param_storage, (bool) $param->type);
            if (!$param_storage->is_optional && !$param_storage->is_variadic) {
                $required_param_count = $i + 1;
                if (!$param->variadic && $has_optional_param) {
                    foreach ($storage->params as $param) {
                        $param->is_optional = false;
                    }
                }
            } else {
                $has_optional_param = true;
            }
            ++$i;
        }
        $storage->required_param_count = $required_param_count;
        if ($stmt instanceof Php_Parser\Node\Stmt\Function_ || $stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
            /** @psalm-suppress RedundantCondition See https://github.com/vimeo/psalm/issues/10296 */
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && $storage instanceof Method_Storage && $classlike_storage && !$classlike_storage->mutation_free && $stmt->stmts && count($stmt->stmts) === 1 && !count($stmt->params) && $stmt->stmts[0] instanceof Php_Parser\Node\Stmt\Return_ && $stmt->stmts[0]->expr instanceof Php_Parser\Node\Expr\Property_Fetch && $stmt->stmts[0]->expr->var instanceof Php_Parser\Node\Expr\Variable && $stmt->stmts[0]->expr->var->name === 'this' && $stmt->stmts[0]->expr->name instanceof Php_Parser\Node\Identifier) {
                $property_name = $stmt->stmts[0]->expr->name->name;
                if (isset($classlike_storage->properties[$property_name]) && $classlike_storage->properties[$property_name]->type) {
                    $storage->mutation_free = true;
                    $storage->external_mutation_free = true;
                    $storage->mutation_free_inferred = !$stmt->is_final() && !$classlike_storage->final;
                    $classlike_storage->properties[$property_name]->getter_method = strtolower($stmt->name->name);
                }
            } elseif (str_starts_with($stmt->name->name, 'assert') && $stmt->stmts) {
                $var_assertions = [];
                foreach ($stmt->stmts as $function_stmt) {
                    if ($function_stmt instanceof Php_Parser\Node\Stmt\If_) {
                        $final_actions = Scope_Analyzer::get_control_actions($function_stmt->stmts, null, [], false);
                        if ($final_actions !== [Scope_Analyzer::ACTION_END]) {
                            $var_assertions = [];
                            break;
                        }
                        $cond_id = spl_object_id($function_stmt->cond);
                        $if_clauses = Formula_Generator::get_formula($cond_id, $cond_id, $function_stmt->cond, $this->classlike_storage->name ?? null, $this->file_scanner);
                        try {
                            $negated_formula = Algebra::negate_formula($if_clauses);
                        } catch (Complicated_Expression_Exception) {
                            $var_assertions = [];
                            break;
                        }
                        $rules = Algebra::get_truths_from_formula($negated_formula);
                        if (!$rules) {
                            $var_assertions = [];
                            break;
                        }
                        foreach ($rules as $var_id => $rule) {
                            foreach ($rule as $rule_part) {
                                if (count($rule_part) > 1) {
                                    $var_assertions = [];
                                    continue 2;
                                }
                                if (isset($existing_params[$var_id])) {
                                    $param_offset = $existing_params[$var_id];
                                    $var_assertions[] = new Possibilities($param_offset, $rule_part);
                                } elseif (str_starts_with($var_id, '$this->')) {
                                    $var_assertions[] = new Possibilities($var_id, $rule_part);
                                }
                            }
                        }
                    } else {
                        $var_assertions = [];
                        break;
                    }
                }
                $storage->assertions = $var_assertions;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && $stmt->stmts && $storage instanceof Method_Storage) {
                $last_stmt = end($stmt->stmts);
                if ($last_stmt instanceof Php_Parser\Node\Stmt\Return_ && $last_stmt->expr instanceof Php_Parser\Node\Expr\Variable && $last_stmt->expr->name === 'this') {
                    $storage->probably_fluent = true;
                }
            }
        }
        if (!$this->file_scanner->will_analyze && ($stmt instanceof Php_Parser\Node\Stmt\Function_ || $stmt instanceof Php_Parser\Node\Stmt\Class_Method || $stmt instanceof Php_Parser\Node\Expr\Closure) && $stmt->stmts) {
            // pick up func_get_args that would otherwise be missed
            foreach ($stmt->stmts as $function_stmt) {
                if ($function_stmt instanceof Php_Parser\Node\Stmt\Expression && $function_stmt->expr instanceof Php_Parser\Node\Expr\Assign && $function_stmt->expr->expr instanceof Php_Parser\Node\Expr\Func_Call && $function_stmt->expr->expr->name instanceof Php_Parser\Node\Name) {
                    $inner_function_id = $function_stmt->expr->expr->name->to_string();
                    if ($inner_function_id === 'func_get_arg' || $inner_function_id === 'func_get_args' || $inner_function_id === 'func_num_args') {
                        $storage->variadic = true;
                    }
                } elseif ($function_stmt instanceof Php_Parser\Node\Stmt\If_ && $function_stmt->cond instanceof Php_Parser\Node\Expr\Binary_Op && $function_stmt->cond->left instanceof Php_Parser\Node\Expr\Binary_Op\Equal && $function_stmt->cond->left->left instanceof Php_Parser\Node\Expr\Func_Call && $function_stmt->cond->left->left->name instanceof Php_Parser\Node\Name) {
                    $inner_function_id = $function_stmt->cond->left->left->name->to_string();
                    if ($inner_function_id === 'func_get_arg' || $inner_function_id === 'func_get_args' || $inner_function_id === 'func_num_args') {
                        $storage->variadic = true;
                    }
                }
            }
        }
        $parser_return_type = $stmt->get_return_type();
        if ($parser_return_type) {
            $original_type = $parser_return_type;
            /** @var Identifier|IntersectionType|Name|NullableType|UnionType $original_type */
            $storage->return_type = Type_Hint_Resolver::resolve($original_type, new Code_Location($this->file_scanner, $original_type), $this->codebase, $this->file_storage, $this->classlike_storage, $this->aliases, $this->codebase->analysis_php_version_id);
            $storage->return_type_location = new Code_Location($this->file_scanner, $original_type);
            if ($stmt->returns_by_ref()) {
                /** @psalm-suppress InaccessibleProperty We just created this type */
                $storage->return_type->by_ref = true;
            }
            $storage->signature_return_type = $storage->return_type;
            $storage->signature_return_type_location = $storage->return_type_location;
        }
        if ($stmt->returns_by_ref()) {
            $storage->returns_by_ref = true;
        }
        $doc_comment = $stmt->get_doc_comment() ?? $doc_comment;
        if ($classlike_storage && !$classlike_storage->is_trait) {
            $storage->internal = [...$classlike_storage->internal, ...$storage->internal];
        }
        if ($doc_comment) {
            try {
                $code_location = new Code_Location($this->file_scanner, $stmt, null, true);
                $docblock_info = Function_Like_Docblock_Parser::parse($doc_comment, $code_location, $cased_function_id);
            } catch (Incorrect_Docblock_Exception $e) {
                $storage->docblock_issues[] = new Missing_Docblock_Type($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($this->file_scanner, $stmt, null, true));
                $docblock_info = null;
            } catch (Docblock_Parse_Exception $e) {
                $storage->docblock_issues[] = new Invalid_Docblock($e->get_message() . ' in docblock for ' . $cased_function_id, new Code_Location($this->file_scanner, $stmt, null, true));
                $docblock_info = null;
            }
            if ($docblock_info) {
                if ($docblock_info->since_php_major_version && !$this->aliases->namespace) {
                    $analysis_major_php_version = $this->codebase->get_major_analysis_php_version();
                    $analysis_minor_php_version = $this->codebase->get_minor_analysis_php_version();
                    if ($docblock_info->since_php_major_version > $analysis_major_php_version) {
                        return false;
                    }
                    if ($docblock_info->since_php_major_version === $analysis_major_php_version && $docblock_info->since_php_minor_version > $analysis_minor_php_version) {
                        return false;
                    }
                }
                if ($stmt instanceof Php_Parser\Node\Expr\Closure || $stmt instanceof Php_Parser\Node\Expr\Arrow_Function) {
                    if ($docblock_info->templates !== []) {
                        $docblock_info->templates = [];
                        $storage->docblock_issues[] = new Invalid_Docblock('Templated closures are not supported', new Code_Location($this->file_scanner, $stmt, null, true));
                    }
                }
                Function_Like_Docblock_Scanner::add_docblock_info($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $this->type_aliases, $this->classlike_storage, $this->existing_function_template_types, $storage, $stmt, $docblock_info, $is_functionlike_override, $fake_method, $cased_function_id);
            }
        }
        // register the functionlike once the @since check has been completed
        if ($stmt instanceof Php_Parser\Node\Stmt\Function_ && $function_id && $storage instanceof Function_Storage) {
            if ($this->codebase->all_functions_global || $this->codebase->register_stub_files || $this->codebase->register_autoload_files && !$this->codebase->functions->has_stubbed_function($function_id)) {
                $this->codebase->functions->add_global_function($function_id, $storage);
            }
            $this->file_storage->functions[$function_id] = $storage;
            $this->file_storage->declaring_function_ids[$function_id] = strtolower($this->file_path);
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && $classlike_storage && $storage instanceof Method_Storage && $method_name_lc && !$fake_method && $method_id) {
            $classlike_storage->methods[$method_name_lc] = $storage;
            $classlike_storage->declaring_method_ids[$method_name_lc] = $classlike_storage->appearing_method_ids[$method_name_lc] = $method_id;
            if (!$stmt->is_private() || $method_name_lc === '__construct' || $method_name_lc === '__clone' || $classlike_storage->is_trait) {
                $classlike_storage->inheritable_method_ids[$method_name_lc] = $method_id;
            }
            if (!isset($classlike_storage->overridden_method_ids[$method_name_lc])) {
                $classlike_storage->overridden_method_ids[$method_name_lc] = [];
            }
            if ($storage->final && $method_name_lc === '__construct') {
                // a bit of a hack, but makes sure that `new static` works for these classes
                $classlike_storage->preserve_constructor_signature = true;
            }
        } elseif (($stmt instanceof Php_Parser\Node\Expr\Closure || $stmt instanceof Php_Parser\Node\Expr\Arrow_Function) && $function_id && $storage instanceof Function_Storage) {
            $this->file_storage->functions[$function_id] = $storage;
        }
        if ($classlike_storage && $method_name_lc === '__construct') {
            foreach ($stmt->get_params() as $param) {
                if (!$param->flags) {
                    continue;
                }
                if (!$param->var instanceof Php_Parser\Node\Expr\Variable) {
                    continue;
                }
                $param_storage = null;
                foreach ($storage->params as $param_storage) {
                    if ($param_storage->name === $param->var->name) {
                        break;
                    }
                }
                if (!$param_storage) {
                    continue;
                }
                if (isset($classlike_storage->properties[$param_storage->name]) && $param_storage->location) {
                    Issue_Buffer::maybe_add(new ParseError('Promoted property ' . $param_storage->name . ' clashes with an existing property', $param_storage->location));
                    $storage->has_visitor_issues = true;
                    $this->file_storage->has_visitor_issues = true;
                    continue;
                }
                $doc_comment = $param->get_doc_comment();
                $var_comment_type = null;
                $var_comment_readonly = false;
                $var_comment_allow_private_mutation = false;
                if ($doc_comment) {
                    $template_types = ($this->existing_function_template_types ?: []) + ($classlike_storage->template_types ?: []);
                    $var_comments = Comment_Analyzer::get_type_from_comment($doc_comment, $this->file_scanner, $this->aliases, $template_types, $this->type_aliases);
                    $var_comment = array_pop($var_comments);
                    if ($var_comment !== null) {
                        $var_comment_type = $var_comment->type;
                        $var_comment_readonly = $var_comment->readonly;
                        $var_comment_allow_private_mutation = $var_comment->allow_private_mutation;
                    }
                }
                //both way to document type were used
                if ($param_storage->type && $param_storage->type->from_docblock && $var_comment_type) {
                    if (Issue_Buffer::accepts(new Invalid_Docblock('Param ' . $param_storage->name . ' of ' . $cased_function_id . ' should be documented as a param or a property, not both', new Code_Location($this->file_scanner, $param, null, true)))) {
                        return false;
                    }
                }
                //no docblock type was provided for param but we have one for property
                if ($var_comment_type) {
                    $param_storage->type = $var_comment_type;
                }
                $property_storage = $classlike_storage->properties[$param_storage->name] = new Property_Storage();
                $property_storage->is_static = false;
                $property_storage->type = $param_storage->type;
                $property_storage->signature_type = $param_storage->signature_type;
                $property_storage->signature_type_location = $param_storage->signature_type_location;
                $property_storage->type_location = $param_storage->type_location;
                $property_storage->location = $param_storage->location;
                $property_storage->stmt_location = new Code_Location($this->file_scanner, $param);
                $property_storage->has_default = (bool) $param->default;
                $param_type_readonly = (bool) ($param->flags & Php_Parser\Modifiers::READONLY);
                $property_storage->readonly = $param_type_readonly ?: $var_comment_readonly;
                $property_storage->allow_private_mutation = $var_comment_allow_private_mutation;
                $param_storage->promoted_property = true;
                $property_storage->is_promoted = true;
                $property_id = $fq_classlike_name . '::$' . $param_storage->name;
                switch ($param->flags & Modifiers::VISIBILITY_MASK) {
                    case Modifiers::PUBLIC:
                        $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
                        $classlike_storage->inheritable_property_ids[$param_storage->name] = $property_id;
                        break;
                    case Modifiers::PROTECTED:
                        $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PROTECTED;
                        $classlike_storage->inheritable_property_ids[$param_storage->name] = $property_id;
                        break;
                    case Modifiers::PRIVATE:
                        $property_storage->visibility = Class_Like_Analyzer::VISIBILITY_PRIVATE;
                        break;
                }
                $fq_classlike_name = $classlike_storage->name;
                $property_id = $fq_classlike_name . '::$' . $param_storage->name;
                $classlike_storage->declaring_property_ids[$param_storage->name] = $fq_classlike_name;
                $classlike_storage->appearing_property_ids[$param_storage->name] = $property_id;
                $classlike_storage->initialized_properties[$param_storage->name] = true;
            }
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Method && $storage instanceof Method_Storage && $storage->params && $this->config->infer_property_types_from_constructor) {
                $this->infer_property_type_from_constructor($stmt, $storage, $classlike_storage);
            }
        }
        foreach ($stmt->get_attr_groups() as $attr_group) {
            foreach ($attr_group->attrs as $attr) {
                $attribute = Attribute_Resolver::resolve($this->codebase, $this->file_scanner, $this->file_storage, $this->aliases, $attr, $this->classlike_storage->name ?? null);
                if ($attribute->fq_class_name === 'Psalm\Pure' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Pure') {
                    $storage->specialize_call = true;
                    $storage->mutation_free = true;
                    if ($storage instanceof Method_Storage) {
                        $storage->external_mutation_free = true;
                    }
                }
                if ($attribute->fq_class_name === 'Psalm\Deprecated' || $attribute->fq_class_name === 'Deprecated' || $attribute->fq_class_name === 'JetBrains\PhpStorm\Deprecated') {
                    $storage->deprecated = true;
                }
                if ($attribute->fq_class_name === 'Psalm\Internal' && !$storage->internal && $fq_classlike_name) {
                    $storage->internal = [Namespace_Analyzer::get_name_space_root($fq_classlike_name)];
                }
                if ($attribute->fq_class_name === 'Psalm\ExternalMutationFree' && $storage instanceof Method_Storage) {
                    $storage->external_mutation_free = true;
                }
                if ($attribute->fq_class_name === 'JetBrains\PhpStorm\NoReturn') {
                    $storage->return_type = Type::get_never();
                }
                $storage->attributes[] = $attribute;
            }
        }
        return $storage;
    }
    private function infer_property_type_from_constructor(Php_Parser\Node\Stmt\Class_Method $stmt, Method_Storage $storage, Class_Like_Storage $classlike_storage): void
    {
        if (!$stmt->stmts) {
            return;
        }
        $assigned_properties = [];
        foreach ($stmt->stmts as $function_stmt) {
            if ($function_stmt instanceof Php_Parser\Node\Stmt\Expression && $function_stmt->expr instanceof Php_Parser\Node\Expr\Assign && $function_stmt->expr->var instanceof Php_Parser\Node\Expr\Property_Fetch && $function_stmt->expr->var->var instanceof Php_Parser\Node\Expr\Variable && $function_stmt->expr->var->var->name === 'this' && $function_stmt->expr->var->name instanceof Php_Parser\Node\Identifier && ($property_name = $function_stmt->expr->var->name->name) && isset($classlike_storage->properties[$property_name]) && $function_stmt->expr->expr instanceof Php_Parser\Node\Expr\Variable && is_string($function_stmt->expr->expr->name) && ($param_name = $function_stmt->expr->expr->name) && isset($storage->param_lookup[$param_name])) {
                if ($classlike_storage->properties[$property_name]->type) {
                    continue;
                }
                if (!$storage->param_lookup[$param_name]) {
                    continue;
                }
                $param_index = array_search($param_name, array_keys($storage->param_lookup), true);
                if ($param_index === false) {
                    continue;
                }
                if (!isset($storage->params[$param_index]->type)) {
                    continue;
                }
                $param_type = $storage->params[$param_index]->type;
                $assigned_properties[$property_name] = $storage->params[$param_index]->is_variadic ? new Union([new T_Array([Type::get_int(), $param_type])]) : $param_type;
            } else {
                $assigned_properties = [];
                break;
            }
        }
        if (!$assigned_properties) {
            return;
        }
        $storage->external_mutation_free = true;
        $storage->mutation_free_inferred = true;
        foreach ($assigned_properties as $property_name => $property_type) {
            $classlike_storage->properties[$property_name]->type = $property_type;
        }
    }
    private function get_translated_function_param(Php_Parser\Node\Param $param, Php_Parser\Node\Function_Like $stmt, bool $fake_method, ?string $fq_classlike_name): Function_Like_Parameter
    {
        $param_type = null;
        $is_nullable = $param->default instanceof Php_Parser\Node\Expr\Const_Fetch && strtolower($param->default->name->get_first()) === 'null';
        $param_typehint = $param->type;
        if ($param_typehint) {
            /** @var Identifier|IntersectionType|Name|NullableType|UnionType $param_typehint */
            $param_type = Type_Hint_Resolver::resolve($param_typehint, new Code_Location($this->file_scanner, $param_typehint), $this->codebase, $this->file_storage, $this->classlike_storage, $this->aliases, $this->codebase->analysis_php_version_id);
            if ($param_type->is_mixed()) {
                $is_nullable = false;
            } elseif ($is_nullable) {
                $param_type = $param_type->get_builder()->add_type(new T_Null())->freeze();
            } else {
                $is_nullable = $param_type->is_nullable();
            }
        }
        $is_optional = $param->default !== null;
        if ($param->var instanceof Php_Parser\Node\Expr\Error || !is_string($param->var->name)) {
            throw new UnexpectedValueException('Not expecting param name to be non-string');
        }
        $default_type = null;
        if ($param->default) {
            $default_type = Simple_Type_Inferer::infer($this->codebase, new Node_Data_Provider(), $param->default, $this->aliases, null, null, $fq_classlike_name);
            if (!$default_type) {
                $default_type = Expression_Resolver::get_unresolved_class_const_expr($param->default, $this->aliases, $fq_classlike_name);
            }
        }
        return new Function_Like_Parameter($param->var->name, $param->by_ref, $param_type, $param_type, new Code_Location($this->file_scanner, $fake_method ? $stmt : $param->var, null, false, !$fake_method ? Code_Location::FUNCTION_PARAM_VAR : Code_Location::FUNCTION_PHPDOC_METHOD), $param_typehint ? new Code_Location($this->file_scanner, $fake_method ? $stmt : $param, null, false, Code_Location::FUNCTION_PARAM_TYPE) : null, $is_optional, $is_nullable, $param->variadic, $default_type);
    }
    //phpcs:disable -- Remove this once the phpstan phpdoc parser MR is merged
    /**
     * @return array{
     *     string,
     *     FunctionStorage|MethodStorage,
     *     null|string,
     *     null|string,
     *     null|lowercase-string,
     *     ClassLikeStorage|null,
     *     bool,
     *     MethodIdentifier|null,
     *     bool
     * }|false
     */
    private function create_storage_for_function_like(Php_Parser\Node\Function_Like $stmt, bool $fake_method): array|false
    {
        //phpcs:enable -- Remove this once the phpstan phpdoc parser MR is merged
        $classlike_storage = null;
        $fq_classlike_name = null;
        $is_functionlike_override = false;
        $function_id = null;
        $method_name_lc = null;
        $method_id = null;
        if ($fake_method && $stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
            $cased_function_id = '@method ' . $stmt->name->name;
            $storage = $this->storage = new Method_Storage();
            $storage->defining_fqcln = '';
            $storage->is_static = $stmt->is_static();
            $storage->final = $this->classlike_storage && $this->classlike_storage->final;
            $storage->final_from_docblock = $this->classlike_storage && $this->classlike_storage->final_from_docblock;
            $storage->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Function_) {
            $cased_function_id = ($this->aliases->namespace ? $this->aliases->namespace . '\\' : '') . $stmt->name->name;
            $function_id = strtolower($cased_function_id);
            $storage = $this->storage = new Function_Storage();
            if ($this->codebase->register_stub_files || $this->codebase->register_autoload_files || $this->codebase->all_functions_global) {
                if (isset($this->file_storage->functions[$function_id]) && ($this->codebase->register_stub_files || !$this->codebase->functions->has_stubbed_function($function_id))) {
                    $this->codebase->functions->add_global_function($function_id, $this->file_storage->functions[$function_id]);
                    $storage = $this->storage = $this->file_storage->functions[$function_id];
                    return [$function_id, $storage, null, null, null, null, false, null, true];
                }
            } else {
                if (isset($this->file_storage->functions[$function_id])) {
                    $duplicate_function_storage = $this->file_storage->functions[$function_id];
                    if ($duplicate_function_storage->location && $duplicate_function_storage->location->get_line_number() === $stmt->get_line()) {
                        $storage = $this->storage = $this->file_storage->functions[$function_id];
                        return [$function_id, $storage, null, null, null, null, false, null, true];
                    }
                    Issue_Buffer::maybe_add(new Duplicate_Function('Method ' . $function_id . ' has already been defined' . ($duplicate_function_storage->location ? ' in ' . $duplicate_function_storage->location->file_path : ''), new Code_Location($this->file_scanner, $stmt, null, true)));
                    $this->file_storage->has_visitor_issues = true;
                    $duplicate_function_storage->has_visitor_issues = true;
                    $storage = $this->storage = $this->file_storage->functions[$function_id];
                    return [$function_id, $storage, null, null, null, null, false, null, true];
                }
                if (isset($this->config->get_predefined_functions()[$function_id])) {
                    /** @psalm-suppress ArgumentTypeCoercion */
                    $reflection_function = new ReflectionFunction($function_id);
                    if ($reflection_function->get_file_name() !== $this->file_path) {
                        Issue_Buffer::maybe_add(new Duplicate_Function('Method ' . $function_id . ' has already been defined as a core function', new Code_Location($this->file_scanner, $stmt, null, true)));
                    }
                }
            }
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_Method) {
            if (!$this->classlike_storage) {
                throw new LogicException('$this->classlike_storage should not be null');
            }
            $fq_classlike_name = $this->classlike_storage->name;
            $method_name_lc = strtolower($stmt->name->name);
            $function_id = $fq_classlike_name . '::' . $method_name_lc;
            $cased_function_id = $fq_classlike_name . '::' . $stmt->name->name;
            $classlike_storage = $this->classlike_storage;
            $storage = null;
            if (isset($classlike_storage->methods[$method_name_lc])) {
                if (!$this->codebase->register_stub_files) {
                    $duplicate_method_storage = $classlike_storage->methods[$method_name_lc];
                    Issue_Buffer::maybe_add(new Duplicate_Method('Method ' . $function_id . ' has already been defined' . ($duplicate_method_storage->location ? ' in ' . $duplicate_method_storage->location->file_path : ''), new Code_Location($this->file_scanner, $stmt, null, true)));
                    $this->file_storage->has_visitor_issues = true;
                    $duplicate_method_storage->has_visitor_issues = true;
                    return false;
                }
                // skip methods based on @since docblock tag
                $doc_comment = $stmt->get_doc_comment();
                if ($doc_comment) {
                    $docblock_info = null;
                    try {
                        $code_location = new Code_Location($this->file_scanner, $stmt, null, true);
                        $docblock_info = Function_Like_Docblock_Parser::parse($doc_comment, $code_location, $cased_function_id);
                    } catch (Incorrect_Docblock_Exception|Docblock_Parse_Exception) {
                    }
                    if ($docblock_info) {
                        if ($docblock_info->since_php_major_version && !$this->aliases->namespace) {
                            $analysis_major_php_version = $this->codebase->get_major_analysis_php_version();
                            $analysis_minor_php_version = $this->codebase->get_minor_analysis_php_version();
                            if ($docblock_info->since_php_major_version > $analysis_major_php_version) {
                                return false;
                            }
                            if ($docblock_info->since_php_major_version === $analysis_major_php_version && $docblock_info->since_php_minor_version > $analysis_minor_php_version) {
                                return false;
                            }
                        }
                    }
                }
                $is_functionlike_override = true;
                $storage = $this->storage = $classlike_storage->methods[$method_name_lc];
            }
            if (!$storage) {
                $storage = $this->storage = new Method_Storage();
            }
            $storage->stubbed = $this->codebase->register_stub_files;
            $storage->defining_fqcln = $fq_classlike_name;
            $class_name_parts = explode('\\', $fq_classlike_name);
            $class_name = array_pop($class_name_parts);
            if ($method_name_lc === strtolower($class_name) && !isset($classlike_storage->methods['__construct']) && !str_contains($fq_classlike_name, '\\') && $this->codebase->analysis_php_version_id <= 70400) {
                $this->codebase->methods->set_declaring_method_id($fq_classlike_name, '__construct', $fq_classlike_name, $method_name_lc);
                $this->codebase->methods->set_appearing_method_id($fq_classlike_name, '__construct', $fq_classlike_name, $method_name_lc);
            }
            $method_id = new Method_Identifier($fq_classlike_name, $method_name_lc);
            $storage->is_static = $stmt->is_static();
            $storage->abstract = $stmt->is_abstract();
            if ($stmt->is_private() && $stmt->is_final() && $method_name_lc !== '__construct') {
                Issue_Buffer::maybe_add(new Private_Final_Method('Private methods cannot be final', new Code_Location($this->file_scanner, $stmt, null, true), (string) $method_id));
                if ($this->codebase->analysis_php_version_id >= 80000) {
                    // ignore `final` on the method as that's what PHP does
                    $storage->final = $classlike_storage->final;
                } else {
                    $storage->final = true;
                }
            } else {
                $storage->final = $classlike_storage->final || $stmt->is_final();
            }
            $storage->final_from_docblock = $classlike_storage->final_from_docblock;
            if ($stmt->is_private()) {
                $storage->visibility = Class_Like_Analyzer::VISIBILITY_PRIVATE;
            } elseif ($stmt->is_protected()) {
                $storage->visibility = Class_Like_Analyzer::VISIBILITY_PROTECTED;
            } else {
                $storage->visibility = Class_Like_Analyzer::VISIBILITY_PUBLIC;
            }
        } elseif ($stmt instanceof Php_Parser\Node\Expr\Closure || $stmt instanceof Php_Parser\Node\Expr\Arrow_Function) {
            $function_id = $cased_function_id = strtolower($this->file_path) . ':' . $stmt->get_line() . ':' . (int) $stmt->get_attribute('startFilePos') . ':-:closure';
            $storage = $this->storage = $this->file_storage->functions[$function_id] = new Function_Storage();
            $storage->is_static = $stmt->static;
            if ($stmt instanceof Php_Parser\Node\Expr\Closure) {
                foreach ($stmt->uses as $closure_use) {
                    if ($closure_use->by_ref && is_string($closure_use->var->name)) {
                        $storage->byref_uses[$closure_use->var->name] = true;
                    }
                }
            }
        } elseif ($stmt instanceof Php_Parser\Node\Property_Hook) {
            $function_id = $cased_function_id = strtolower($this->file_path) . ':' . $stmt->get_line() . ':' . (int) $stmt->get_attribute('startFilePos') . ':-:hook';
            $storage = $this->storage = $this->file_storage->functions[$function_id] = new Function_Storage();
        } else {
            throw new UnexpectedValueException("Unrecognized functionlike of type " . $stmt::class);
        }
        return [$cased_function_id, $storage, $function_id, $fq_classlike_name, $method_name_lc, $classlike_storage, $is_functionlike_override, $method_id, false];
    }
}