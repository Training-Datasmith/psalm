<?php

declare (strict_types=1);
namespace Psalm\Internal\Scanner;

use Php_Parser;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Plugin\Event_Handler\Event\Function_Return_Type_Provider_Event;
use Psalm\Plugin\Event_Handler\Event\Method_Return_Type_Provider_Event;
use Psalm\Type;
use Psalm\Type\Atomic\T_Array;
use Psalm\Type\Atomic\T_Keyed_Array;
use Psalm\Type\Atomic\T_Named_Object;
use Psalm\Type\Union;
use ReflectionProperty;
use function count;
use function is_string;
use function str_contains;
use function str_replace;
use function strtolower;
/**
 * @internal
 */
final class Php_Storm_Meta_Scanner
{
    /**
     * @param  list<PhpParser\Node\Arg> $args
     */
    public static function handle_override(array $args, Codebase $codebase): void
    {
        if (count($args) < 2) {
            return;
        }
        $identifier = $args[0]->value;
        if (!$args[1]->value instanceof Php_Parser\Node\Expr\Func_Call || !$args[1]->value->name instanceof Php_Parser\Node\Name) {
            return;
        }
        $map = [];
        if ($args[1]->value->name->get_parts() === ['map'] && $args[1]->value->get_args() && $args[1]->value->get_args()[0]->value instanceof Php_Parser\Node\Expr\Array_) {
            foreach ($args[1]->value->get_args()[0]->value->items as $array_item) {
                if ($array_item && $array_item->key instanceof Php_Parser\Node\Scalar\String_) {
                    if ($array_item->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $array_item->value->class instanceof Php_Parser\Node\Name\Fully_Qualified && $array_item->value->name instanceof Php_Parser\Node\Identifier && strtolower($array_item->value->name->name)) {
                        $map[$array_item->key->value] = new Union([new T_Named_Object($array_item->value->class->to_string())]);
                    } elseif ($array_item->value instanceof Php_Parser\Node\Scalar\String_) {
                        $map[$array_item->key->value] = $array_item->value->value;
                    }
                } elseif ($array_item && $array_item->key instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $array_item->key->class instanceof Php_Parser\Node\Name\Fully_Qualified && $array_item->key->name instanceof Php_Parser\Node\Identifier) {
                    /** @var string|null $resolved_name */
                    $resolved_name = $array_item->key->class->get_attribute('resolvedName');
                    if (!$resolved_name) {
                        continue;
                    }
                    $constant_type = $codebase->classlikes->get_class_constant_type($resolved_name, $array_item->key->name->name, ReflectionProperty::IS_PRIVATE);
                    if (!$constant_type instanceof Union) {
                        continue;
                    }
                    if (!$constant_type->is_single_string_literal()) {
                        continue;
                    }
                    $meta_key = $constant_type->get_single_string_literal()->value;
                    if ($array_item->value instanceof Php_Parser\Node\Expr\Class_Const_Fetch && $array_item->value->class instanceof Php_Parser\Node\Name\Fully_Qualified && $array_item->value->name instanceof Php_Parser\Node\Identifier && strtolower($array_item->value->name->name)) {
                        $map[$meta_key] = new Union([new T_Named_Object($array_item->value->class->to_string())]);
                    } elseif ($array_item->value instanceof Php_Parser\Node\Scalar\String_) {
                        $map[$meta_key] = $array_item->value->value;
                    }
                }
            }
        }
        $type_offset = null;
        if ($args[1]->value->name->get_parts() === ['type'] && $args[1]->value->get_args() && $args[1]->value->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_) {
            $type_offset = $args[1]->value->get_args()[0]->value->value;
        }
        $element_type_offset = null;
        if ($args[1]->value->name->get_parts() === ['elementType'] && $args[1]->value->get_args() && $args[1]->value->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_) {
            $element_type_offset = $args[1]->value->get_args()[0]->value->value;
        }
        if ($identifier instanceof Php_Parser\Node\Expr\Static_Call && $identifier->class instanceof Php_Parser\Node\Name\Fully_Qualified && $identifier->name instanceof Php_Parser\Node\Identifier && ($identifier->get_args() === [] || $identifier->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_)) {
            $meta_fq_classlike_name = $identifier->class->to_string();
            $meta_method_name = strtolower($identifier->name->name);
            if ($map) {
                $offset = 0;
                if ($identifier->get_args() && $identifier->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_) {
                    $offset = $identifier->get_args()[0]->value->value;
                }
                $codebase->methods->return_type_provider->register_closure($meta_fq_classlike_name, static function (Method_Return_Type_Provider_Event $event) use ($map, $offset, $meta_fq_classlike_name, $meta_method_name): ?Union {
                    $statements_analyzer = $event->get_source();
                    $call_args = $event->get_call_args();
                    $method_name = $event->get_method_name_lowercase();
                    $fq_classlike_name = $event->get_fq_classlike_name();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if ($meta_method_name !== $method_name || $meta_fq_classlike_name !== $fq_classlike_name) {
                        return null;
                    }
                    if (isset($call_args[$offset]->value) && ($call_arg_type = $statements_analyzer->node_data->get_type($call_args[$offset]->value)) && $call_arg_type->is_single_string_literal()) {
                        $offset_arg_value = $call_arg_type->get_single_string_literal()->value;
                        if ($mapped_type = $map[$offset_arg_value] ?? null) {
                            if ($mapped_type instanceof Union) {
                                return $mapped_type;
                            }
                        }
                        if (($mapped_type = $map[''] ?? null) && is_string($mapped_type)) {
                            if (str_contains($mapped_type, '@')) {
                                $mapped_type = str_replace('@', $offset_arg_value, $mapped_type);
                                if (!str_contains($mapped_type, '.')) {
                                    return new Union([new T_Named_Object($mapped_type)]);
                                }
                            }
                        }
                    }
                    return null;
                });
            } elseif ($type_offset !== null) {
                $codebase->methods->return_type_provider->register_closure($meta_fq_classlike_name, static function (Method_Return_Type_Provider_Event $event) use ($type_offset, $meta_fq_classlike_name, $meta_method_name): ?Union {
                    $statements_analyzer = $event->get_source();
                    $call_args = $event->get_call_args();
                    $method_name = $event->get_method_name_lowercase();
                    $fq_classlike_name = $event->get_fq_classlike_name();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if ($meta_method_name !== $method_name || $meta_fq_classlike_name !== $fq_classlike_name) {
                        return null;
                    }
                    if (isset($call_args[$type_offset]->value) && $call_arg_type = $statements_analyzer->node_data->get_type($call_args[$type_offset]->value)) {
                        return $call_arg_type;
                    }
                    return null;
                });
            } elseif ($element_type_offset !== null) {
                $codebase->methods->return_type_provider->register_closure($meta_fq_classlike_name, static function (Method_Return_Type_Provider_Event $event) use ($element_type_offset, $meta_fq_classlike_name, $meta_method_name): ?Union {
                    $statements_analyzer = $event->get_source();
                    $call_args = $event->get_call_args();
                    $method_name = $event->get_method_name_lowercase();
                    $fq_classlike_name = $event->get_fq_classlike_name();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if ($meta_method_name !== $method_name || $meta_fq_classlike_name !== $fq_classlike_name) {
                        return null;
                    }
                    if (isset($call_args[$element_type_offset]->value) && ($call_arg_type = $statements_analyzer->node_data->get_type($call_args[$element_type_offset]->value)) && $call_arg_type->has_array()) {
                        /**
                         * @var TArray|TKeyedArray
                         */
                        $array_atomic_type = $call_arg_type->get_array();
                        if ($array_atomic_type instanceof T_Keyed_Array) {
                            return $array_atomic_type->get_generic_value_type();
                        }
                        return $array_atomic_type->type_params[1];
                    }
                    return null;
                });
            }
        }
        if ($identifier instanceof Php_Parser\Node\Expr\Func_Call && $identifier->name instanceof Php_Parser\Node\Name\Fully_Qualified && ($identifier->get_args() === [] || $identifier->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_)) {
            $function_id = strtolower($identifier->name->to_string());
            if ($map) {
                $offset = 0;
                if ($identifier->get_args() && $identifier->get_args()[0]->value instanceof Php_Parser\Node\Scalar\Int_) {
                    $offset = $identifier->get_args()[0]->value->value;
                }
                $codebase->functions->return_type_provider->register_closure($function_id, static function (Function_Return_Type_Provider_Event $event) use ($map, $offset): Union {
                    $statements_analyzer = $event->get_statements_source();
                    $call_args = $event->get_call_args();
                    $function_id = $event->get_function_id();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if (isset($call_args[$offset]->value) && ($call_arg_type = $statements_analyzer->node_data->get_type($call_args[$offset]->value)) && $call_arg_type->is_single_string_literal()) {
                        $offset_arg_value = $call_arg_type->get_single_string_literal()->value;
                        if ($mapped_type = $map[$offset_arg_value] ?? null) {
                            if ($mapped_type instanceof Union) {
                                return $mapped_type;
                            }
                        }
                        if (($mapped_type = $map[''] ?? null) && is_string($mapped_type)) {
                            if (str_contains($mapped_type, '@')) {
                                $mapped_type = str_replace('@', $offset_arg_value, $mapped_type);
                                if (!str_contains($mapped_type, '.')) {
                                    return new Union([new T_Named_Object($mapped_type)]);
                                }
                            }
                        }
                    }
                    $storage = $statements_analyzer->get_codebase()->functions->get_storage($statements_analyzer, strtolower($function_id));
                    return $storage->return_type ?: Type::get_mixed();
                });
            } elseif ($type_offset !== null) {
                $codebase->functions->return_type_provider->register_closure($function_id, static function (Function_Return_Type_Provider_Event $event) use ($type_offset): Union {
                    $statements_analyzer = $event->get_statements_source();
                    $call_args = $event->get_call_args();
                    $function_id = $event->get_function_id();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if (isset($call_args[$type_offset]->value) && $call_arg_type = $statements_analyzer->node_data->get_type($call_args[$type_offset]->value)) {
                        return $call_arg_type;
                    }
                    $storage = $statements_analyzer->get_codebase()->functions->get_storage($statements_analyzer, strtolower($function_id));
                    return $storage->return_type ?: Type::get_mixed();
                });
            } elseif ($element_type_offset !== null) {
                $codebase->functions->return_type_provider->register_closure($function_id, static function (Function_Return_Type_Provider_Event $event) use ($element_type_offset): Union {
                    $statements_analyzer = $event->get_statements_source();
                    $call_args = $event->get_call_args();
                    $function_id = $event->get_function_id();
                    if (!$statements_analyzer instanceof Statements_Analyzer) {
                        return Type::get_mixed();
                    }
                    if (isset($call_args[$element_type_offset]->value) && ($call_arg_type = $statements_analyzer->node_data->get_type($call_args[$element_type_offset]->value)) && $call_arg_type->has_array()) {
                        /**
                         * @var TArray|TKeyedArray
                         */
                        $array_atomic_type = $call_arg_type->get_array();
                        if ($array_atomic_type instanceof T_Keyed_Array) {
                            return $array_atomic_type->get_generic_value_type();
                        }
                        return $array_atomic_type->type_params[1];
                    }
                    $storage = $statements_analyzer->get_codebase()->functions->get_storage($statements_analyzer, strtolower($function_id));
                    return $storage->return_type ?: Type::get_mixed();
                });
            }
        }
    }
}