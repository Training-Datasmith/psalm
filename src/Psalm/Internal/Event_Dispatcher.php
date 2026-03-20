<?php

declare (strict_types=1);
namespace Psalm\Internal;

use Psalm\Plugin\Event_Handler\Add_Taints_Interface;
use Psalm\Plugin\Event_Handler\After_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Class_Like_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Class_Like_Existence_Check_Interface;
use Psalm\Plugin\Event_Handler\After_Class_Like_Visit_Interface;
use Psalm\Plugin\Event_Handler\After_Codebase_Populated_Interface;
use Psalm\Plugin\Event_Handler\After_Every_Function_Call_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Expression_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_File_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Function_Call_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Function_Like_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Method_Call_Analysis_Interface;
use Psalm\Plugin\Event_Handler\After_Statement_Analysis_Interface;
use Psalm\Plugin\Event_Handler\Before_Add_Issue_Interface;
use Psalm\Plugin\Event_Handler\Before_Expression_Analysis_Interface;
use Psalm\Plugin\Event_Handler\Before_File_Analysis_Interface;
use Psalm\Plugin\Event_Handler\Before_Statement_Analysis_Interface;
use Psalm\Plugin\Event_Handler\Class_File_Path_Provider_Interface;
use Psalm\Plugin\Event_Handler\Event\Add_Remove_Taints_Event;
use Psalm\Plugin\Event_Handler\Event\After_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Existence_Check_Event;
use Psalm\Plugin\Event_Handler\Event\After_Class_Like_Visit_Event;
use Psalm\Plugin\Event_Handler\Event\After_Codebase_Populated_Event;
use Psalm\Plugin\Event_Handler\Event\After_Every_Function_Call_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Expression_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_File_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Function_Call_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Function_Like_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Method_Call_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\After_Statement_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Add_Issue_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Expression_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_File_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\Before_Statement_Analysis_Event;
use Psalm\Plugin\Event_Handler\Event\String_Interpreter_Event;
use Psalm\Plugin\Event_Handler\Remove_Taints_Interface;
use Psalm\Plugin\Event_Handler\String_Interpreter_Interface;
use Psalm\Type\Atomic\T_Literal_String;
use function count;
use function is_bool;
use function is_subclass_of;
/**
 * @internal
 */
