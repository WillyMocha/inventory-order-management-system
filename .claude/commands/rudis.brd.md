---
description: Reverse-engineer a compact per-module semantic map of an existing/brownfield codebase — capabilities, entities, API surface, data flow, and dependencies — to seed context for future /rudis.specify, /rudis.constitution, or /rudis.redesign work, at a fraction of a full document's token cost.
handoffs:
  - label: Derive Project Principles
    agent: rudis.constitution
    prompt: Derive project principles from the current system described in docs/brd/00-overview.md
  - label: Specify a New Feature
    agent: rudis.specify
    prompt: Using docs/brd/00-overview.md as background context, build a new feature that...
  - label: Revamp a Module
    agent: rudis.redesign
    prompt: Using docs/brd/modules/<module-slug>.md as background context (capabilities/screens to preserve, entities, API surface, data flow, dependencies, and test coverage), redesign...
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). If non-empty, treat it as a scope/focus hint (e.g. "only the billing module", "focus on the API layer") rather than a feature description — this command documents what already exists, it does not propose new work. A module-name hint scopes the whole run to that module: only that module's file and its row in the Module Index are touched; every other module is left untouched.

## Outline

This command is for **existing/brownfield** codebases. It reads the repository and drafts a compact **semantic map** — one file per module capturing what each module does, its entities, its API surface, how data flows through it, and what it depends on. This map is far smaller than the raw code but captures its meaning, so later commands can navigate a large codebase accurately without re-reading everything. The resulting `docs/brd/` directory becomes the background context that `/rudis.specify` and `/rudis.constitution` build on — and, for a revamp of something that already exists, what `/rudis.redesign` reads as its boundary context when there's no prior plan.md/data-model.md to fall back on.

Do not use this command to describe a feature that doesn't exist yet — that's `/rudis.specify`.

### Cost discipline (the whole point of this rewrite)

This map is deliberately **terse and structured**, not prose. It is a navigation aid for an AI, not a narrative for a human reader. Follow these rules to keep it cheap to generate and cheap to load later:

- **One file per module** — not three. Business, flow, and technical detail live together, compactly.
- **Bullets, short tables, and IDs — not paragraphs.** If a section can be a list, it must be a list.
- **Sample, don't exhaust.** Read representative files (entry points, models, one or two handlers per capability); do not read every file in a large repo.
- **Mermaid only where it adds signal.** At most one small diagram per module, and only when a flow is non-obvious; otherwise a one-line textual flow. Never diagram trivial CRUD.
- **No raw code dumps.** Cite file paths and signatures, not function bodies.

### Output structure

```text
docs/brd/
├── 00-overview.md              # system map: exec summary, module index, one system
│                                #   diagram, dependency inventory, constraints, glossary,
│                                #   assumptions, change log
└── modules/
    └── <module-slug>.md         # one compact semantic map per module
```

A **module** is a top-level business/domain folder in the source code (e.g. `auth/`, `billing/`, `order/`) — not entry-point-level (too granular) and not freeform (not predictable enough for repeat runs). Capability/entity IDs are prefixed per module (`AUTH-CAP-001`, `BILLING-ENT-002`) so they stay unique and citable from `00-overview.md`.

Each `modules/<slug>.md` contains, in this order — omit a section only if it genuinely does not apply, and keep every section terse:

