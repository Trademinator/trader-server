# Repository working rules

## Artisan commands

- Use lowercase kebab-case for all `trademinator:*` command names and aliases (for example, `trademinator:fetch-ohlcv`). Preserve case-sensitive argument values such as `BTC/USD` and `1M`.
- Maintain `CLI.md` as the canonical complete CLI reference. In the same change as any new, changed, renamed, or removed command, update its description, exact normalized Laravel signature, arguments/options/defaults, examples, side effects, prerequisites, and schedule where applicable.
- Update all command invocations in code, tests, schedules, and documentation when renaming. Document migration from previous names; do not introduce mixed-case aliases.
- Keep specialized guides linked to `CLI.md` instead of treating them as the complete command inventory.
- Run `php artisan test --filter=TrademinatorCliDocumentationTest` after command or CLI documentation changes. This gate checks registered command names, aliases, coverage, signatures, and descriptions. Review behavior-only documentation updates manually too.
