<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer\Statements;

use Php_Parser;
use Php_Parser\Node\Declare_Item;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Issue\Unrecognized_Statement;
use Psalm\Issue_Buffer;
use function in_array;
/**
 * @internal
 */
final class Declare_Analyzer
{
    public static function analyze(Statements_Analyzer $statements_analyzer, Php_Parser\Node\Stmt\Declare_ $stmt, Context $context): void
    {
        foreach ($stmt->declares as $declaration) {
            $declaration_key = (string) $declaration->key;
            if ($declaration_key === 'strict_types') {
                if ($stmt->stmts !== null) {
                    Issue_Buffer::maybe_add(new Unrecognized_Statement('strict_types declaration must not use block mode', new Code_Location($statements_analyzer, $stmt)), $statements_analyzer->get_suppressed_issues());
                }
                self::analyze_strict_types_declaration($statements_analyzer, $declaration, $context);
            } elseif ($declaration_key === 'ticks') {
                self::analyze_ticks_declaration($statements_analyzer, $declaration);
            } elseif ($declaration_key === 'encoding') {
                self::analyze_encoding_declaration($statements_analyzer, $declaration);
            } else {
                Issue_Buffer::maybe_add(new Unrecognized_Statement('Psalm does not understand the declare statement ' . $declaration->key, new Code_Location($statements_analyzer, $declaration)), $statements_analyzer->get_suppressed_issues());
            }
        }
    }
    private static function analyze_strict_types_declaration(Statements_Analyzer $statements_analyzer, Declare_Item $declaration, Context $context): void
    {
        if (!$declaration->value instanceof Php_Parser\Node\Scalar\L_Number || !in_array($declaration->value->value, [0, 1], true)) {
            Issue_Buffer::maybe_add(new Unrecognized_Statement('strict_types declaration can only have 1 or 0 as a value', new Code_Location($statements_analyzer, $declaration)), $statements_analyzer->get_suppressed_issues());
            return;
        }
        if ($declaration->value->value === 1) {
            $context->strict_types = true;
        }
    }
    private static function analyze_ticks_declaration(Statements_Analyzer $statements_analyzer, Declare_Item $declaration): void
    {
        if (!$declaration->value instanceof Php_Parser\Node\Scalar\L_Number) {
            Issue_Buffer::maybe_add(new Unrecognized_Statement('ticks declaration should have integer as a value', new Code_Location($statements_analyzer, $declaration)), $statements_analyzer->get_suppressed_issues());
        }
    }
    private static function analyze_encoding_declaration(Statements_Analyzer $statements_analyzer, Declare_Item $declaration): void
    {
        if (!$declaration->value instanceof Php_Parser\Node\Scalar\String_) {
            Issue_Buffer::maybe_add(new Unrecognized_Statement('encoding declaration should have string as a value', new Code_Location($statements_analyzer, $declaration)), $statements_analyzer->get_suppressed_issues());
        }
    }
}