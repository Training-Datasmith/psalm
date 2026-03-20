<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser\Node\Expr;
use Php_Parser\Node\Expr\Method_Call;
use Php_Parser\Node\Expr\Static_Call;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\File_Manipulation;
use Psalm\Statements_Source;
use Psalm\Type\Union;
final class After_Method_Call_Analysis_Event
{
    /**
     * @param  FileManipulation[] $file_replacements
     * @internal
     */
    public function __construct(private readonly Method_Call|Static_Call $expr, private readonly string $method_id, private readonly string $appearing_method_id, private readonly string $declaring_method_id, private readonly Context $context, private readonly Statements_Source $statements_source, private readonly Codebase $codebase, private array $file_replacements = [], private ?Union $return_type_candidate = null)
    {
    }
    /**
     * @return MethodCall|StaticCall
     */
    public function get_expr(): Expr
    {
        return $this->expr;
    }
    public function get_method_id(): string
    {
        return $this->method_id;
    }
    public function get_appearing_method_id(): string
    {
        return $this->appearing_method_id;
    }
    public function get_declaring_method_id(): string
    {
        return $this->declaring_method_id;
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
    public function get_return_type_candidate(): ?Union
    {
        return $this->return_type_candidate;
    }
    /**
     * @param FileManipulation[] $file_replacements
     */
    public function set_file_replacements(array $file_replacements): void
    {
        $this->file_replacements = $file_replacements;
    }
    public function set_return_type_candidate(?Union $return_type_candidate): void
    {
        $this->return_type_candidate = $return_type_candidate;
    }
}