- **Summary** — 1–2 lines: what this module is responsible for.
- **Capabilities** — `<MOD>-CAP-NNN — <verb phrase>` bullets (each maps to an entry point: route, CLI command, screen, consumer). Business language.
- **Key entities & rules** — `<MOD>-ENT-NNN — <Entity>: <key fields, invariants, states>` bullets. Note the underlying table/model/file inline.
- **API surface** — a compact list/table of what the module **exposes** (endpoints/commands/events published) and **consumes** (calls to other modules/external services, events subscribed). This is the component-map edge list.
- **Data flow** — the main process(es) as a one-line textual flow each; add at most one small Mermaid diagram only if a flow is non-obvious.
- **Screens/Pages** (UI modules only) — literal inventory: name, route, entry file. Not a description.
- **Dependencies** — other modules and external integrations this module needs (names only; versions live in the overview's inventory unless module-specific).
- **Test coverage** — a plain observation of which of this module's areas/files have tests and which don't (same detection approach as `/rudis.test`'s Discovery Phase). Not a numeric estimate.
- **Known gaps / risks** — technical debt, and any `[NEEDS CLARIFICATION: …]` for business intent the code can't reveal.
- **Change log** — one line per run.

### Steps

1. **Determine scope**: If `$ARGUMENTS` names a module/area, limit discovery and writes to it and note the scope at the top of `00-overview.md`. Otherwise, scope is the whole repository.

2. **Legacy migration**: if the older multi-file layout exists (`docs/brd.md`, or per-module folders `docs/brd/modules/<slug>/brd.md`+`flow.md`+`technical.md`), read it as reference context first — it captures previously-answered business questions discovery shouldn't re-ask. After writing the new structure (step 7), back up any single-file `docs/brd.md` to `docs/brd.legacy.md` (do not delete its content), and for the old per-module folders, consolidate each folder's three files into the single new `modules/<slug>.md` and remove the old folder. Note the migration in `00-overview.md`'s Change Log.

3. **Check for an existing map**: if `docs/brd/` already exists in the new layout, this is an **update**, not an overwrite:
   - Existing module files: preserve entries that still hold; revise what the current code contradicts; prepend a new entry to that file's Change Log.
   - Newly-discovered modules: create fresh files.
   - If `$ARGUMENTS` scopes this run to one module: touch only that module's file and its row in `00-overview.md`'s Module Index — leave everything else untouched.
   - If nothing exists yet, create `docs/brd/` fresh from the templates.

4. **Discovery** — survey top-down; sample representative files rather than reading everything, especially in large repos:
   - Read root-level docs first: `README.md`, the agent context file (`CLAUDE.md` or equivalent), `CHANGELOG.md`, and anything under `docs/` — the most reliable statements of intent.
   - Map the directory structure to identify modules/domains (see the module definition above).
   - Per module: identify entry points (HTTP routes/controllers, CLI commands, UI pages/screens, message consumers) → candidate capabilities; data models/schema/migrations → candidate Key Entities (note the file/table); calls to other modules and external integrations → the API-surface edges.
   - Identify auth/permissions/role code → Business Actors (record in the overview, system-wide).
   - Skim `git log --oneline` and `CHANGELOG.md` for capability history if useful for the Executive Summary.
   - Read `.rudis/memory/constitution.md` if it exists, for constraints that belong in the overview.
   - **Test coverage** (per module): using `/rudis.test`'s detection approach (frameworks, locations, naming), record a plain observation of which areas have tests and which don't.
   - **Dependency inventory** (system-wide): read the manifest(s) at repo root (`package.json`, `pyproject.toml`/`requirements.txt`, `pom.xml`/`build.gradle`, `go.mod`, `Gemfile`, `*.csproj`, etc.) and record direct/major dependencies with the version actually pinned. A dependency used by only one module is noted on that module instead.
   - **Screens/pages** (per UI module): enumerate routes/pages from routing config or the page directory — a literal inventory.

5. **Load templates**: `.rudis/templates/brd-overview-template.md` and `.rudis/templates/brd-module-template.md` — the required sections for each output file.

6. **Draft** — terse, per the Cost discipline rules above:
   - **`00-overview.md` first**: Executive Summary (≤5 bullets), Business Objectives, Stakeholders & Business Actors, Module Index (one row per module → link to `modules/<slug>.md`), System Map (one Mermaid component diagram of module→module/external edges), Technology & Dependency Inventory (pinned versions), Constraints & Non-Functional Notes, Out of Scope / Known Gaps, Glossary (non-obvious terms only), Assumptions & Open Questions, Change Log.
   - **Then, per module**: fill `modules/<slug>.md` with the sections listed under Output structure. Business language for Summary/Capabilities/Entities; technical detail (file paths, signatures, stack) is allowed and expected in API surface / Dependencies / Test coverage — one file, both concerns, kept compact.
   - Where code cannot reveal the "why" (original business driver, target user, priority, success metric, intended scope boundary), mark it inline with `[NEEDS CLARIFICATION: specific question]` instead of guessing confidently.
   - **LIMIT: Maximum 5 [NEEDS CLARIFICATION] markers across the entire run** (not per module). Prioritize by business impact: objectives/intent > scope boundaries > actor/permission ambiguity > minor detail. Record any excess as open items in the relevant "Known gaps" section instead of blocking.

7. **Resolve clarifications with the user**: if any `[NEEDS CLARIFICATION]` markers remain, present them together before finalizing:

   ```markdown
   ## Question [N]: [Topic]

   **Context**: [Quote the relevant finding, and which file it's in]

   **What we need to know**: [The specific question from the marker]

   **Suggested Answers**:

   | Option | Answer | Implications |
   |--------|--------|--------------|
   | A      | [First plausible answer, based on what the code suggests] | [What this means] |
   | B      | [Second plausible answer] | [What this means] |
   | Custom | Provide your own answer | [How to respond] |

   **Your choice**: _[Wait for user response]_
   ```

   Number questions sequentially, present them all together, then wait. Replace each marker with the resolved answer and add a line to `00-overview.md`'s "Assumptions & Open Questions". Any marker the user can't resolve stays listed there as an open question rather than blocking the document.

8. **Write** all files under `docs/brd/` (and, if step 2 applies, the `docs/brd.legacy.md` backup / folder consolidation).

9. **Report** completion with: paths written/updated, module count, rough capability/entity counts per module, how many `[NEEDS CLARIFICATION]` items were resolved vs. remain open, and a suggested next step — typically `/rudis.constitution` (derive principles from what exists), `/rudis.specify` (build a new feature with `docs/brd/00-overview.md` as background context), or `/rudis.redesign` (revamp a module/screen, using that module's `modules/<slug>.md` as boundary context — flag modules with no test coverage as higher-risk to redesign without first adding tests).

## Guidelines

- **Terse and structured beats thorough and verbose.** A shorter map that an AI can load cheaply and navigate accurately is the goal — not a comprehensive document. If in doubt, cut.
- Summary/Capabilities/Entities are business-facing (same discipline as `spec.md` — no framework/API-shape detail *there*). API surface / Dependencies / Test coverage are the technical sections and are where file paths, signatures, and stack detail belong.
- Prefer evidence from docs/comments/tests over guessing from code structure; when both are silent, mark `[NEEDS CLARIFICATION]` rather than inventing intent.
- This command never modifies application source code — it only reads the repository and writes under `docs/brd/` (plus the optional `docs/brd.legacy.md` backup).
