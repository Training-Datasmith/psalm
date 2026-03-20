<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Stmt\Class_Like;
use Psalm\Codebase;
use Psalm\File_Manipulation;
use Psalm\File_Source;
use Psalm\Storage\Class_Like_Storage;
final class After_Class_Like_Visit_Event
{
    /**
     * @param  FileManipulation[] $file_replacements
     * @internal
     */
    public function __construct(private readonly Class_Like $stmt, private readonly Class_Like_Storage $storage, private readonly File_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements = [])
    {
    }
    public function get_stmt(): Class_Like
    {
        return $this->stmt;
    }
    public function get_storage(): Class_Like_Storage
    {
        return $this->storage;
    }
    public function get_statements_source(): File_Source
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