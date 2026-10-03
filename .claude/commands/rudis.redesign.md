---
description: Incrementally redesign or rework a specific part of an implemented feature (a form, page, endpoint, table, or module) without regenerating everything from scratch.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). The input names
the redesign target and intent — e.g., "the Projected Cost create form, follow
designs/projected-cost.fig", "merge the ActualCost and ProjectedCost tables", or
"align the Actual Cost pages with the Projected Cost look".

## Interactive Clarification (ask when target or intent is unclear)

When the redesign target or the desired outcome cannot be resolved confidently, ask the user instead of guessing:

- **Ask when** the target is ambiguous (multiple matching files, or no match), the design intent is unclear, or a change would spill outside the requested scope (shared type, migration, route change).
- **How**: pause and ask ONE specific question at a time. State your recommended option with a one-line reason, then give 2–4 concrete choices plus a short free-form alternative; the user may accept the recommendation by replying "yes" (same style as `/rudis.clarify`).
- **Bounded (no wait-loop)**: ask at most twice per decision. If still unresolved, do NOT redesign blindly — report what is ambiguous and stop, rather than guessing at scope.
- **Non-interactive sessions** (CI, `rudis run`, headless): never block on a question — if the target is unambiguous, proceed with the safest in-scope interpretation and flag assumptions in the report; if the target itself is ambiguous, stop and report rather than risk an out-of-scope rewrite.

## Goal

`/rudis.implement` builds a feature from scratch; this command **surgically reworks one
part of it**. It exists because full regeneration wastes the majority of manual-fix time:
redesigns must touch ONLY the requested scope, preserve everything that already works,
and leave an auditable trail with a rollback path.

## Operating Constraints (STRICT)

- **Scope lock**: modify only files that belong to the requested target. If a change
  outside the scope seems necessary (shared type, migration, route registration), list it
  and ask for confirmation BEFORE touching it.
- **Preserve working behavior**: never rewrite a file wholesale when an edit suffices.
  Keep existing naming, patterns, and passing tests intact.
- **Mimicry Principle**: any new code the redesign introduces must mimic the surrounding
  code's existing patterns — error handling, logging, validation, imports, file layout, and
  (for UI) the sibling components' structure. A redesign should read as if the original author
  wrote it; do not import a fresh style. For UI redesigns the design file is the source of truth
  for *layout*, but the *code conventions* still follow the existing components.
- **Absolute paths only**, resolved from the repo root.
- **No silent architecture changes**: honor plan.md's Architecture Type / Integration
  Target and the constitution (or, in the brownfield fallback below, the target module's
  `technical.md`); a redesign is not a license to restructure.

## Execution Steps

### 1. Initialize

Run `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -PathsOnly` from repo root and parse FEATURE_DIR (and available paths). Read, when
they exist: plan.md (architecture, tech stack), data-model.md, contracts/, spec.md, and
the agent context file (`CLAUDE.md`/`AGENTS.md`). These define the boundaries the
redesign must respect.

**Brownfield fallback**: if none of plan.md/data-model.md/contracts/spec.md exist for this
target (no prior `/rudis.specify` cycle — e.g. the project's docs came from `/rudis.brd`
instead), read `docs/brd/00-overview.md` for system context. Once the target module is
identified in Step 2, also read that module's semantic map `docs/brd/modules/<slug>.md` — its
Capabilities and Screens/Pages (not to silently break), Key Entities & API surface (data model
and contracts to respect), Data Flow (existing processes to preserve), and Test Coverage — which
stands in for plan.md/data-model.md/contracts/ as the boundary the redesign must respect.

### 2. Resolve the redesign target

- Locate the exact files implementing the target (component, page, endpoint, entity,
  migration). List them with a one-line role each. In the brownfield fallback, use
  `docs/brd/00-overview.md`'s Module Index and the target module's Screens/Pages table (if
  present) to narrow down which module/screen is meant before searching the filesystem.
- If the user supplied a **design file** (Figma export, Claude Design HTML/image, mockup
  screenshot), read it and extract: layout structure, fields and their grouping,
  states (empty/loading/error), and interaction notes. The design file is the source of
  truth for UI redesigns; contracts/ remain the source of truth for field names/types
  (or the target module's `technical.md` data model, in the brownfield fallback).
- If the target is ambiguous (multiple matches, or no match found), show the candidates
  and ask — do not guess.
- If the target module's `technical.md` reports no test coverage for the files you're
  about to touch, say so before proceeding — the user may want tests written first (see
  `/rudis.test`) so the redesign has a regression safety net.

### 3. Present the redesign plan (confirmation gate)

Before changing anything, show:

- Files to be modified (and any file to be created/deleted)
- What changes in each, in one line per file
- What is intentionally NOT touched
- Any out-of-scope change that needs separate approval

Wait for user confirmation, then proceed.

### 4. Checkpoint

- Record the current HEAD as the rollback point; if the working tree is dirty, tell the
  user and offer to commit/stash first.
- Append a session entry to `FEATURE_DIR/implementation-log.md`: date, checkpoint HEAD,
  redesign target, planned files.

### 5. Apply the redesign incrementally

- Edit file by file, smallest viable diff first (schema/migration → backend mapping →
  API/DTO → frontend), re-checking after each file that nothing outside the scope broke.
- For database redesigns (e.g., merging tables): generate a migration, update the
  data-model.md Data Design Decisions table, and update mappers so API contracts stay
  compliant.
- For UI redesigns: reuse the project's existing design-system components and shared
  styles; match sibling pages' conventions so the result is visually consistent
  (alignment across pages is a first-class requirement, not polish).
- Log each modified file in implementation-log.md as you go.

### 6. Verify

- Run type check / compile, lint, build, and the tests covering the touched files.
- **Self-fix loop**: fix failures and re-run, up to 3 attempts per distinct error. If an
  error survives, mark it UNRESOLVED in the log with a diagnosis, and say so plainly.
- **Contract compliance**: re-verify field names/types/casing of touched DTOs and forms
  against contracts/ — a redesign must never drift from the API spec.
- Confirm no file outside the approved scope was modified (`git status` / diff against
  the checkpoint).

### 7. Report

- Summary of what changed, per file (from the log)
- Diff overview: `git diff <checkpoint>` stats
- Verification results (checks run, pass/fail, anything UNRESOLVED)
- Rollback instructions: `git checkout <checkpoint> -- <path>` per file, or
  `git reset --hard <checkpoint>` to undo the whole redesign (warn: discards all changes)
- Suggest follow-ups when relevant (e.g., `/rudis.test` for the touched area, updating
  spec.md if behavior changed)

## Rules

- Never regenerate the whole module when one part was requested.
- Never mark the redesign complete while verification fails.
- Update design artifacts (data-model.md, contracts/, spec.md) when the redesign changes
  what they describe — artifacts and code must not drift apart. In the brownfield fallback
  (no such artifacts), update the relevant sections of the target module's semantic map
  `docs/brd/modules/<slug>.md` instead (Key Entities / API surface for data-model or contract
  changes, Capabilities / Screens for capability or screen changes, Data Flow if the process
  changed) so that map doesn't drift from the code either — prepend a Change Log entry.
