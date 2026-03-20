<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Stmt;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Statements_Source;
use Psalm\Storage\File_Storage;
final class Before_File_Analysis_Event
{
    /**
     * Called before a file has been checked
     *
     * @param list<Stmt> $stmts
     * @internal
     */
    public function __construct(private readonly Statements_Source $statements_source, private readonly Context $file_context, private readonly File_Storage $file_storage, private readonly Codebase $codebase, private readonly array $stmts)
    {
    }
    public function get_statements_source(): Statements_Source
    {
        return $this->statements_source;
    }
    public function get_file_context(): Context
    {
        return $this->file_context;
    }
    public function get_file_storage(): File_Storage
    {
        return $this->file_storage;
    }
    public function get_codebase(): Codebase
    {
        return $this->codebase;
    }
    /**
     * @return list<Stmt>
     */
    public function get_stmts(): array
    {
        return $this->stmts;
    }
}