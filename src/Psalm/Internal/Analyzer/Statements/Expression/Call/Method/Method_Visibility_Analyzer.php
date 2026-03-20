<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Trait_Analyzer;
use Psalm\Internal\Codebase\Internal_Call_Map_Handler;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Inaccessible_Method;
use Psalm\Issue_Buffer;
use Psalm\Statements_Source;
use UnexpectedValueException;
use function array_pop;
use function end;
use function strtolower;
/**
 * @internal
 */
final class Method_Visibility_Analyzer
{
    /**
     * @param  string[]         $suppressed_issues
     * @return false|null
     */
    public static function analyze(Method_Identifier $method_id, Context $context, Statements_Source $source, Code_Location $code_location, array $suppressed_issues): ?bool
    {
        $codebase = $source->get_codebase();
        $codebase_methods = $codebase->methods;
        $codebase_classlikes = $codebase->classlikes;
        $fq_classlike_name = $method_id->fq_class_name;
        $method_name = $method_id->method_name;
        $with_pseudo = true;
        if ($codebase_methods->visibility_provider->has($fq_classlike_name)) {
            $method_visible = $codebase_methods->visibility_provider->is_method_visible($source, $fq_classlike_name, $method_name, $context, $code_location);
            if ($method_visible === false) {
                if (Issue_Buffer::accepts(new Inaccessible_Method('Cannot access method ' . $codebase_methods->get_cased_method_id($method_id) . ' from context ' . $context->self, $code_location), $suppressed_issues)) {
                    return false;
                }
            } elseif ($method_visible === true) {
                return false;
            }
        }
        $declaring_method_id = $codebase_methods->get_declaring_method_id($method_id, $with_pseudo);
        if (!$declaring_method_id) {
            if ($method_name === '__construct' || $method_id->fq_class_name === 'Closure' && ($method_id->method_name === 'fromcallable' || $method_id->method_name === '__invoke')) {
                return null;
            }
            if (Internal_Call_Map_Handler::in_call_map((string) $method_id)) {
                return null;
            }
            throw new UnexpectedValueException('$declaring_method_id not expected to be null here');
        }
        $appearing_method_id = $codebase_methods->get_appearing_method_id($method_id);
        $appearing_method_class = null;
        $appearing_class_storage = null;
        $appearing_method_name = null;
        if ($appearing_method_id) {
            $appearing_method_class = $appearing_method_id->fq_class_name;
            $appearing_method_name = $appearing_method_id->method_name;
            // if the calling class is the same, we know the method exists, so it must be visible
            if ($appearing_method_class === $context->self) {
                return null;
            }
            $appearing_class_storage = $codebase->classlike_storage_provider->get($appearing_method_class);
        }
        $declaring_method_class = $declaring_method_id->fq_class_name;
        if ($source->get_source() instanceof Trait_Analyzer && strtolower($declaring_method_class) === strtolower((string) $source->get_fqcln())) {
            return null;
        }
        $storage = $codebase->methods->get_storage($declaring_method_id, $with_pseudo);
        $visibility = $storage->visibility;
        if ($appearing_method_name && isset($appearing_class_storage->trait_visibility_map[$appearing_method_name])) {
            $visibility = $appearing_class_storage->trait_visibility_map[$appearing_method_name];
        }
        // Get oldest ancestor declaring $method_id
        $overridden_method_ids = $codebase_methods->get_overridden_method_ids($method_id);
        // Remove traits and interfaces
        while (($oldest_declaring_method_id = end($overridden_method_ids)) && !$codebase_classlikes->has_fully_qualified_class_name($oldest_declaring_method_id->fq_class_name)) {
            array_pop($overridden_method_ids);
        }
        if (empty($overridden_method_ids)) {
            // We prefer appearing method id over declaring method id because declaring method id could be a trait
            $oldest_ancestor_declaring_method_id = $appearing_method_id;
        } else {
            // Oldest ancestor is at end of array
            $oldest_ancestor_declaring_method_id = array_pop($overridden_method_ids);
        }
        $oldest_ancestor_declaring_method_class = $oldest_ancestor_declaring_method_id->fq_class_name ?? null;
        switch ($visibility) {
            case Class_Like_Analyzer::VISIBILITY_PUBLIC:
                return null;
            case Class_Like_Analyzer::VISIBILITY_PRIVATE:
                if (!(!$context->self || $appearing_method_class !== $context->self)) {
                    return null;
                }
                if (Issue_Buffer::accepts(new Inaccessible_Method('Cannot access private method ' . $codebase_methods->get_cased_method_id($method_id) . ' from context ' . $context->self, $code_location), $suppressed_issues)) {
                    return false;
                }
                return null;
            case Class_Like_Analyzer::VISIBILITY_PROTECTED:
                if (!$context->self) {
                    if (Issue_Buffer::accepts(new Inaccessible_Method('Cannot access protected method ' . $method_id, $code_location), $suppressed_issues)) {
                        return false;
                    }
                    return null;
                }
                if ($oldest_ancestor_declaring_method_class !== null && $codebase_classlikes->class_extends($oldest_ancestor_declaring_method_class, $context->self)) {
                    return null;
                }
                if ($oldest_ancestor_declaring_method_class !== null && !$codebase_classlikes->class_extends($context->self, $oldest_ancestor_declaring_method_class) && !$codebase_classlikes->class_extends($declaring_method_class, $context->self)) {
                    if (Issue_Buffer::accepts(new Inaccessible_Method('Cannot access protected method ' . $codebase_methods->get_cased_method_id($method_id) . ' from context ' . $context->self, $code_location), $suppressed_issues)) {
                        return false;
                    }
                }
        }
        return null;
    }
}