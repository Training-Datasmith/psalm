<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Expr\Func_Call;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Statements_Source;
use Psalm\Type\Union;
final class After_Function_Call_Analysis_Event
{
    /**
     * @param non-empty-string $function_id
     * @param FileManipulation[] $file_replacements
     * @internal
     */
    public function __construct(private readonly Func_Call $expr, private readonly string $function_id, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private readonly Union $return_type_candidate, private array $file_replacements)
    {
    }
    public function get_expr(): Func_Call
    {
        return $this->expr;
    }
    /**
     * @return non-empty-string
     */
    public function get_function_id(): string
    {
        return $this->function_id;
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
    public function get_return_type_candidate(): Union
    {
        return $this->return_type_candidate;
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