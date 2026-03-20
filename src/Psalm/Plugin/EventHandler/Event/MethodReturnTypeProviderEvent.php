<?php

declare (strict_types=1);
namespace Psalm\Plugin\Event_Handler\Event;

use Php_Parser;
use Psalm\Code_Location;
use Psalm\Context;
use Psalm\Statements_Source;
use Psalm\Type\Union;
final class Method_Return_Type_Provider_Event
{
    /**
     * Use this hook for providing custom return type logic. If this plugin does not know what a method should return
     * but another plugin may be able to determine the type, return null. Otherwise return a mixed union type if
     * something should be returned, but can't be more specific.
     *
     * @param non-empty-list<Union>|null $template_type_parameters
     * @param lowercase-string $method_name_lowercase
     * @param lowercase-string $called_method_name_lowercase
     * @internal
     */
    public function __construct(private readonly Statements_Source $source, private readonly string $fq_classlike_name, private readonly string $method_name_lowercase, private readonly Php_Parser\Node\Expr\Method_Call|Php_Parser\Node\Expr\Static_Call $stmt, private readonly Context $context, private readonly Code_Location $code_location, private readonly ?array $template_type_parameters = null, private readonly ?string $called_fq_classlike_name = null, private readonly ?string $called_method_name_lowercase = null)
    {
    }
    public function get_source(): Statements_Source
    {
        return $this->source;
    }
    public function get_fq_classlike_name(): string
    {
        return $this->fq_classlike_name;
    }
    /**
     * @return lowercase-string
     */
    public function get_method_name_lowercase(): string
    {
        return $this->method_name_lowercase;
    }
    /**
     * @return list<PhpParser\Node\Arg>
     */
    public function get_call_args(): array
    {
        return $this->stmt->get_args();
    }
    public function get_context(): Context
    {
        return $this->context;
    }
    public function get_code_location(): Code_Location
    {
        return $this->code_location;
    }
    /**
     * @return non-empty-list<Union>|null
     */
    public function get_template_type_parameters(): ?array
    {
        return $this->template_type_parameters;
    }
    public function get_called_fq_classlike_name(): ?string
    {
        return $this->called_fq_classlike_name;
    }
    /**
     * @return lowercase-string|null
     */
    public function get_called_method_name_lowercase(): ?string
    {
        return $this->called_method_name_lowercase;
    }
    public function get_stmt(): Php_Parser\Node\Expr\Method_Call|Php_Parser\Node\Expr\Static_Call
    {
        return $this->stmt;
    }
}