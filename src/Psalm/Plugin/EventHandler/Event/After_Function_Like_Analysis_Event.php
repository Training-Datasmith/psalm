<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Node_Type_Provider;
use Psalm\Statements_Source;
use Psalm\Storage\Function_Like_Storage;
final class After_Function_Like_Analysis_Event
{
    /**
     * Called after a statement has been checked
     *
     * @param  FileManipulation[]   $file_replacements
     * @internal
     */
    public function __construct(private readonly Node\Function_Like $stmt, private readonly Function_Like_Storage $functionlike_storage, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements, private readonly Node_Type_Provider $node_type_provider, private readonly Context $context)
    {
    }
    public function get_stmt(): Node\Function_Like
    {
        return $this->stmt;
    }
    public function get_functionlike_storage(): Function_Like_Storage
    {
        return $this->functionlike_storage;
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
    public function get_node_type_provider(): Node_Type_Provider
    {
        return $this->node_type_provider;
    }
    public function get_context(): Context
    {
        return $this->context;
    }
}