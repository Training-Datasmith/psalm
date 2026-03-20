# Architecture: psalm

## Purpose

Psalm is a static analysis tool for PHP that finds type errors, undefined variables, missing return types, security vulnerabilities (taint analysis), and other bugs without executing the code. It reads PHP source files and a `psalm.xml` configuration file, then reports issues with file and line references.

## Directory Structure

```
src/Psalm/
  Codebase.php              - Central registry of all analysed classes, methods, and types
  Config.php                - Psalm configuration (issue levels, plugins, paths, suppressions)
  Context.php               - Tracks variable types and assignments within a code scope
  Type.php                  - Entry point for type system operations
  Type/                     - Type representation: Union, Atomic, Generic, Template types, etc.
  Internal/                 - Private implementation: analysers, checkers, walkers, data flow
  Issue/                    - One class per issue type (UndefinedVariable, NullReference, etc.)
  Issue_Buffer.php          - Collects and deduplicates issues found during analysis
  Plugin/                   - Plugin API: hooks, event sockets for extending Psalm
  Report/                   - Output formatters (JSON, JUnit, text, SARIF, GitHub Actions)
  Progress/                 - Progress reporting during long analysis runs
  Storage/                  - Immutable value objects caching method/class/property metadata
  Node/                     - PHP-Parser node wrappers with Psalm type annotations
  SourceControl/            - Git integration for blame-based issue suppression
  Exception/                - Domain exceptions
bin/
  psalm                     - CLI entry point
examples/                   - Example plugins demonstrating the plugin API
```

## Key Design Decisions

- **Union type system**: Every expression has a `Union` type — a set of possible `Atomic` types (e.g., `int|string|null`). Analysis narrows unions through control flow (if/else, instanceof, null checks).
- **Codebase cache**: `Codebase` pre-indexes all discovered PHP files into `Storage` objects so repeated analysis runs (in language server or `--diff` mode) only re-analyse changed files.
- **Taint analysis**: An optional data-flow graph tracks user-controlled values from sources (HTTP input) to sinks (SQL queries, `eval`, `header()`) to find injection vulnerabilities.
- **Plugin API**: Plugins hook into analysis via event sockets (`After_Statement_Analysis_Interface`, `Before_Method_Call_Analysis_Interface`, etc.), allowing custom issue types and type inference rules without forking Psalm.
- **Issue levels**: Each issue type has a configurable error level (error, warning, info, suppress). `psalm.xml` maps issue types to levels, enabling gradual adoption in existing codebases.

## Extension Points

- Implement a `Plugin` class and register it in `psalm.xml` to add custom issue types, type inference, or taint sources/sinks.
- Add custom stubs (`.php` files with docblock type hints) to teach Psalm about untyped third-party libraries.
- Use `@psalm-suppress` annotations to silence specific issues on individual lines.

## Dependency Flow

```
psalm --config=psalm.xml src/
  └─> Config::load_from_xml_file()
  └─> Codebase::scanFiles() — index all PHP files via PHP-Parser
  └─> Analyser::analyseFiles()
        └─> per file: walk AST nodes
              └─> Context — track variable/expression types
              └─> Type narrowing — refine unions through branches
              └─> Taint graph — propagate data flow
              └─> Issue_Buffer::add() — record found issues
  └─> Report — format and output issues
```
