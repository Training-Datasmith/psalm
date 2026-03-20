<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Function_Analyzer;
use Psalm\Internal\Analyzer\Method_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Issue\Undefined_Constant;
use Psalm\Issue_Buffer;
use Psalm\Type;
use Psalm\Type\Atomic\T_Callable_String;
use Psalm\Type\Atomic\T_Non_Empty_String;
use Psalm\Type\Union;
use function dirname;
/**
 * @internal
 */
final class Magic_Const_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Scalar\Magic_Const $stmt, Context $context): void
    {
        if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Line) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_int_range(1, null));
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Class_) {
            $codebase = $statements_analyzer->get_codebase();
            if (!$context->self) {
                Issue_Buffer::maybe_add(new Undefined_Constant('Cannot get __class__ outside a class', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
                $statements_analyzer->node_data->set_type($stmt, Type::get_class_string());
            } else {
                if ($codebase->alter_code) {
                    $codebase->classlikes->handle_class_like_reference_in_migration($codebase, $statements_analyzer, $stmt, $context->self, $context->calling_method_id);
                }
                $statements_analyzer->node_data->set_type($stmt, Type::get_literal_class_string($context->self));
            }
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Namespace_) {
            $namespace = $statements_analyzer->get_namespace();
            if ($namespace === null) {
                Issue_Buffer::maybe_add(new Undefined_Constant('Cannot get __namespace__ outside a namespace', new Code_Location($statements_analyzer->get_source(), $stmt)), $statements_analyzer->get_suppressed_issues());
            }
            $statements_analyzer->node_data->set_type($stmt, Type::get_string($namespace));
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Method || $stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Function_) {
            $source = $statements_analyzer->get_source();
            if ($source instanceof Method_Analyzer) {
                if ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Function_) {
                    $statements_analyzer->node_data->set_type($stmt, Type::get_string($source->get_method_name()));
                } else {
                    $statements_analyzer->node_data->set_type($stmt, Type::get_string($source->get_correctly_cased_method_id()));
                }
            } elseif ($source instanceof Function_Analyzer) {
                $statements_analyzer->node_data->set_type($stmt, Type::get_string($source->get_correctly_cased_method_id()));
            } else {
                $statements_analyzer->node_data->set_type($stmt, new Union([new T_Callable_String()]));
            }
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Dir) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_string(dirname($statements_analyzer->get_source()->get_file_path())));
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\File) {
            $statements_analyzer->node_data->set_type($stmt, Type::get_string($statements_analyzer->get_source()->get_file_path()));
        } elseif ($stmt instanceof Php_Parser\Node\Scalar\Magic_Const\Trait_) {
            if ($statements_analyzer->get_source() instanceof Trait_Analyzer) {
                $statements_analyzer->node_data->set_type($stmt, new Union([new T_Non_Empty_String()]));
            } else {
                $statements_analyzer->node_data->set_type($stmt, Type::get_string());
            }
        }
    }
}