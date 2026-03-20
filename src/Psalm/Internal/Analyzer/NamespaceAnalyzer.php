<?php

declare (strict_types=1);
namespace Psalm\Internal\Analyzer;

use InvalidArgumentException;
use Override;
use Php_Parser;
use Php_Parser\Node\Stmt\Namespace_;
use Psalm\Context;
use Psalm\Internal\Provider\Node_Data_Provider;
use Psalm\Type;
use Psalm\Type\Union;
use ReflectionProperty;
use UnexpectedValueException;
use function assert;
use function count;
use function preg_replace;
use function strpos;
use function strtolower;
use function substr;
/**
 * @internal
 */
final class Namespace_Analyzer extends Source_Analyzer
{
    use Can_Alias;
    private readonly string $namespace_name;
    /**
     * A lookup table for public namespace constants
     *
     * @var array<string, array<string, Union>>
     */
    private static array $public_namespace_constants = [];
    public function __construct(
        private readonly Namespace_ $namespace,
        /**
         * @var FileAnalyzer
         */
        protected Source_Analyzer $source
    )
    {
        $this->namespace_name = $this->namespace->name ? $this->namespace->name->to_string() : '';
    }
    public function collect_analyzable_information(): void
    {
        $leftover_stmts = [];
        if (!isset(self::$public_namespace_constants[$this->namespace_name])) {
            self::$public_namespace_constants[$this->namespace_name] = [];
        }
        $codebase = $this->get_codebase();
        foreach ($this->namespace->stmts as $stmt) {
            if ($stmt instanceof Php_Parser\Node\Stmt\Class_Like) {
                $this->collect_analyzable_class_like($stmt);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Use_) {
                $this->visit_use($stmt);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Group_Use) {
                $this->visit_group_use($stmt);
            } elseif ($stmt instanceof Php_Parser\Node\Stmt\Const_) {
                foreach ($stmt->consts as $const) {
                    self::$public_namespace_constants[$this->namespace_name][$const->name->name] = Type::get_mixed();
                }
                $leftover_stmts[] = $stmt;
            } else {
                $leftover_stmts[] = $stmt;
            }
        }
        if ($leftover_stmts) {
            $statements_analyzer = new Statements_Analyzer($this, new Node_Data_Provider());
            $file_context = $this->source->context;
            if ($file_context !== null) {
                $context = $file_context;
            } else {
                $context = new Context();
                $context->is_global = true;
                $context->define_globals();
                $context->collect_exceptions = $codebase->config->check_for_throws_in_global_scope;
            }
            $statements_analyzer->analyze($leftover_stmts, $context, null, true);
        }
    }
    public function collect_analyzable_class_like(Php_Parser\Node\Stmt\Class_Like $stmt): void
    {
        if (!$stmt->name) {
            throw new UnexpectedValueException('Did not expect anonymous class here');
        }
        $fq_class_name = Type::get_fqcln_from_string($stmt->name->name, $this->get_aliases());
        if ($stmt instanceof Php_Parser\Node\Stmt\Class_ || $stmt instanceof Php_Parser\Node\Stmt\Enum_) {
            $this->source->add_namespaced_class_analyzer($fq_class_name, new Class_Analyzer($stmt, $this, $fq_class_name));
        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Interface_) {
            $this->source->add_namespaced_interface_analyzer($fq_class_name, new Interface_Analyzer($stmt, $this, $fq_class_name));
        }
    }
    #[Override]
    public function get_namespace(): string
    {
        return $this->namespace_name;
    }
    public function set_const_type(string $const_name, Union $const_type): void
    {
        self::$public_namespace_constants[$this->namespace_name][$const_name] = $const_type;
    }
    /**
     * @return array<string, Union>
     */
    public static function get_constants_for_namespace(string $namespace_name, int $visibility): array
    {
        // @todo this does not allow for loading in namespace constants not already defined in the current sweep
        if (!isset(self::$public_namespace_constants[$namespace_name])) {
            self::$public_namespace_constants[$namespace_name] = [];
        }
        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return self::$public_namespace_constants[$namespace_name];
        }
        throw new InvalidArgumentException('Given $visibility not supported');
    }
    #[Override]
    public function get_file_analyzer(): File_Analyzer
    {
        return $this->source;
    }
    /**
     * Returns true if $calling_identifier is the same as, or is within with $identifier, in a
     * case-insensitive comparison. Identifiers can be namespaces, classlikes, functions, or methods.
     *
     * @psalm-pure
     * @throws InvalidArgumentException if $identifier is not a valid identifier
     */
    public static function is_within(string $calling_identifier, string $identifier): bool
    {
        $normalized_calling_ident = self::normalize_identifier($calling_identifier);
        $normalized_ident = self::normalize_identifier($identifier);
        if ($normalized_calling_ident === $normalized_ident) {
            return true;
        }
        $normalized_calling_ident_parts = self::get_identifier_parts($normalized_calling_ident);
        $normalized_ident_parts = self::get_identifier_parts($normalized_ident);
        if (count($normalized_calling_ident_parts) < count($normalized_ident_parts)) {
            return false;
        }
        for ($i = 0; $i < count($normalized_ident_parts); ++$i) {
            if ($normalized_ident_parts[$i] !== $normalized_calling_ident_parts[$i]) {
                return false;
            }
        }
        return true;
    }
    /**
     * Returns true if $calling_identifier is the same as or is within any identifier
     * in $identifiers in a case-insensitive comparison, or if $identifiers is empty.
     * Identifiers can be namespaces, classlikes, functions, or methods.
     *
     * @psalm-pure
     * @psalm-assert-if-false !empty $identifiers
     * @param list<string> $identifiers
     */
    public static function is_within_any(string $calling_identifier, array $identifiers): bool
    {
        if (count($identifiers) === 0) {
            return true;
        }
        foreach ($identifiers as $identifier) {
            if (self::is_within($calling_identifier, $identifier)) {
                return true;
            }
        }
        return false;
    }
    /**
     * @param non-empty-string $fullyQualifiedClassName e.g. '\Psalm\Internal\Analyzer\NamespaceAnalyzer'
     * @return non-empty-string , e.g. 'Psalm'
     * @psalm-pure
     */
    public static function get_name_space_root(string $fully_qualified_class_name): string
    {
        $root_namespace = (string) preg_replace('/^([^\\\\]+).*/', '$1', $fully_qualified_class_name, 1);
        if ($root_namespace === "") {
            throw new InvalidArgumentException("Invalid classname \"{$fully_qualified_class_name}\"");
        }
        return $root_namespace;
    }
    /**
     * @return ($lowercase is true ? lowercase-string : string)
     * @psalm-pure
     */
    public static function normalize_identifier(string $identifier, bool $lowercase = true): string
    {
        if ($identifier === "") {
            return "";
        }
        $identifier = $identifier[0] === "\\" ? substr($identifier, 1) : $identifier;
        return $lowercase ? strtolower($identifier) : $identifier;
    }
    /**
     * Splits an identifier into parts, eg `Foo\Bar::baz` becomes ["Foo", "\\", "Bar", "::", "baz"].
     *
     * @return list<non-empty-string>
     * @psalm-pure
     */
    public static function get_identifier_parts(string $identifier): array
    {
        $parts = [];
        while (($pos = strpos($identifier, "\\")) !== false) {
            if ($pos > 0) {
                $part = substr($identifier, 0, $pos);
                assert($part !== "");
                $parts[] = $part;
            }
            $parts[] = "\\";
            $identifier = substr($identifier, $pos + 1);
        }
        if (($pos = strpos($identifier, "::")) !== false) {
            if ($pos > 0) {
                $part = substr($identifier, 0, $pos);
                assert($part !== "");
                $parts[] = $part;
            }
            $parts[] = "::";
            $identifier = substr($identifier, $pos + 2);
        }
        if ($identifier !== "") {
            $parts[] = $identifier;
        }
        return $parts;
    }
}