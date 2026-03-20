<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements\Expression\Call\Method;

use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Internal\Analyzer\Namespace_Analyzer;
use Psalm\Internal\Method_Identifier;
use Psalm\Issue\Deprecated_Method;
use Psalm\Issue\Internal_Class;
use Psalm\Issue\Internal_Method;
use Psalm\Issue_Buffer;
/**
 * @internal
 */
final class Method_Call_Prohibition_Analyzer
{
    /**
     * @param  string[]     $suppressed_issues
     */
    public static function analyze(Codebase $codebase, Context $context, Method_Identifier $method_id, ?string $caller_identifier, Code_Location $code_location, array $suppressed_issues): void
    {
        $codebase_methods = $codebase->methods;
        $method_id = $codebase_methods->get_declaring_method_id($method_id);
        if ($method_id === null) {
            return;
        }
        $storage = $codebase_methods->get_storage($method_id);
        if ($storage->deprecated) {
            Issue_Buffer::maybe_add(new Deprecated_Method('The method ' . $codebase_methods->get_cased_method_id($method_id) . ' has been marked as deprecated', $code_location, (string) $method_id), $suppressed_issues);
        }
        if (!$context->collect_initializations && !$context->collect_mutations) {
            if (!Namespace_Analyzer::is_within_any($caller_identifier ?? "", $storage->internal)) {
                Issue_Buffer::maybe_add(new Internal_Method('The method ' . $codebase_methods->get_cased_method_id($method_id) . ' is internal to ' . Internal_Class::list_to_phrase($storage->internal) . ' but called from ' . ($caller_identifier ?: 'root namespace'), $code_location, (string) $method_id), $suppressed_issues);
            }
        }
    }
}