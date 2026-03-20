<?php

declare (strict_types=1);
namespace Psalm\Internal\Codebase;

use InvalidArgumentException;
use Php_Parser;
use Php_Parser\Node_Traverser;
use Psalm\Code_Location;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\Exception\Unpopulated_Classlike_Exception;
use Psalm\File_Manipulation;
use Psalm\Internal\Analyzer\Class_Like_Analyzer;
use Psalm\Internal\Analyzer\Project_Analyzer;
use Psalm\Internal\Analyzer\Statements_Analyzer;
use Psalm\Internal\File_Manipulation\Class_Docblock_Manipulator;
use Psalm\Internal\File_Manipulation\Code_Migration;
use Psalm\Internal\File_Manipulation\File_Manipulation_Buffer;
use Psalm\Internal\Method_Identifier;
use Psalm\Internal\Php_Visitor\Trait_Finder;
use Psalm\Internal\Provider\Class_Like_Storage_Provider;
use Psalm\Internal\Provider\File_Reference_Provider;
use Psalm\Internal\Type\Type_Expander;
use Psalm\Issue\Class_Must_Be_Final;
use Psalm\Issue\Possibly_Unused_Method;
use Psalm\Issue\Possibly_Unused_Param;
use Psalm\Issue\Possibly_Unused_Property;
use Psalm\Issue\Possibly_Unused_Return_Value;
use Psalm\Issue\Unused_Class;
use Psalm\Issue\Unused_Constructor;
use Psalm\Issue\Unused_Method;
use Psalm\Issue\Unused_Param;
use Psalm\Issue\Unused_Property;
use Psalm\Issue\Unused_Return_Value;
use Psalm\Issue_Buffer;
use Psalm\Node\Virtual_Node;
use Psalm\Progress\Progress;
use Psalm\Progress\Void_Progress;
use Psalm\Statements_Source;
use Psalm\Storage\Class_Constant_Storage;
use Psalm\Storage\Class_Like_Storage;
use Psalm\Type;
use Psalm\Type\Atomic\T_Enum_Case;
use Psalm\Type\Union;
use ReflectionClass;
use ReflectionProperty;
use UnexpectedValueException;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_pop;
use function count;
use function end;
use function explode;
use function get_declared_classes;
use function get_declared_interfaces;
use function implode;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function substr;
use const PHP_EOL;
/**
 * @internal
 *
 * Handles information about classes, interfaces and traits
 */
