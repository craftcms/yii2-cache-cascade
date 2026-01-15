# AGENTS.md

## Project

Uses [Mago](https://github.com/carthage-software/mago/) for linting and static analysis.

## Global

## General

- Always RTFM first, before attempting debugging or workarounds. If you are unsure of a canonical documentation source, ask.
- Check for available MCPs for any related tools or libraries. If you find any that aren't installed or enabled, prompt me.
- Consider performance implications, but prioritize clarity and correctness first.
- Search for and suggest existing libraries before implementing an overly complex solution to a common problem

## Code style

- Prefer declarative, self-documenting code.
- Use descriptive, verbose names that explain intent without requiring comments.
- Use comments only for non-obvious business logic, workarounds, or references to external issues.
- Include TODO comments with issue references when applicable: `// @TODO: replace with current spec`.

## Tests

- Write tests for any new functionality.
- Always ensure tests pass after tasks are complete.

## Git

- Only commit to prefixed branches, e.g. `agent/my-new-feature`.
- Commit atomically as you iterate. Freely commit and push to any `agent/*` branches.
- Fix linting and type errors before committing.