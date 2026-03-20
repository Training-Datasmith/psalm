<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Stmt;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Statements_Source;
final class After_Statement_Analysis_Event
{
    /**
     * Called after a statement has been checked
     *
     * @param  FileManipulation[]   $file_replacements
     * @internal
     */
    public function __construct(private readonly Stmt $stmt, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements = [])
    {
    }
    public function get_stmt(): Stmt
    {
        return $this->stmt;
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
     * @return FileManipulation[]
     */
    public function get_file_replacements(): array
    {
        return $this->file_replacements;
    }
    /**
     * @param FileManipulation[] $file_replacements
     */
    public function set_file_replacements(array $file_replacements): void
    {
        $this->file_replacements = $file_replacements;
    }
}