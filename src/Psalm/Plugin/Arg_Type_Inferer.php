<?php

declare (strict_types=1);
namespace Psalm\Plugin;

use Php_Parser;
use Psalm\Context;
use Psalm\Internal\Analyzer\Statements\Expression_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Type;
use Psalm\Type\Union;
final class Arg_Type_Inferer
{
    /**
     * @internal
     */
    public function __construct(private readonly Context $context, private readonly Statements_Analyzer $statements_analyzer)
    {
    }
    public function infer(Php_Parser\Node\Arg $arg): null|Union
    {
        $already_inferred_type = $this->statements_analyzer->node_data->get_type($arg->value);
        if ($already_inferred_type) {
            return $already_inferred_type;
        }
        if (Expression_Analyzer::analyze($this->statements_analyzer, $arg->value, $this->context) === false) {
            return null;
        }
        return $this->statements_analyzer->node_data->get_type($arg->value) ?? Type::get_mixed();
    }
}