final class Event_Dispatcher
{
    /**
     * Static methods to be called after method checks have completed
     *
     * @var list<class-string<AfterMethodCallAnalysisInterface>>
     */
    private array $after_method_checks = [];
    /**
     * Static methods to be called after project function checks have completed
     *
     * Called after function calls to functions defined in the project.
     *
     * Allows influencing the return type and adding of modifications.
     *
     * @var list<class-string<AfterFunctionCallAnalysisInterface>>
     */
    public array $after_function_checks = [];
    /**
     * Static methods to be called after every function call
     *
     * Called after each function call, including php internal functions.
     *
     * Cannot change the call or influence its return type
     *
     * @var list<class-string<AfterEveryFunctionCallAnalysisInterface>>
     */
    public array $after_every_function_checks = [];
    /**
     * Static methods to be called before expression checks are completed
     *
     * @var list<class-string<BeforeExpressionAnalysisInterface>>
     */
    public array $before_expression_checks = [];
    /**
     * Static methods to be called after expression checks have completed
     *
     * @var list<class-string<AfterExpressionAnalysisInterface>>
     */
    public array $after_expression_checks = [];
    /**
     * Static methods to be called before statement checks are processed
     *
     * @var list<class-string<BeforeStatementAnalysisInterface>>
     */
    public array $before_statement_checks = [];
    /**
     * Static methods to be called after statement checks have completed
     *
     * @var list<class-string<AfterStatementAnalysisInterface>>
     */
    public array $after_statement_checks = [];
    /**
     * Static methods to be called after method checks have completed
     *
     * @var list<class-string<StringInterpreterInterface>>
     */
    public array $string_interpreters = [];
    /**
     * Static methods to be called after classlike exists checks have completed
     *
     * @var list<class-string<AfterClassLikeExistenceCheckInterface>>
     */
    public array $after_classlike_exists_checks = [];
    /**
     * Static methods to be called after classlike checks have completed
     *
     * @var list<class-string<AfterClassLikeAnalysisInterface>>
     */
    public array $after_classlike_checks = [];
    /**
     * Static methods to be called after classlikes have been scanned
     *
     * @var list<class-string<AfterClassLikeVisitInterface>>
     */
    private array $after_visit_classlikes = [];
    /**
     * Static methods to be called after codebase has been populated
     *
     * @var list<class-string<AfterCodebasePopulatedInterface>>
     */
    public array $after_codebase_populated = [];
    /**
     * @var list<class-string<BeforeAddIssueInterface>>
     */
    private array $before_add_issue = [];
    /**
     * Static methods to be called after codebase has been populated
     *
     * @var list<class-string<AfterAnalysisInterface>>
     */
    public array $after_analysis = [];
    /**
     * Static methods to be called after a file has been analyzed
     *
     * @var list<class-string<AfterFileAnalysisInterface>>
     */
    public array $after_file_checks = [];
    /**
     * Static methods to be called before a file is analyzed
     *
     * @var list<class-string<BeforeFileAnalysisInterface>>
     */
    public array $before_file_checks = [];
    /**
     * Static methods to be called to determine the file path a of a class
     * not autoloadable using composer, but autoloadable through another autoloader
     * (to avoid actually autoloading and parsing the class).
     *
     * @var list<class-string<ClassFilePathProviderInterface>>
     */
    public array $file_path_provider_interface = [];
    /**
     * Static methods to be called after functionlike checks have completed
     *
     * @var list<class-string<AfterFunctionLikeAnalysisInterface>>
     */
    public array $after_functionlike_checks = [];
    /**
     * Static methods to be called to see if taints should be added
     *
     * @var list<class-string<AddTaintsInterface>>
     */
    public array $add_taints_checks = [];
    /**
     * Static methods to be called to see if taints should be removed
     *
     * @var list<class-string<RemoveTaintsInterface>>
     */
    public array $remove_taints_checks = [];
    /**
     * @param class-string $class
     */
    public function register_class(string $class): void
    {
        if (is_subclass_of($class, After_Method_Call_Analysis_Interface::class)) {
            $this->after_method_checks[] = $class;
        }
        if (is_subclass_of($class, After_Function_Call_Analysis_Interface::class)) {
            $this->after_function_checks[] = $class;
        }
        if (is_subclass_of($class, After_Every_Function_Call_Analysis_Interface::class)) {
            $this->after_every_function_checks[] = $class;
        }
        if (is_subclass_of($class, Before_Expression_Analysis_Interface::class)) {
            $this->before_expression_checks[] = $class;
        }
        if (is_subclass_of($class, After_Expression_Analysis_Interface::class)) {
            $this->after_expression_checks[] = $class;
        }
        if (is_subclass_of($class, Before_Statement_Analysis_Interface::class)) {
            $this->before_statement_checks[] = $class;
        }
        if (is_subclass_of($class, After_Statement_Analysis_Interface::class)) {
            $this->after_statement_checks[] = $class;
        }
        if (is_subclass_of($class, String_Interpreter_Interface::class)) {
            $this->string_interpreters[] = $class;
        }
        if (is_subclass_of($class, After_Class_Like_Existence_Check_Interface::class)) {
            $this->after_classlike_exists_checks[] = $class;
        }
        if (is_subclass_of($class, After_Class_Like_Analysis_Interface::class)) {
            $this->after_classlike_checks[] = $class;
        }
        if (is_subclass_of($class, After_Class_Like_Visit_Interface::class)) {
            $this->after_visit_classlikes[] = $class;
        }
        if (is_subclass_of($class, After_Codebase_Populated_Interface::class)) {
            $this->after_codebase_populated[] = $class;
        }
        if (is_subclass_of($class, Before_Add_Issue_Interface::class)) {
            $this->before_add_issue[] = $class;
        }
        if (is_subclass_of($class, After_Analysis_Interface::class)) {
            $this->after_analysis[] = $class;
        }
        if (is_subclass_of($class, After_File_Analysis_Interface::class)) {
            $this->after_file_checks[] = $class;
        }
        if (is_subclass_of($class, Before_File_Analysis_Interface::class)) {
            $this->before_file_checks[] = $class;
        }
        if (is_subclass_of($class, After_Function_Like_Analysis_Interface::class)) {
            $this->after_functionlike_checks[] = $class;
        }
        if (is_subclass_of($class, Add_Taints_Interface::class)) {
            $this->add_taints_checks[] = $class;
        }
        if (is_subclass_of($class, Remove_Taints_Interface::class)) {
            $this->remove_taints_checks[] = $class;
        }
        if (is_subclass_of($class, Class_File_Path_Provider_Interface::class)) {
            $this->file_path_provider_interface[] = $class;
        }
    }
    public function has_after_method_call_analysis_handlers(): bool
    {
        return count($this->after_method_checks) > 0;
    }
    public function dispatch_after_method_call_analysis(After_Method_Call_Analysis_Event $event): void
    {
        foreach ($this->after_method_checks as $handler) {
            $handler::after_method_call_analysis($event);
        }
    }
    public function dispatch_after_function_call_analysis(After_Function_Call_Analysis_Event $event): void
    {
        foreach ($this->after_function_checks as $handler) {
            $handler::after_function_call_analysis($event);
        }
    }
    public function dispatch_after_every_function_call_analysis(After_Every_Function_Call_Analysis_Event $event): void
    {
        foreach ($this->after_every_function_checks as $handler) {
            $handler::after_every_function_call_analysis($event);
        }
    }
    public function dispatch_before_expression_analysis(Before_Expression_Analysis_Event $event): ?bool
    {
        foreach ($this->before_expression_checks as $handler) {
            if ($handler::before_expression_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    public function dispatch_after_expression_analysis(After_Expression_Analysis_Event $event): ?bool
    {
        foreach ($this->after_expression_checks as $handler) {
            if ($handler::after_expression_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    public function dispatch_before_statement_analysis(Before_Statement_Analysis_Event $event): ?bool
    {
        foreach ($this->before_statement_checks as $handler) {
            if ($handler::before_statement_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    public function dispatch_after_statement_analysis(After_Statement_Analysis_Event $event): ?bool
    {
        foreach ($this->after_statement_checks as $handler) {
            if ($handler::after_statement_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    public function dispatch_string_interpreter(String_Interpreter_Event $event): ?T_Literal_String
    {
        foreach ($this->string_interpreters as $handler) {
            if ($type = $handler::get_type_from_value($event)) {
                return $type;
            }
        }
        return null;
    }
    public function dispatch_after_class_like_existence_check(After_Class_Like_Existence_Check_Event $event): void
    {
        foreach ($this->after_classlike_exists_checks as $handler) {
            $handler::after_class_like_existence_check($event);
        }
    }
    public function dispatch_after_class_like_analysis(After_Class_Like_Analysis_Event $event): ?bool
    {
        foreach ($this->after_classlike_checks as $handler) {
            if ($handler::after_statement_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    public function has_after_class_like_visit_handlers(): bool
    {
        return count($this->after_visit_classlikes) > 0;
    }
    public function dispatch_after_class_like_visit(After_Class_Like_Visit_Event $event): void
    {
        foreach ($this->after_visit_classlikes as $handler) {
            $handler::after_class_like_visit($event);
        }
    }
    public function dispatch_after_codebase_populated(After_Codebase_Populated_Event $event): void
    {
        foreach ($this->after_codebase_populated as $handler) {
            $handler::after_codebase_populated($event);
        }
    }
    public function dispatch_before_add_issue(Before_Add_Issue_Event $event): ?bool
    {
        foreach ($this->before_add_issue as $handler) {
            $result = $handler::before_add_issue($event);
            if (is_bool($result)) {
                return $result;
            }
        }
        return null;
    }
    public function dispatch_after_analysis(After_Analysis_Event $event): void
    {
        foreach ($this->after_analysis as $handler) {
            $handler::after_analysis($event);
        }
    }
    public function dispatch_after_file_analysis(After_File_Analysis_Event $event): void
    {
        foreach ($this->after_file_checks as $handler) {
            $handler::after_analyze_file($event);
        }
    }
    public function dispatch_before_file_analysis(Before_File_Analysis_Event $event): void
    {
        foreach ($this->before_file_checks as $handler) {
            $handler::before_analyze_file($event);
        }
    }
    public function dispatch_after_function_like_analysis(After_Function_Like_Analysis_Event $event): ?bool
    {
        foreach ($this->after_functionlike_checks as $handler) {
            if ($handler::after_statement_analysis($event) === false) {
                return false;
            }
        }
        return null;
    }
    /**
     * @return list<string>
     */
    public function dispatch_add_taints(Add_Remove_Taints_Event $event): array
    {
        $added_taints = [];
        foreach ($this->add_taints_checks as $handler) {
            $added_taints = [...$added_taints, ...$handler::add_taints($event)];
        }
        return $added_taints;
    }
    /**
     * @return list<string>
     */
    public function dispatch_remove_taints(Add_Remove_Taints_Event $event): array
    {
        $removed_taints = [];
        foreach ($this->remove_taints_checks as $handler) {
            $removed_taints = [...$removed_taints, ...$handler::remove_taints($event)];
        }
        return $removed_taints;
    }
}