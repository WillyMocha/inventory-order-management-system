# Module: [MODULE NAME] (`[module-slug]`)

**Back to**: [00-overview.md](../00-overview.md) · **Last Updated**: [DATE]

<!--
  This is a compact SEMANTIC MAP of one module — a navigation aid for an AI, not a
  narrative for a human. Keep every section terse: bullets, short tables, IDs, file
  paths and signatures — never paragraphs or raw code bodies. Omit a section only if
  it genuinely does not apply. Business language for Summary/Capabilities/Entities;
  technical detail belongs in API surface / Dependencies / Test coverage.
-->

## Summary

[1–2 lines: what this module is responsible for.]

## Capabilities

<!-- Each maps to an entry point (route, CLI command, screen, consumer). Business language. -->

- **[MODULE]-CAP-001** — [verb phrase, e.g. "create an account with email verification"]
- **[MODULE]-CAP-002** — [capability] `[NEEDS CLARIFICATION: which user segment?]` *(only if genuinely unclear)*

## Key Entities & Rules

<!-- Note the backing table/model/file inline. Business objects and invariants, terse. -->

- **[MODULE]-ENT-001 [Entity]** — [what it represents; key fields, invariants, states] (`path/to/model`)
- **[MODULE]-ENT-002 [Entity]** — [represents; relationships — note if a relationship crosses into another module]

## API Surface

<!-- The component-map edge list: what this module EXPOSES and what it CONSUMES. -->

**Exposes** — endpoints / commands / events this module publishes:

| Kind | Name / Route | Role | Entry file |
| ---- | ------------ | ---- | ---------- |
| [HTTP/CLI/event] | [`POST /orders`] | [what it does] | [`path/to/handler`](path/to/handler) |

**Consumes** — other modules / external services / events subscribed:

- [module or service] — [what for]

## Data Flow

<!-- One-line textual flow per main process. Add at most ONE small Mermaid diagram, and
     only if a flow is non-obvious. Never diagram trivial CRUD. -->

- [Process]: [actor] → [step] → [step] → [outcome]

## Screens / Pages

<!-- UI modules only. Literal inventory — omit entirely for backend/headless modules. -->

| Screen | Route | Entry file | Related capability |
| ------ | ----- | ---------- | ------------------ |
| [name] | [`/orders/:id`] | [`path/to/Page.ext`](path/to/Page.ext) | [MODULE]-CAP-001 |

## Dependencies

<!-- Names only; pinned versions live in 00-overview.md's inventory unless module-specific. -->

- **Other modules**: [module — why]
- **External**: [service/library — why]

## Test Coverage

<!-- Plain observation, not a number: which areas/files have tests, which don't. -->

- [area/file] — [tested / no tests found]

## Known Gaps / Risks

- [Technical debt, referenced-but-missing capability, or deferred item]

## Change Log

<!-- Prepend newest first; maintained by /rudis.brd on each run touching this module. -->

- **[DATE]**: Initial version generated from codebase survey.
