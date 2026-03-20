<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Expr;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Statements_Source;
final class Before_Expression_Analysis_Event
{
    /**
     * Called before an expression is checked
     *
     * @param  list<FileManipulation> $file_replacements
     * @internal
     */
    public function __construct(private readonly Expr $expr, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements = [])
    {
    }
    public function get_expr(): Expr
    {
        return $this->expr;
    }
    public function get_context(): Context
    {
        return $this->context;
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * @return list<FileManipulation>
     */
    public function get_file_replacements(): array
    {
        return $this->file_replacements;
    }
    /**
     * @param list<FileManipulation> $file_replacements
     */
    public function set_file_replacements(array $file_replacements): void
    {
        $this->file_replacements = $file_replacements;
    }
}