final class Class_Likes
{
    /**
     * @var array<lowercase-string, bool>
     */
    private array $existing_classlikes_lc = [];
    /**
     * @var array<lowercase-string, bool>
     */
    private array $existing_classes_lc = [];
    /**
     * @var array<string, bool>
     */
    private array $existing_classes = [];
    /**
     * @var array<lowercase-string, bool>
     */
    private array $existing_interfaces_lc = [];
    /**
     * @var array<string, bool>
     */
    private array $existing_interfaces = [];
    /**
     * @var array<lowercase-string, bool>
     */
    private array $existing_traits_lc = [];
    /**
     * @var array<string, bool>
     */
    private array $existing_traits = [];
    /**
     * @var array<lowercase-string, bool>
     */
    private array $existing_enums_lc = [];
    /**
     * @var array<string, bool>
     */
    private array $existing_enums = [];
    /**
     * @var array<lowercase-string, string>
     */
    private array $classlike_aliases_map = [];
    /**
     * @var array<string, bool>
     */
    private array $existing_classlike_aliases = [];
    /**
     * @var array<string, PhpParser\Node\Stmt\Trait_>
     */
    private array $trait_nodes = [];
    public bool $collect_references = false;
    public bool $collect_locations = false;
    public function __construct(private readonly Config $config, private readonly Class_Like_Storage_Provider $classlike_storage_provider, public File_Reference_Provider $file_reference_provider, private readonly Scanner $scanner)
    {
        $this->collect_predefined_class_likes();
    }
    private function collect_predefined_class_likes(): void
    {
        /** @var array<int, string> */
        $predefined_classes = get_declared_classes();
        foreach ($predefined_classes as $predefined_class) {
            $predefined_class = (string) preg_replace('/^\\\\/', '', $predefined_class, 1);
            /** @psalm-suppress ArgumentTypeCoercion */
            $reflection_class = new ReflectionClass($predefined_class);
            if (!$reflection_class->is_user_defined() && $reflection_class->name === $predefined_class) {
                $predefined_class_lc = strtolower($predefined_class);
                $this->existing_classlikes_lc[$predefined_class_lc] = true;
                $this->existing_classes_lc[$predefined_class_lc] = true;
                $this->existing_classes[$predefined_class] = true;
            }
        }
        /** @var array<int, string> */
        $predefined_interfaces = get_declared_interfaces();
        foreach ($predefined_interfaces as $predefined_interface) {
            $predefined_interface = (string) preg_replace('/^\\\\/', '', $predefined_interface, 1);
            /** @psalm-suppress ArgumentTypeCoercion */
            $reflection_class = new ReflectionClass($predefined_interface);
            if (!$reflection_class->is_user_defined() && $reflection_class->name === $predefined_interface) {
                $predefined_interface_lc = strtolower($predefined_interface);
                $this->existing_classlikes_lc[$predefined_interface_lc] = true;
                $this->existing_interfaces_lc[$predefined_interface_lc] = true;
                $this->existing_interfaces[$predefined_interface] = true;
            }
        }
    }
    public function add_fully_qualified_class_name(string $fq_class_name, ?string $file_path = null): void
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = true;
        $this->existing_classes[$fq_class_name] = true;
        $this->existing_traits_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces_lc[$fq_class_name_lc] = false;
        $this->existing_enums_lc[$fq_class_name_lc] = false;
        if ($file_path) {
            $this->scanner->set_class_like_file_path($fq_class_name_lc, $file_path);
        }
    }
    public function add_fully_qualified_interface_name(string $fq_class_name, ?string $file_path = null): void
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_interfaces_lc[$fq_class_name_lc] = true;
        $this->existing_interfaces[$fq_class_name] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = false;
        $this->existing_traits_lc[$fq_class_name_lc] = false;
        $this->existing_enums_lc[$fq_class_name_lc] = false;
        if ($file_path) {
            $this->scanner->set_class_like_file_path($fq_class_name_lc, $file_path);
        }
    }
    public function add_fully_qualified_trait_name(string $fq_class_name, ?string $file_path = null): void
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_traits_lc[$fq_class_name_lc] = true;
        $this->existing_traits[$fq_class_name] = true;
        $this->existing_classes_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces_lc[$fq_class_name_lc] = false;
        $this->existing_enums[$fq_class_name] = false;
        if ($file_path) {
            $this->scanner->set_class_like_file_path($fq_class_name_lc, $file_path);
        }
    }
    public function add_fully_qualified_enum_name(string $fq_class_name, ?string $file_path = null): void
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        $this->existing_classlikes_lc[$fq_class_name_lc] = true;
        $this->existing_enums_lc[$fq_class_name_lc] = true;
        $this->existing_enums[$fq_class_name] = true;
        $this->existing_traits_lc[$fq_class_name_lc] = false;
        $this->existing_classes_lc[$fq_class_name_lc] = false;
        $this->existing_interfaces_lc[$fq_class_name_lc] = false;
        if ($file_path) {
            $this->scanner->set_class_like_file_path($fq_class_name_lc, $file_path);
        }
    }
    public function add_fully_qualified_class_like_name(string $fq_class_name_lc, ?string $file_path = null): void
    {
        if ($file_path) {
            $this->scanner->set_class_like_file_path($fq_class_name_lc, $file_path);
        }
    }
    /**
     * @return list<string>
     */
    public function get_matching_class_like_names(string $stub): array
    {
        $matching_classes = [];
        if ($stub[0] === '*') {
            $stub = substr($stub, 1);
        }
        $fully_qualified = false;
        if ($stub[0] === '\\') {
            $fully_qualified = true;
            $stub = substr($stub, 1);
        } else {
            // for any not-fully-qualified class name the bit we care about comes after a dash
            [, $stub] = explode('-', $stub);
        }
        $stub = preg_quote(strtolower($stub));
        if ($fully_qualified) {
            $stub = '^' . $stub;
        } else {
            $stub = '(^|\\\\)' . $stub;
        }
        foreach ($this->existing_classes as $fq_classlike_name => $found) {
            if (!$found) {
                continue;
            }
            if (preg_match('@' . $stub . '.*@i', $fq_classlike_name)) {
                $matching_classes[] = $fq_classlike_name;
            }
        }
        foreach ($this->existing_interfaces as $fq_classlike_name => $found) {
            if (!$found) {
                continue;
            }
            if (preg_match('@' . $stub . '.*@i', $fq_classlike_name)) {
                $matching_classes[] = $fq_classlike_name;
            }
        }
        return $matching_classes;
    }
    public function has_fully_qualified_class_name(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        $fq_class_name_lc = strtolower($this->get_un_aliased_name($fq_class_name));
        if ($code_location) {
            if ($calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class($calling_method_id, $fq_class_name_lc);
            } elseif (!$calling_fq_class_name || strtolower($calling_fq_class_name) !== $fq_class_name_lc) {
                $this->file_reference_provider->add_non_method_reference_to_class($code_location->file_path, $fq_class_name_lc);
                if ($calling_fq_class_name) {
                    $class_storage = $this->classlike_storage_provider->get($calling_fq_class_name);
                    if ($class_storage->location && $class_storage->location->file_path !== $code_location->file_path) {
                        $this->file_reference_provider->add_non_method_reference_to_class($class_storage->location->file_path, $fq_class_name_lc);
                    }
                }
            }
        }
        // fixme: this looks like a crazy caching hack
        if (!isset($this->existing_classes_lc[$fq_class_name_lc]) || !$this->existing_classes_lc[$fq_class_name_lc] || !$this->classlike_storage_provider->has($fq_class_name_lc)) {
            if ((!isset($this->existing_classes_lc[$fq_class_name_lc]) || $this->existing_classes_lc[$fq_class_name_lc]) && !$this->classlike_storage_provider->has($fq_class_name_lc)) {
                if (!isset($this->existing_classes_lc[$fq_class_name_lc])) {
                    $this->existing_classes_lc[$fq_class_name_lc] = false;
                    return false;
                }
                return $this->existing_classes_lc[$fq_class_name_lc];
            }
            return false;
        }
        if ($this->collect_locations && $code_location) {
            $this->file_reference_provider->add_calling_location_for_class($code_location, strtolower($fq_class_name));
        }
        return true;
    }
    public function has_fully_qualified_interface_name(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        $fq_class_name_lc = strtolower($this->get_un_aliased_name($fq_class_name));
        // fixme: this looks like a crazy caching hack
        if (!isset($this->existing_interfaces_lc[$fq_class_name_lc]) || !$this->existing_interfaces_lc[$fq_class_name_lc] || !$this->classlike_storage_provider->has($fq_class_name_lc)) {
            if ((!isset($this->existing_interfaces_lc[$fq_class_name_lc]) || $this->existing_interfaces_lc[$fq_class_name_lc]) && !$this->classlike_storage_provider->has($fq_class_name_lc)) {
                if (!isset($this->existing_interfaces_lc[$fq_class_name_lc])) {
                    $this->existing_interfaces_lc[$fq_class_name_lc] = false;
                    return false;
                }
                return $this->existing_interfaces_lc[$fq_class_name_lc];
            }
            return false;
        }
        if ($this->collect_references && $code_location) {
            if ($calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class($calling_method_id, $fq_class_name_lc);
            } else {
                $this->file_reference_provider->add_non_method_reference_to_class($code_location->file_path, $fq_class_name_lc);
                if ($calling_fq_class_name) {
                    $class_storage = $this->classlike_storage_provider->get($calling_fq_class_name);
                    if ($class_storage->location && $class_storage->location->file_path !== $code_location->file_path) {
                        $this->file_reference_provider->add_non_method_reference_to_class($class_storage->location->file_path, $fq_class_name_lc);
                    }
                }
            }
        }
        if ($this->collect_locations && $code_location) {
            $this->file_reference_provider->add_calling_location_for_class($code_location, strtolower($fq_class_name));
        }
        return true;
    }
    public function has_fully_qualified_enum_name(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        $fq_class_name_lc = strtolower($this->get_un_aliased_name($fq_class_name));
        // fixme: this looks like a crazy caching hack
        if (!isset($this->existing_enums_lc[$fq_class_name_lc]) || !$this->existing_enums_lc[$fq_class_name_lc] || !$this->classlike_storage_provider->has($fq_class_name_lc)) {
            if ((!isset($this->existing_enums_lc[$fq_class_name_lc]) || $this->existing_enums_lc[$fq_class_name_lc]) && !$this->classlike_storage_provider->has($fq_class_name_lc)) {
                if (!isset($this->existing_enums_lc[$fq_class_name_lc])) {
                    $this->existing_enums_lc[$fq_class_name_lc] = false;
                    return false;
                }
                return $this->existing_enums_lc[$fq_class_name_lc];
            }
            return false;
        }
        if ($this->collect_references && $code_location) {
            if ($calling_method_id) {
                $this->file_reference_provider->add_method_reference_to_class($calling_method_id, $fq_class_name_lc);
            } else {
                $this->file_reference_provider->add_non_method_reference_to_class($code_location->file_path, $fq_class_name_lc);
                if ($calling_fq_class_name) {
                    $class_storage = $this->classlike_storage_provider->get($calling_fq_class_name);
                    if ($class_storage->location && $class_storage->location->file_path !== $code_location->file_path) {
                        $this->file_reference_provider->add_non_method_reference_to_class($class_storage->location->file_path, $fq_class_name_lc);
                    }
                }
            }
        }
        if ($this->collect_locations && $code_location) {
            $this->file_reference_provider->add_calling_location_for_class($code_location, strtolower($fq_class_name));
        }
        return true;
    }
    public function has_fully_qualified_trait_name(string $fq_class_name, ?Code_Location $code_location = null): bool
    {
        $fq_class_name_lc = strtolower($this->get_un_aliased_name($fq_class_name));
        if (!isset($this->existing_traits_lc[$fq_class_name_lc]) || !$this->existing_traits_lc[$fq_class_name_lc]) {
            return false;
        }
        if ($this->collect_references && $code_location) {
            $this->file_reference_provider->add_non_method_reference_to_class($code_location->file_path, $fq_class_name_lc);
        }
        return true;
    }
    /**
     * Check whether a class/interface exists
     */
    public function class_or_interface_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        if ($this->class_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id)) {
            return true;
        }
        return $this->interface_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /**
     * Check whether a class/interface exists
     */
    public function class_or_interface_or_enum_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        if ($this->class_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id)) {
            return true;
        }
        if ($this->interface_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id)) {
            return true;
        }
        return $this->enum_exists($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /**
     * Determine whether or not a given class exists
     */
    public function class_exists(string $fq_class_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        if (isset(Class_Like_Analyzer::SPECIAL_TYPES[$fq_class_name])) {
            return false;
        }
        if ($fq_class_name === 'Generator') {
            return true;
        }
        return $this->has_fully_qualified_class_name($fq_class_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    /**
     * Determine whether or not a class extends a parent
     *
     * @psalm-mutation-free
     * @throws UnpopulatedClasslikeException when called on unpopulated class
     * @throws InvalidArgumentException when class does not exist
     */
    public function class_extends(string $fq_class_name, string $possible_parent, bool $from_api = false): bool
    {
        $unaliased_fq_class_name = $this->get_un_aliased_name($fq_class_name);
        $unaliased_fq_class_name_lc = strtolower($unaliased_fq_class_name);
        if ($unaliased_fq_class_name_lc === 'generator') {
            return false;
        }
        $class_storage = $this->classlike_storage_provider->get($unaliased_fq_class_name);
        if ($from_api && !$class_storage->populated) {
            throw new Unpopulated_Classlike_Exception($fq_class_name);
        }
        return isset($class_storage->parent_classes[strtolower($possible_parent)]);
    }
    /**
     * Check whether a class implements an interface
     *
     * @psalm-mutation-free
     */
    public function class_implements(string $fq_class_name, string $interface): bool
    {
        $interface_id = strtolower($interface);
        $fq_class_name = strtolower($fq_class_name);
        if ($interface_id === 'callable' && $fq_class_name === 'closure') {
            return true;
        }
        if ($interface_id === 'traversable' && $fq_class_name === 'generator') {
            return true;
        }
        if ($interface_id === 'traversable' && $fq_class_name === 'iterator') {
            return true;
        }
        if (isset(Class_Like_Analyzer::SPECIAL_TYPES[$interface_id]) || isset(Class_Like_Analyzer::SPECIAL_TYPES[$fq_class_name])) {
            return false;
        }
        $fq_class_name = $this->get_un_aliased_name($fq_class_name);
        if (!$this->classlike_storage_provider->has($fq_class_name)) {
            return false;
        }
        $class_storage = $this->classlike_storage_provider->get($fq_class_name);
        if (isset($class_storage->class_implements[$interface_id])) {
            return true;
        }
        foreach ($class_storage->class_implements as $implementing_interface_lc => $_) {
            $aliased_interface_lc = strtolower($this->get_un_aliased_name($implementing_interface_lc));
            if ($aliased_interface_lc === $interface_id) {
                return true;
            }
        }
        return false;
    }
    public function interface_exists(string $fq_interface_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        if (isset(Class_Like_Analyzer::SPECIAL_TYPES[strtolower($fq_interface_name)])) {
            return false;
        }
        return $this->has_fully_qualified_interface_name($fq_interface_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    public function enum_exists(string $fq_enum_name, ?Code_Location $code_location = null, ?string $calling_fq_class_name = null, ?string $calling_method_id = null): bool
    {
        if (isset(Class_Like_Analyzer::SPECIAL_TYPES[strtolower($fq_enum_name)])) {
            return false;
        }
        return $this->has_fully_qualified_enum_name($fq_enum_name, $code_location, $calling_fq_class_name, $calling_method_id);
    }
    public function interface_extends(string $interface_name, string $possible_parent): bool
    {
        return isset($this->get_parent_interfaces($interface_name)[strtolower($possible_parent)]);
    }
    /**
     * @return array<lowercase-string, string>   all interfaces extended by $interface_name
     */
    public function get_parent_interfaces(string $fq_interface_name): array
    {
        $fq_interface_name = strtolower($fq_interface_name);
        return $this->classlike_storage_provider->get($fq_interface_name)->parent_interfaces;
    }
    public function trait_exists(string $fq_trait_name, ?Code_Location $code_location = null): bool
    {
        return $this->has_fully_qualified_trait_name($fq_trait_name, $code_location);
    }
    /**
     * Determine whether or not a class has the correct casing
     */
    public function class_has_correct_casing(string $fq_class_name): bool
    {
        if ($fq_class_name === 'Generator') {
            return true;
        }
        if (isset($this->existing_classlike_aliases[$fq_class_name])) {
            return true;
        }
        return isset($this->existing_classes[$fq_class_name]);
    }
    public function interface_has_correct_casing(string $fq_interface_name): bool
    {
        if (isset($this->existing_classlike_aliases[$fq_interface_name])) {
            return true;
        }
        return isset($this->existing_interfaces[$fq_interface_name]);
    }
    public function enum_has_correct_casing(string $fq_enum_name): bool
    {
        if (isset($this->existing_classlike_aliases[$fq_enum_name])) {
            return true;
        }
        return isset($this->existing_enums[$fq_enum_name]);
    }
    public function trait_has_correct_casing(string $fq_trait_name): bool
    {
        if (isset($this->existing_classlike_aliases[$fq_trait_name])) {
            return true;
        }
        return isset($this->existing_traits[$fq_trait_name]);
    }
    public function get_trait_node(string $fq_trait_name): Php_Parser\Node\Stmt\Trait_
    {
        $fq_trait_name_lc = strtolower($fq_trait_name);
        if (isset($this->trait_nodes[$fq_trait_name_lc])) {
            return $this->trait_nodes[$fq_trait_name_lc];
        }
        $storage = $this->classlike_storage_provider->get($fq_trait_name);
        if (!$storage->location) {
            throw new UnexpectedValueException('Storage should exist for ' . $fq_trait_name);
        }
        $codebase = Project_Analyzer::get_instance()->get_codebase();
        $file_statements = $codebase->get_statements_for_file($storage->location->file_path);
        $trait_finder = new Trait_Finder($fq_trait_name);
        $traverser = new Node_Traverser();
        $traverser->add_visitor($trait_finder);
        $traverser->traverse($file_statements);
        $trait_node = $trait_finder->get_node();
        if ($trait_node) {
            $this->trait_nodes[$fq_trait_name_lc] = $trait_node;
            return $trait_node;
        }
        throw new UnexpectedValueException("Could not locate trait statement for {$fq_trait_name}");
    }
    public function add_class_alias(string $fq_class_name, string $alias_name): void
    {
        $this->classlike_aliases_map[strtolower($alias_name)] = $fq_class_name;
        $this->existing_classlike_aliases[$alias_name] = true;
    }
    /** @psalm-mutation-free */
    public function get_un_aliased_name(string $alias_name): string
    {
        $alias_name_lc = strtolower($alias_name);
        if ($this->existing_classlikes_lc[$alias_name_lc] ?? false) {
            return $alias_name;
        }
        $result = $this->classlike_aliases_map[$alias_name_lc] ?? $alias_name;
        if ($result === $alias_name) {
            return $result;
        }
        return $this->get_un_aliased_name($result);
    }
    public function consolidate_analyzed_data(Methods $methods, ?Progress $progress, bool $find_unused_code): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $progress->debug('Checking class references' . PHP_EOL);
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        foreach ($this->existing_classlikes_lc as $fq_class_name_lc => $_) {
            try {
                $classlike_storage = $this->classlike_storage_provider->get($fq_class_name_lc);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($classlike_storage->location && $this->config->is_in_project_dirs($classlike_storage->location->file_path) && !$classlike_storage->is_trait) {
                if ($find_unused_code) {
                    if ($classlike_storage->public_api || $this->file_reference_provider->is_class_referenced($fq_class_name_lc)) {
                        $this->check_method_references($classlike_storage, $methods);
                        $this->check_property_references($classlike_storage);
                    } else {
                        Issue_Buffer::maybe_add(new Unused_Class('Class ' . $classlike_storage->name . ' is never used', $classlike_storage->location, $classlike_storage->name), $classlike_storage->suppressed_issues);
                    }
                    $this->check_method_param_references($classlike_storage);
                }
                if (!$classlike_storage->public_api && !$classlike_storage->has_children && !$classlike_storage->abstract && !$classlike_storage->final && !$classlike_storage->is_enum && !$classlike_storage->is_interface) {
                    Issue_Buffer::maybe_add(new Class_Must_Be_Final('Class ' . $classlike_storage->name . ' is never extended and is not part of the public API, and thus must be made final.', $classlike_storage->location, $classlike_storage->name), $classlike_storage->suppressed_issues, true);
                    if ($codebase->alter_code && $classlike_storage->stmt_location !== null && isset($project_analyzer->get_issues_to_fix()['ClassMustBeFinal'])) {
                        $selection = $classlike_storage->stmt_location->get_snippet();
                        $insert_pos = strpos($selection, "class");
                        if ($insert_pos === false) {
                            $insert_pos = $classlike_storage->stmt_location->get_selection_bounds()[0];
                        }
                        File_Manipulation_Buffer::add($classlike_storage->stmt_location->file_path, [new File_Manipulation($insert_pos, $insert_pos, 'final ', true)]);
                    }
                }
                $this->find_possible_method_param_types($classlike_storage);
                if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingImmutableAnnotation']) && !isset($codebase->analyzer->mutable_classes[$fq_class_name_lc]) && !$classlike_storage->external_mutation_free && $classlike_storage->properties && isset($classlike_storage->methods['__construct'])) {
                    $stmts = $codebase->get_statements_for_file($classlike_storage->location->file_path);
                    foreach ($stmts as $stmt) {
                        if ($stmt instanceof Php_Parser\Node\Stmt\Namespace_) {
                            foreach ($stmt->stmts as $namespace_stmt) {
                                if ($namespace_stmt instanceof Php_Parser\Node\Stmt\Class_ && strtolower($stmt->name . '\\' . $namespace_stmt->name) === $fq_class_name_lc) {
                                    self::make_immutable($namespace_stmt, $project_analyzer, $classlike_storage->location->file_path);
                                }
                            }
                        } elseif ($stmt instanceof Php_Parser\Node\Stmt\Class_ && strtolower((string) $stmt->name) === $fq_class_name_lc) {
                            self::make_immutable($stmt, $project_analyzer, $classlike_storage->location->file_path);
                        }
                    }
                }
            }
        }
    }
    public static function make_immutable(Php_Parser\Node\Stmt\Class_ $class_stmt, Project_Analyzer $project_analyzer, string $file_path): void
    {
        $manipulator = Class_Docblock_Manipulator::get_for_class($project_analyzer, $file_path, $class_stmt);
        $manipulator->make_immutable();
    }
    public function move_methods(Methods $methods, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        if (!$codebase->methods_to_move) {
            return;
        }
        $progress->debug('Refactoring methods ' . PHP_EOL);
        $code_migrations = [];
        foreach ($codebase->methods_to_move as $source => $destination) {
            $source_parts = explode('::', $source);
            try {
                $source_method_storage = $methods->get_storage(new Method_Identifier(...$source_parts));
            } catch (InvalidArgumentException) {
                continue;
            }
            [$destination_fq_class_name, $destination_name] = explode('::', $destination);
            try {
                $classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($classlike_storage->stmt_location && $this->config->is_in_project_dirs($classlike_storage->stmt_location->file_path) && $source_method_storage->stmt_location && $source_method_storage->stmt_location->file_path && $source_method_storage->location) {
                $new_class_bounds = $classlike_storage->stmt_location->get_snippet_bounds();
                $old_method_bounds = $source_method_storage->stmt_location->get_snippet_bounds();
                $old_method_name_bounds = $source_method_storage->location->get_selection_bounds();
                File_Manipulation_Buffer::add($source_method_storage->stmt_location->file_path, [new File_Manipulation($old_method_name_bounds[0], $old_method_name_bounds[1], $destination_name)]);
                $selection = $classlike_storage->stmt_location->get_snippet();
                $insert_pos = strrpos($selection, "\n", -1);
                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }
                $code_migrations[] = new Code_Migration($source_method_storage->stmt_location->file_path, $old_method_bounds[0], $old_method_bounds[1], $classlike_storage->stmt_location->file_path, $new_class_bounds[0] + $insert_pos);
            }
        }
        File_Manipulation_Buffer::add_code_migrations($code_migrations);
    }
    public function move_properties(Properties $properties, ?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        if (!$codebase->properties_to_move) {
            return;
        }
        $progress->debug('Refacting properties ' . PHP_EOL);
        $code_migrations = [];
        foreach ($codebase->properties_to_move as $source => $destination) {
            try {
                $source_property_storage = $properties->get_storage($source);
            } catch (InvalidArgumentException) {
                continue;
            }
            [$source_fq_class_name] = explode('::$', $source);
            [$destination_fq_class_name, $destination_name] = explode('::$', $destination);
            $source_classlike_storage = $this->classlike_storage_provider->get($source_fq_class_name);
            $destination_classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);
            if ($destination_classlike_storage->stmt_location && $this->config->is_in_project_dirs($destination_classlike_storage->stmt_location->file_path) && $source_property_storage->stmt_location && $source_property_storage->stmt_location->file_path && $source_property_storage->location) {
                if ($source_property_storage->type && $source_property_storage->type_location && $source_property_storage->type_location !== $source_property_storage->signature_type_location) {
                    $bounds = $source_property_storage->type_location->get_selection_bounds();
                    $replace_type = Type_Expander::expand_union($codebase, $source_property_storage->type, $source_classlike_storage->name, $source_classlike_storage->name, $source_classlike_storage->parent_class);
                    $this->airlift_class_defined_docblock_type($replace_type, $destination_fq_class_name, $source_property_storage->stmt_location->file_path, $bounds[0], $bounds[1]);
                }
                $new_class_bounds = $destination_classlike_storage->stmt_location->get_snippet_bounds();
                $old_property_bounds = $source_property_storage->stmt_location->get_snippet_bounds();
                $old_property_name_bounds = $source_property_storage->location->get_selection_bounds();
                File_Manipulation_Buffer::add($source_property_storage->stmt_location->file_path, [new File_Manipulation($old_property_name_bounds[0], $old_property_name_bounds[1], '$' . $destination_name)]);
                $selection = $destination_classlike_storage->stmt_location->get_snippet();
                $insert_pos = strrpos($selection, "\n", -1);
                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }
                $code_migrations[] = new Code_Migration($source_property_storage->stmt_location->file_path, $old_property_bounds[0], $old_property_bounds[1], $destination_classlike_storage->stmt_location->file_path, $new_class_bounds[0] + $insert_pos);
            }
        }
        File_Manipulation_Buffer::add_code_migrations($code_migrations);
    }
    public function move_class_constants(?Progress $progress = null): void
    {
        if ($progress === null) {
            $progress = new Void_Progress();
        }
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        if (!$codebase->class_constants_to_move) {
            return;
        }
        $progress->debug('Refacting constants ' . PHP_EOL);
        $code_migrations = [];
        foreach ($codebase->class_constants_to_move as $source => $destination) {
            [$source_fq_class_name, $source_const_name] = explode('::', $source);
            [$destination_fq_class_name, $destination_name] = explode('::', $destination);
            $source_classlike_storage = $this->classlike_storage_provider->get($source_fq_class_name);
            $destination_classlike_storage = $this->classlike_storage_provider->get($destination_fq_class_name);
            $constant_storage = $source_classlike_storage->constants[$source_const_name];
            $source_const_stmt_location = $constant_storage->stmt_location;
            $source_const_location = $constant_storage->location;
            if (!$source_const_location) {
                continue;
            }
            if (!$source_const_stmt_location) {
                continue;
            }
            if ($destination_classlike_storage->stmt_location && $this->config->is_in_project_dirs($destination_classlike_storage->stmt_location->file_path) && $source_const_stmt_location->file_path) {
                $new_class_bounds = $destination_classlike_storage->stmt_location->get_snippet_bounds();
                $old_const_bounds = $source_const_stmt_location->get_snippet_bounds();
                $old_const_name_bounds = $source_const_location->get_selection_bounds();
                File_Manipulation_Buffer::add($source_const_stmt_location->file_path, [new File_Manipulation($old_const_name_bounds[0], $old_const_name_bounds[1], $destination_name)]);
                $selection = $destination_classlike_storage->stmt_location->get_snippet();
                $insert_pos = strrpos($selection, "\n", -1);
                if (!$insert_pos) {
                    $insert_pos = strlen($selection) - 1;
                } else {
                    ++$insert_pos;
                }
                $code_migrations[] = new Code_Migration($source_const_stmt_location->file_path, $old_const_bounds[0], $old_const_bounds[1], $destination_classlike_storage->stmt_location->file_path, $new_class_bounds[0] + $insert_pos);
            }
        }
        File_Manipulation_Buffer::add_code_migrations($code_migrations);
    }
    /**
     * @param lowercase-string|null $calling_method_id
     */
    public function handle_class_like_reference_in_migration(Codebase $codebase, Statements_Source $source, Php_Parser\Node $class_name_node, string $fq_class_name, ?string $calling_method_id, bool $force_change = false, bool $was_self = false): bool
    {
        if ($class_name_node instanceof Virtual_Node) {
            return false;
        }
        $calling_fq_class_name = $source->get_fqcln();
        // if we're inside a moved class static method
        if ($codebase->methods_to_move && $calling_fq_class_name && $calling_method_id && isset($codebase->methods_to_move[$calling_method_id])) {
            $destination_class = explode('::', $codebase->methods_to_move[$calling_method_id])[0];
            $intended_fq_class_name = strtolower($calling_fq_class_name) === strtolower($fq_class_name) && isset($codebase->classes_to_move[strtolower($calling_fq_class_name)]) ? $destination_class : $fq_class_name;
            $this->airlift_class_like_reference($intended_fq_class_name, $destination_class, $source->get_file_path(), (int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, $class_name_node instanceof Php_Parser\Node\Scalar\Magic_Const\Class_, $was_self);
            return true;
        }
        // if we're outside a moved class, but we're changing all references to a class
        if (isset($codebase->class_transforms[strtolower($fq_class_name)])) {
            $new_fq_class_name = $codebase->class_transforms[strtolower($fq_class_name)];
            $file_manipulations = [];
            if ($class_name_node instanceof Php_Parser\Node\Identifier) {
                $destination_parts = explode('\\', $new_fq_class_name);
                $destination_class_name = array_pop($destination_parts);
                $file_manipulations[] = new File_Manipulation((int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, $destination_class_name);
                File_Manipulation_Buffer::add($source->get_file_path(), $file_manipulations);
                return true;
            }
            $uses_flipped = $source->get_aliased_classes_flipped();
            $uses_flipped_replaceable = $source->get_aliased_classes_flipped_replaceable();
            $old_fq_class_name = strtolower($fq_class_name);
            $migrated_source_fqcln = $calling_fq_class_name;
            if ($calling_fq_class_name && isset($codebase->class_transforms[strtolower($calling_fq_class_name)])) {
                $migrated_source_fqcln = $codebase->class_transforms[strtolower($calling_fq_class_name)];
            }
            $source_namespace = $source->get_namespace();
            if ($migrated_source_fqcln && $calling_fq_class_name !== $migrated_source_fqcln) {
                $new_source_parts = explode('\\', $migrated_source_fqcln, -1);
                $source_namespace = implode('\\', $new_source_parts);
            }
            if (isset($uses_flipped_replaceable[$old_fq_class_name])) {
                $alias = $uses_flipped_replaceable[$old_fq_class_name];
                unset($uses_flipped[$old_fq_class_name]);
                $old_class_name_parts = explode('\\', $old_fq_class_name);
                $old_class_name = end($old_class_name_parts);
                if ($old_class_name === strtolower($alias)) {
                    $new_class_name_parts = explode('\\', $new_fq_class_name);
                    $new_class_name = end($new_class_name_parts);
                    $uses_flipped[strtolower($new_fq_class_name)] = $new_class_name;
                } else {
                    $uses_flipped[strtolower($new_fq_class_name)] = $alias;
                }
            }
            $file_manipulations[] = new File_Manipulation((int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, Type::get_string_from_fqcln($new_fq_class_name, $source_namespace, $uses_flipped, $migrated_source_fqcln, $was_self) . ($class_name_node instanceof Php_Parser\Node\Scalar\Magic_Const\Class_ ? '::class' : ''));
            File_Manipulation_Buffer::add($source->get_file_path(), $file_manipulations);
            return true;
        }
        // if we're inside a moved class (could be a method, could be a property/class const default)
        if ($codebase->classes_to_move && $calling_fq_class_name && isset($codebase->classes_to_move[strtolower($calling_fq_class_name)])) {
            $destination_class = $codebase->classes_to_move[strtolower($calling_fq_class_name)];
            if ($class_name_node instanceof Php_Parser\Node\Identifier) {
                $destination_parts = explode('\\', $destination_class);
                $destination_class_name = array_pop($destination_parts);
                $file_manipulations = [];
                $file_manipulations[] = new File_Manipulation((int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, $destination_class_name);
                File_Manipulation_Buffer::add($source->get_file_path(), $file_manipulations);
            } else {
                $this->airlift_class_like_reference(strtolower($calling_fq_class_name) === strtolower($fq_class_name) ? $destination_class : $fq_class_name, $destination_class, $source->get_file_path(), (int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, $class_name_node instanceof Php_Parser\Node\Scalar\Magic_Const\Class_);
            }
            return true;
        }
        if ($force_change) {
            if ($calling_fq_class_name) {
                $this->airlift_class_like_reference($fq_class_name, $calling_fq_class_name, $source->get_file_path(), (int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1);
            } else {
                $file_manipulations = [];
                $file_manipulations[] = new File_Manipulation((int) $class_name_node->get_attribute('startFilePos'), (int) $class_name_node->get_attribute('endFilePos') + 1, Type::get_string_from_fqcln($fq_class_name, $source->get_namespace(), $source->get_aliased_classes_flipped(), null));
                File_Manipulation_Buffer::add($source->get_file_path(), $file_manipulations);
            }
            return true;
        }
        return false;
    }
    /**
     * @param lowercase-string|null $calling_method_id
     */
    public function handle_docblock_type_in_migration(Codebase $codebase, Statements_Source $source, Union $type, Code_Location $type_location, ?string $calling_method_id): void
    {
        $calling_fq_class_name = $source->get_fqcln();
        $fq_class_name_lc = strtolower($calling_fq_class_name ?? '');
        $moved_type = false;
        // if we're inside a moved class static method
        if ($codebase->methods_to_move && $calling_fq_class_name && $calling_method_id && isset($codebase->methods_to_move[$calling_method_id])) {
            $bounds = $type_location->get_selection_bounds();
            $destination_class = explode('::', $codebase->methods_to_move[$calling_method_id])[0];
            $this->airlift_class_defined_docblock_type($type, $destination_class, $source->get_file_path(), $bounds[0], $bounds[1]);
            $moved_type = true;
        }
        // if we're outside a moved class, but we're changing all references to a class
        if (!$moved_type && $codebase->class_transforms) {
            $uses_flipped = $source->get_aliased_classes_flipped();
            $uses_flipped_replaceable = $source->get_aliased_classes_flipped_replaceable();
            $migrated_source_fqcln = $calling_fq_class_name;
            if ($calling_fq_class_name && isset($codebase->class_transforms[$fq_class_name_lc])) {
                $migrated_source_fqcln = $codebase->class_transforms[$fq_class_name_lc];
            }
            $source_namespace = $source->get_namespace();
            if ($migrated_source_fqcln && $calling_fq_class_name !== $migrated_source_fqcln) {
                $new_source_parts = explode('\\', $migrated_source_fqcln, -1);
                $source_namespace = implode('\\', $new_source_parts);
            }
            foreach ($codebase->class_transforms as $old_fq_class_name => $new_fq_class_name) {
                if (isset($uses_flipped_replaceable[$old_fq_class_name])) {
                    $alias = $uses_flipped_replaceable[$old_fq_class_name];
                    unset($uses_flipped[$old_fq_class_name]);
                    $old_class_name_parts = explode('\\', $old_fq_class_name);
                    $old_class_name = end($old_class_name_parts);
                    if ($old_class_name === strtolower($alias)) {
                        $new_class_name_parts = explode('\\', $new_fq_class_name);
                        $new_class_name = end($new_class_name_parts);
                        $uses_flipped[strtolower($new_fq_class_name)] = $new_class_name;
                    } else {
                        $uses_flipped[strtolower($new_fq_class_name)] = $alias;
                    }
                }
            }
            foreach ($codebase->class_transforms as $old_fq_class_name => $new_fq_class_name) {
                if ($type->contains_class_like($old_fq_class_name)) {
                    $type = $type->replace_class_like($old_fq_class_name, $new_fq_class_name);
                    $bounds = $type_location->get_selection_bounds();
                    $file_manipulations = [];
                    $file_manipulations[] = new File_Manipulation($bounds[0], $bounds[1], $type->to_namespaced_string($source_namespace, $uses_flipped, $migrated_source_fqcln, false));
                    File_Manipulation_Buffer::add($source->get_file_path(), $file_manipulations);
                    $moved_type = true;
                }
            }
        }
        // if we're inside a moved class (could be a method, could be a property/class const default)
        if (!$moved_type && $codebase->classes_to_move && $calling_fq_class_name && isset($codebase->classes_to_move[$fq_class_name_lc])) {
            $bounds = $type_location->get_selection_bounds();
            $destination_class = $codebase->classes_to_move[$fq_class_name_lc];
            if ($type->contains_class_like($fq_class_name_lc)) {
                $type = $type->replace_class_like($fq_class_name_lc, $destination_class);
            }
            $this->airlift_class_defined_docblock_type($type, $destination_class, $source->get_file_path(), $bounds[0], $bounds[1]);
        }
    }
    public function airlift_class_like_reference(string $fq_class_name, string $destination_fq_class_name, string $source_file_path, int $source_start, int $source_end, bool $add_class_constant = false, bool $allow_self = false): void
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $destination_class_storage = $codebase->classlike_storage_provider->get($destination_fq_class_name);
        if (!$destination_class_storage->aliases) {
            throw new UnexpectedValueException('Aliases should not be null');
        }
        $file_manipulations = [];
        $file_manipulations[] = new File_Manipulation($source_start, $source_end, Type::get_string_from_fqcln($fq_class_name, $destination_class_storage->aliases->namespace, $destination_class_storage->aliases->uses_flipped, $destination_class_storage->name, $allow_self) . ($add_class_constant ? '::class' : ''));
        File_Manipulation_Buffer::add($source_file_path, $file_manipulations);
    }
    public function airlift_class_defined_docblock_type(Union $type, string $destination_fq_class_name, string $source_file_path, int $source_start, int $source_end): void
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        $destination_class_storage = $codebase->classlike_storage_provider->get($destination_fq_class_name);
        if (!$destination_class_storage->aliases) {
            throw new UnexpectedValueException('Aliases should not be null');
        }
        $file_manipulations = [];
        $file_manipulations[] = new File_Manipulation($source_start, $source_end, $type->to_namespaced_string($destination_class_storage->aliases->namespace, $destination_class_storage->aliases->uses_flipped, $destination_class_storage->name, false));
        File_Manipulation_Buffer::add($source_file_path, $file_manipulations);
    }
    /**
     * @param ReflectionProperty::IS_PUBLIC|ReflectionProperty::IS_PROTECTED|ReflectionProperty::IS_PRIVATE
     *  $visibility
     * @return array<string, ClassConstantStorage>
     */
    public function get_constants_for_class(string $class_name, int $visibility): array
    {
        $class_name = strtolower($class_name);
        $storage = $this->classlike_storage_provider->get($class_name);
        if ($visibility === ReflectionProperty::IS_PUBLIC) {
            return array_filter($storage->constants, static fn(Class_Constant_Storage $constant): bool => $constant->type && $constant->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC);
        }
        if ($visibility === ReflectionProperty::IS_PROTECTED) {
            return array_filter($storage->constants, static fn(Class_Constant_Storage $constant): bool => $constant->type && ($constant->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || $constant->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED));
        }
        return array_filter($storage->constants, static fn(Class_Constant_Storage $constant): bool => $constant->type !== null);
    }
    /**
     * @param ReflectionProperty::IS_PUBLIC|ReflectionProperty::IS_PROTECTED|ReflectionProperty::IS_PRIVATE $visibility
     */
    public function get_class_constant_type(string $class_name, string $constant_name, int $visibility, ?Statements_Analyzer $statements_analyzer = null, array $visited_constant_ids = [], bool $late_static_binding = false, bool $in_value_of_context = false): ?Union
    {
        $class_name = strtolower($class_name);
        if (!$this->classlike_storage_provider->has($class_name)) {
            return null;
        }
        $storage = $this->classlike_storage_provider->get($class_name);
        $enum_types = null;
        if ($storage->is_enum) {
            $enum_types = $this->get_enum_type($storage, $constant_name);
            if ($in_value_of_context) {
                return $enum_types;
            }
        }
        $constant_types = $this->get_constant_type($storage, $constant_name, $visibility, $statements_analyzer, $visited_constant_ids, $late_static_binding);
        $types = [];
        if ($enum_types !== null) {
            $types = array_merge($types, $enum_types->get_atomic_types());
        }
        if ($constant_types !== null) {
            $types = array_merge($types, $constant_types->get_atomic_types());
        }
        if ($types === []) {
            return null;
        }
        return new Union($types);
    }
    private function check_method_references(Class_Like_Storage $classlike_storage, Methods $methods): void
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        foreach ($classlike_storage->appearing_method_ids as $method_name => $appearing_method_id) {
            $appearing_fq_classlike_name = $appearing_method_id->fq_class_name;
            if ($appearing_fq_classlike_name !== $classlike_storage->name) {
                continue;
            }
            $method_id = $appearing_method_id;
            $declaring_classlike_storage = $classlike_storage;
            if (isset($classlike_storage->methods[$method_name])) {
                $method_storage = $classlike_storage->methods[$method_name];
            } else {
                $declaring_method_id = $classlike_storage->declaring_method_ids[$method_name];
                $declaring_fq_classlike_name = $declaring_method_id->fq_class_name;
                $declaring_method_name = $declaring_method_id->method_name;
                try {
                    $declaring_classlike_storage = $this->classlike_storage_provider->get($declaring_fq_classlike_name);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $method_storage = $declaring_classlike_storage->methods[$declaring_method_name];
                $method_id = $declaring_method_id;
            }
            if ($classlike_storage->public_api && ($method_storage->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || $method_storage->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED && !$classlike_storage->final)) {
                continue;
            }
            if ($method_storage->public_api) {
                continue;
            }
            if ($method_storage->location && !$project_analyzer->can_report_issues($method_storage->location->file_path) && !$codebase->analyzer->can_report_issues($method_storage->location->file_path)) {
                continue;
            }
            $method_referenced = $this->file_reference_provider->is_class_method_referenced(strtolower((string) $method_id));
            if (!$method_referenced && $method_storage->location) {
                if ($method_name !== '__destruct' && $method_name !== '__clone' && $method_name !== '__invoke' && $method_name !== '__unset' && $method_name !== '__isset' && $method_name !== '__sleep' && $method_name !== '__wakeup' && $method_name !== '__serialize' && $method_name !== '__unserialize' && $method_name !== '__set_state' && $method_name !== '__debuginfo' && $method_name !== '__tostring') {
                    $method_location = $method_storage->location;
                    $method_id = $classlike_storage->name . '::' . $method_storage->cased_name;
                    if ($method_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                        $has_parent_references = false;
                        if ($codebase->class_implements($classlike_storage->name, 'Serializable') && ($method_name === 'serialize' || $method_name === 'unserialize')) {
                            continue;
                        }
                        if ($codebase->class_implements($classlike_storage->name, 'JsonSerializable') && $method_name === 'jsonserialize') {
                            continue;
                        }
                        $has_variable_calls = $codebase->analyzer->has_mixed_member_name($method_name) || $codebase->analyzer->has_mixed_member_name(strtolower($classlike_storage->name . '::'));
                        if (isset($classlike_storage->overridden_method_ids[$method_name])) {
                            foreach ($classlike_storage->overridden_method_ids[$method_name] as $parent_method_id) {
                                $parent_method_storage = $methods->get_storage($parent_method_id);
                                if ($parent_method_storage->location && !$project_analyzer->can_report_issues($parent_method_storage->location->file_path)) {
                                    // here we just don’t know
                                    $has_parent_references = true;
                                    break;
                                }
                                $parent_method_referenced = $this->file_reference_provider->is_class_method_referenced(strtolower((string) $parent_method_id));
                                if (!$parent_method_storage->abstract || $parent_method_referenced) {
                                    $has_parent_references = true;
                                    break;
                                }
                            }
                        }
                        foreach ($classlike_storage->parent_classes as $parent_method_fqcln) {
                            if ($codebase->analyzer->has_mixed_member_name(strtolower($parent_method_fqcln) . '::')) {
                                $has_variable_calls = true;
                                break;
                            }
                        }
                        foreach ($classlike_storage->class_implements as $fq_interface_name_lc => $_) {
                            try {
                                $interface_storage = $this->classlike_storage_provider->get($fq_interface_name_lc);
                            } catch (InvalidArgumentException) {
                                continue;
                            }
                            if ($codebase->analyzer->has_mixed_member_name($fq_interface_name_lc . '::')) {
                                $has_variable_calls = true;
                            }
                            if (isset($interface_storage->methods[$method_name])) {
                                $interface_method_referenced = $this->file_reference_provider->is_class_method_referenced($fq_interface_name_lc . '::' . $method_name);
                                if ($interface_method_referenced) {
                                    $has_parent_references = true;
                                }
                            }
                        }
                        if (!$has_parent_references) {
                            $issue = new Possibly_Unused_Method('Cannot find ' . ($has_variable_calls ? 'explicit' : 'any') . ' calls to method ' . $method_id . ($has_variable_calls ? ' (but did find some potential callers)' : ''), $method_storage->location, $method_id);
                            if ($codebase->alter_code) {
                                if ($method_storage->stmt_location && !$declaring_classlike_storage->is_trait && isset($project_analyzer->get_issues_to_fix()['PossiblyUnusedMethod']) && !$has_variable_calls && !Issue_Buffer::is_suppressed($issue, $method_storage->suppressed_issues)) {
                                    File_Manipulation_Buffer::add_for_code_location($method_storage->stmt_location, '', true);
                                }
                            } else {
                                Issue_Buffer::maybe_add($issue, $method_storage->suppressed_issues, $method_storage->stmt_location && !$declaring_classlike_storage->is_trait && !$has_variable_calls);
                            }
                        }
                    } elseif (!isset($classlike_storage->declaring_method_ids['__call'])) {
                        $has_variable_calls = $codebase->analyzer->has_mixed_member_name(strtolower($classlike_storage->name . '::')) || $codebase->analyzer->has_mixed_member_name($method_name);
                        if ($method_name === '__construct') {
                            $issue = new Unused_Constructor('Cannot find ' . ($has_variable_calls ? 'explicit' : 'any') . ' calls to private constructor ' . $method_id . ($has_variable_calls ? ' (but did find some potential callers)' : ''), $method_location, $method_id);
                        } else {
                            $issue = new Unused_Method('Cannot find ' . ($has_variable_calls ? 'explicit' : 'any') . ' calls to private method ' . $method_id . ($has_variable_calls ? ' (but did find some potential callers)' : ''), $method_location, $method_id);
                        }
                        if ($codebase->alter_code) {
                            if ($method_storage->stmt_location && !$declaring_classlike_storage->is_trait && isset($project_analyzer->get_issues_to_fix()['UnusedMethod']) && !$has_variable_calls && !Issue_Buffer::is_suppressed($issue, $method_storage->suppressed_issues)) {
                                File_Manipulation_Buffer::add_for_code_location($method_storage->stmt_location, '', true);
                            }
                        } else {
                            Issue_Buffer::maybe_add($issue, $method_storage->suppressed_issues, $method_storage->stmt_location && !$declaring_classlike_storage->is_trait && !$has_variable_calls);
                        }
                    }
                }
            } else if ($method_storage->return_type && $method_storage->return_type_location && !$method_storage->return_type->is_void() && !$method_storage->return_type->is_never() && $method_id->method_name !== '__tostring' && ($method_storage->is_static || !$method_storage->probably_fluent)) {
                $method_return_referenced = $this->file_reference_provider->is_method_return_referenced(strtolower((string) $method_id));
                if (!$method_return_referenced) {
                    if ($method_storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                        Issue_Buffer::maybe_add(new Unused_Return_Value('The return value for this private method is never used', $method_storage->return_type_location), $method_storage->suppressed_issues);
                    } else {
                        Issue_Buffer::maybe_add(new Possibly_Unused_Return_Value('The return value for this method is never used', $method_storage->return_type_location), $method_storage->suppressed_issues);
                    }
                }
            }
        }
    }
    private function check_method_param_references(Class_Like_Storage $classlike_storage): void
    {
        foreach ($classlike_storage->appearing_method_ids as $method_name => $appearing_method_id) {
            $appearing_fq_classlike_name = $appearing_method_id->fq_class_name;
            if ($appearing_fq_classlike_name !== $classlike_storage->name) {
                continue;
            }
            $method_id = $appearing_method_id;
            if (isset($classlike_storage->methods[$method_name])) {
                $method_storage = $classlike_storage->methods[$method_name];
            } else {
                $declaring_method_id = $classlike_storage->declaring_method_ids[$method_name];
                $declaring_fq_classlike_name = $declaring_method_id->fq_class_name;
                $declaring_method_name = $declaring_method_id->method_name;
                try {
                    $declaring_classlike_storage = $this->classlike_storage_provider->get($declaring_fq_classlike_name);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $method_storage = $declaring_classlike_storage->methods[$declaring_method_name];
                $method_id = $declaring_method_id;
            }
            if ($method_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PRIVATE && !$classlike_storage->is_interface) {
                foreach ($method_storage->params as $offset => $param_storage) {
                    if (empty($classlike_storage->overridden_method_ids[$method_name]) && $param_storage->location && !$param_storage->promoted_property && !$this->file_reference_provider->is_method_param_used(strtolower((string) $method_id), $offset)) {
                        if ($method_storage->final) {
                            Issue_Buffer::maybe_add(new Unused_Param('Param #' . ($offset + 1) . ' is never referenced in this method', $param_storage->location), $method_storage->suppressed_issues);
                        } else {
                            Issue_Buffer::maybe_add(new Possibly_Unused_Param('Param #' . ($offset + 1) . ' is never referenced in this method', $param_storage->location), $method_storage->suppressed_issues);
                        }
                    }
                }
            }
        }
    }
    private function find_possible_method_param_types(Class_Like_Storage $classlike_storage): void
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        foreach ($classlike_storage->appearing_method_ids as $method_name => $appearing_method_id) {
            $appearing_fq_classlike_name = $appearing_method_id->fq_class_name;
            if ($appearing_fq_classlike_name !== $classlike_storage->name) {
                continue;
            }
            $method_id = $appearing_method_id;
            $declaring_classlike_storage = $classlike_storage;
            if (isset($classlike_storage->methods[$method_name])) {
                $method_storage = $classlike_storage->methods[$method_name];
            } else {
                $declaring_method_id = $classlike_storage->declaring_method_ids[$method_name];
                $declaring_fq_classlike_name = $declaring_method_id->fq_class_name;
                $declaring_method_name = $declaring_method_id->method_name;
                try {
                    $declaring_classlike_storage = $this->classlike_storage_provider->get($declaring_fq_classlike_name);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $method_storage = $declaring_classlike_storage->methods[$declaring_method_name];
                $method_id = $declaring_method_id;
            }
            if ($method_storage->location && !$project_analyzer->can_report_issues($method_storage->location->file_path) && !$codebase->analyzer->can_report_issues($method_storage->location->file_path)) {
                continue;
            }
            if ($declaring_classlike_storage->is_trait) {
                continue;
            }
            $method_id_lc = strtolower((string) $method_id);
            if (isset($codebase->analyzer->possible_method_param_types[$method_id_lc])) {
                if ($method_storage->location) {
                    $possible_param_types = $codebase->analyzer->possible_method_param_types[$method_id_lc];
                    if ($possible_param_types) {
                        foreach ($possible_param_types as $offset => $possible_type) {
                            if (!isset($method_storage->params[$offset])) {
                                continue;
                            }
                            $param_name = $method_storage->params[$offset]->name;
                            if ($possible_type->has_mixed()) {
                                continue;
                            }
                            if ($possible_type->is_null()) {
                                continue;
                            }
                            if ($method_storage->params[$offset]->default_type) {
                                if ($method_storage->params[$offset]->default_type instanceof Union) {
                                    $default_type = $method_storage->params[$offset]->default_type;
                                } else {
                                    $default_type_atomic = Constant_Type_Resolver::resolve($codebase->classlikes, $method_storage->params[$offset]->default_type);
                                    $default_type = new Union([$default_type_atomic]);
                                }
                                $possible_type = Type::combine_union_types($possible_type, $default_type);
                            }
                            if ($codebase->alter_code && isset($project_analyzer->get_issues_to_fix()['MissingParamType'])) {
                                $function_analyzer = $project_analyzer->get_function_like_analyzer($method_id, $method_storage->location->file_path);
                                $has_variable_calls = $codebase->analyzer->has_mixed_member_name($method_name) || $codebase->analyzer->has_mixed_member_name(strtolower($classlike_storage->name . '::'));
                                if ($has_variable_calls) {
                                    $possible_type = $possible_type->set_properties(['from_docblock' => true]);
                                }
                                if ($function_analyzer) {
                                    $function_analyzer->add_or_update_param_type($project_analyzer, $param_name, $possible_type, $possible_type->from_docblock && $project_analyzer->only_replace_php_types_with_non_docblock_types);
                                }
                            } else {
                                Issue_Buffer::add_fixable_issue('MissingParamType');
                            }
                        }
                    }
                }
            }
        }
    }
    private function check_property_references(Class_Like_Storage $classlike_storage): void
    {
        $project_analyzer = Project_Analyzer::get_instance();
        $codebase = $project_analyzer->get_codebase();
        foreach ($classlike_storage->properties as $property_name => $property_storage) {
            if ($classlike_storage->public_api && ($property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || $property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED && !$classlike_storage->final)) {
                continue;
            }
            $referenced_property_name = strtolower($classlike_storage->name) . '::$' . $property_name;
            $property_referenced = $this->file_reference_provider->is_class_property_referenced($referenced_property_name);
            $property_constructor_referenced = false;
            if ($property_referenced && $property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PRIVATE) {
                $all_method_references = $this->file_reference_provider->get_all_method_references_to_class_properties();
                if (isset($all_method_references[$referenced_property_name]) && count($all_method_references[$referenced_property_name]) === 1) {
                    $constructor_name = strtolower($classlike_storage->name) . '::__construct';
                    $property_references = $all_method_references[$referenced_property_name];
                    $property_constructor_referenced = isset($property_references[$constructor_name]) && !$property_storage->is_static;
                }
            }
            if ((!$property_referenced || $property_constructor_referenced) && $property_storage->location) {
                $property_id = $classlike_storage->name . '::$' . $property_name;
                if ($property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || $property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PROTECTED) {
                    $has_parent_references = isset($classlike_storage->overridden_property_ids[$property_name]);
                    $has_variable_calls = $codebase->analyzer->has_mixed_member_name('$' . $property_name) || $codebase->analyzer->has_mixed_member_name(strtolower($classlike_storage->name) . '::$');
                    foreach ($classlike_storage->parent_classes as $parent_method_fqcln) {
                        if ($codebase->analyzer->has_mixed_member_name(strtolower($parent_method_fqcln) . '::$')) {
                            $has_variable_calls = true;
                            break;
                        }
                    }
                    foreach ($classlike_storage->class_implements as $fq_interface_name) {
                        if ($codebase->analyzer->has_mixed_member_name(strtolower($fq_interface_name) . '::$')) {
                            $has_variable_calls = true;
                            break;
                        }
                    }
                    if (!$has_parent_references && ($property_storage->visibility === Class_Like_Analyzer::VISIBILITY_PUBLIC || !isset($classlike_storage->declaring_method_ids['__get']))) {
                        $issue = new Possibly_Unused_Property('Cannot find ' . ($has_variable_calls ? 'explicit' : 'any') . ' references to property ' . $property_id . ($has_variable_calls ? ' (but did find some potential references)' : ''), $property_storage->location, $property_id);
                        if ($codebase->alter_code) {
                            if ($property_storage->stmt_location && isset($project_analyzer->get_issues_to_fix()['PossiblyUnusedProperty']) && !$has_variable_calls && !Issue_Buffer::is_suppressed($issue, $classlike_storage->suppressed_issues)) {
                                File_Manipulation_Buffer::add_for_code_location($property_storage->stmt_location, '', true);
                            }
                        } else {
                            Issue_Buffer::maybe_add($issue, $classlike_storage->suppressed_issues + $property_storage->suppressed_issues);
                        }
                    }
                } elseif (!isset($classlike_storage->declaring_method_ids['__get'])) {
                    $has_variable_calls = $codebase->analyzer->has_mixed_member_name('$' . $property_name);
                    $issue = new Unused_Property('Cannot find ' . ($has_variable_calls ? 'explicit' : 'any') . ' references to private property ' . $property_id . ($has_variable_calls ? ' (but did find some potential references)' : ''), $property_storage->location, $property_id);
                    if ($codebase->alter_code) {
                        if (!$property_constructor_referenced && $property_storage->stmt_location && isset($project_analyzer->get_issues_to_fix()['UnusedProperty']) && !$has_variable_calls && !Issue_Buffer::is_suppressed($issue, $classlike_storage->suppressed_issues)) {
                            File_Manipulation_Buffer::add_for_code_location($property_storage->stmt_location, '', true);
                        }
                    } else {
                        Issue_Buffer::maybe_add($issue, $classlike_storage->suppressed_issues + $property_storage->suppressed_issues);
                    }
                }
            }
        }
    }
    /**
     * @param  lowercase-string $fq_classlike_name_lc
     */
    public function register_missing_class_like(string $fq_classlike_name_lc): void
    {
        $this->existing_classlikes_lc[$fq_classlike_name_lc] = false;
    }
    /**
     * @param  lowercase-string $fq_classlike_name_lc
     */
    public function is_missing_class_like(string $fq_classlike_name_lc): bool
    {
        return isset($this->existing_classlikes_lc[$fq_classlike_name_lc]) && $this->existing_classlikes_lc[$fq_classlike_name_lc] === false;
    }
    /**
     * @param  lowercase-string $fq_classlike_name_lc
     */
    public function does_class_like_exist(string $fq_classlike_name_lc): bool
    {
        return isset($this->existing_classlikes_lc[$fq_classlike_name_lc]) && $this->existing_classlikes_lc[$fq_classlike_name_lc];
    }
    public function forget_missing_class_likes(): void
    {
        $this->existing_classlikes_lc = array_filter($this->existing_classlikes_lc);
    }
    public function remove_class_like(string $fq_class_name): void
    {
        $fq_class_name_lc = strtolower($fq_class_name);
        unset($this->existing_classlikes_lc[$fq_class_name_lc], $this->existing_traits_lc[$fq_class_name_lc], $this->existing_traits[$fq_class_name], $this->existing_enums_lc[$fq_class_name_lc], $this->existing_enums[$fq_class_name], $this->existing_interfaces_lc[$fq_class_name_lc], $this->existing_interfaces[$fq_class_name], $this->existing_classes_lc[$fq_class_name_lc], $this->existing_classes[$fq_class_name], $this->trait_nodes[$fq_class_name_lc]);
        $this->scanner->remove_class_like($fq_class_name_lc);
    }
    /**
     * @return array{
     *     array<lowercase-string, bool>,
     *     array<lowercase-string, bool>,
     *     array<lowercase-string, bool>,
     *     array<string, bool>,
     *     array<lowercase-string, bool>,
     *     array<string, bool>,
     *     array<lowercase-string, bool>,
     *     array<string, bool>,
     *     array<string, bool>,
     * }
     */
    public function get_thread_data(): array
    {
        return [$this->existing_classlikes_lc, $this->existing_classes_lc, $this->existing_traits_lc, $this->existing_traits, $this->existing_enums_lc, $this->existing_enums, $this->existing_interfaces_lc, $this->existing_interfaces, $this->existing_classes];
    }
    /**
     * @param array{
     *     0: array<lowercase-string, bool>,
     *     1: array<lowercase-string, bool>,
     *     2: array<lowercase-string, bool>,
     *     3: array<string, bool>,
     *     4: array<lowercase-string, bool>,
     *     5: array<string, bool>,
     *     6: array<lowercase-string, bool>,
     *     7: array<string, bool>,
     *     8: array<string, bool>,
     * } $thread_data
     */
    public function add_thread_data(array $thread_data): void
    {
        [$existing_classlikes_lc, $existing_classes_lc, $existing_traits_lc, $existing_traits, $existing_enums_lc, $existing_enums, $existing_interfaces_lc, $existing_interfaces, $existing_classes] = $thread_data;
        $this->existing_classlikes_lc = self::merge_thread_data($existing_classlikes_lc, $this->existing_classlikes_lc);
        $this->existing_classes_lc = self::merge_thread_data($existing_classes_lc, $this->existing_classes_lc);
        $this->existing_traits_lc = self::merge_thread_data($existing_traits_lc, $this->existing_traits_lc);
        $this->existing_traits = self::merge_thread_data($existing_traits, $this->existing_traits);
        $this->existing_enums_lc = self::merge_thread_data($existing_enums_lc, $this->existing_enums_lc);
        $this->existing_enums = self::merge_thread_data($existing_enums, $this->existing_enums);
        $this->existing_interfaces_lc = self::merge_thread_data($existing_interfaces_lc, $this->existing_interfaces_lc);
        $this->existing_interfaces = self::merge_thread_data($existing_interfaces, $this->existing_interfaces);
        $this->existing_classes = self::merge_thread_data($existing_classes, $this->existing_classes);
    }
    /**
     * @template T as string|lowercase-string
     * @param array<T, bool> $old
     * @param array<T, bool> $new
     * @return array<T, bool>
     */
    private static function merge_thread_data(array $old, array $new): array
    {
        foreach ($new as $name => $value) {
            if (!isset($old[$name]) || !$old[$name] && $value) {
                $old[$name] = $value;
            }
        }
        return $old;
    }
    public function get_storage_for(string $fq_class_name): ?Class_Like_Storage
    {
        $fq_class_name = $this->get_un_aliased_name($fq_class_name);
        try {
            return $this->classlike_storage_provider->get($fq_class_name);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
    private function get_constant_type(Class_Like_Storage $class_like_storage, string $constant_name, int $visibility, ?Statements_Analyzer $statements_analyzer, array $visited_constant_ids, bool $late_static_binding): ?Union
    {
        $constant_resolver = new Storage_By_Pattern_Resolver();
        $resolved_constants = $constant_resolver->resolve_constants($class_like_storage, $constant_name);
        $filtered_constants_by_visibility = array_filter($resolved_constants, fn(Class_Constant_Storage $resolved_constant): bool => $this->filter_constant_name_by_visibility($resolved_constant, $visibility));
        if ($filtered_constants_by_visibility === []) {
            return null;
        }
        $new_atomic_types = [];
        foreach ($filtered_constants_by_visibility as $filtered_constant_name => $constant_storage) {
            if (!isset($class_like_storage->constants[$filtered_constant_name])) {
                continue;
            }
            if ($constant_storage->unresolved_node) {
                /** @psalm-suppress InaccessibleProperty Lazy resolution */
                $constant_storage->inferred_type = new Union([Constant_Type_Resolver::resolve($this, $constant_storage->unresolved_node, $statements_analyzer, $visited_constant_ids)]);
                if ($constant_storage->type === null || !$constant_storage->type->from_docblock) {
                    /** @psalm-suppress InaccessibleProperty Lazy resolution */
                    $constant_storage->type = $constant_storage->inferred_type;
                }
            }
            $constant_type = $late_static_binding ? $constant_storage->type : $constant_storage->inferred_type ?? null;
            if ($constant_type === null) {
                continue;
            }
            $new_atomic_types[] = $constant_type->get_atomic_types();
        }
        if ($new_atomic_types === []) {
            return null;
        }
        return new Union(array_merge([], ...$new_atomic_types));
    }
    private function get_enum_type(Class_Like_Storage $class_like_storage, string $constant_name): ?Union
    {
        $constant_resolver = new Storage_By_Pattern_Resolver();
        $resolved_enums = $constant_resolver->resolve_enums($class_like_storage, $constant_name);
        if ($resolved_enums === []) {
            return null;
        }
        $types = [];
        foreach (array_keys($resolved_enums) as $enum_case_name) {
            $types[$enum_case_name] = new T_Enum_Case($class_like_storage->name, $enum_case_name);
        }
        return new Union($types);
    }
    private function filter_constant_name_by_visibility(Class_Constant_Storage $constant_storage, int $visibility): bool
    {
        if ($visibility === ReflectionProperty::IS_PUBLIC && $constant_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC) {
            return false;
        }
        if ($visibility === ReflectionProperty::IS_PROTECTED && $constant_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PUBLIC && $constant_storage->visibility !== Class_Like_Analyzer::VISIBILITY_PROTECTED) {
            return false;
        }
        return true;
    }
}