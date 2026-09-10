---
description: Turn production reality — incidents, telemetry findings, operational learnings — into spec, constitution, and NFR updates so the next regeneration is better. Closes the SDD feedback loop.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). The input is the source
material — an incident summary, a `/rudis.operate` finding, telemetry observations, or a plain
description of what went wrong or was learned in production.

## Goal

In SDD the specification is the source of truth and code is its regenerated expression. So a
production learning that lives only in a hotfix is a **drift**: the next time the feature is
regenerated from the spec, the fix disappears and the incident returns. This command closes the loop
— it converts operational reality into durable updates to the artifacts that generate code, so the
learning survives regeneration:

- A recurring **performance** problem → a new or tightened **non-functional requirement**.
- A **security** vulnerability → a **constraint** that constrains all future generations.
- A **correctness** incident → a clarified requirement, acceptance criterion, or edge case in the spec.
- A **systemic/architectural** lesson (affects more than one feature) → a **constitution** principle.

This command edits specification and governance artifacts. It does **not** touch application source
code — the immediate fix is `/rudis.operate`'s job; making that fix permanent in the spec is this
command's job.

## Execution Steps

### 1. Initialize & gather

Run `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -PathsOnly` from repo root and parse FEATURE_DIR. Read the artifacts this learning might amend:
`spec.md` (Functional Requirements, Non-Functional Requirements, Success Criteria, Assumptions, Edge
Cases), `.rudis/memory/constitution.md`, and — if present — `plan.md` and `data-model.md`. In a brownfield
project with no spec cycle, the amendable target is the module's `docs/brd/modules/<slug>.md` and
`00-overview.md`'s Constraints section instead.

If the input is thin, ask the user for the missing facts (what was the symptom, the root cause, the
fix applied) rather than inventing a narrative.

### 2. Classify the learning

For each distinct learning, decide **where it belongs** — this is the core judgment:

| Learning type | Lands in | As |
| --- | --- | --- |
| Performance / capacity / latency | spec.md → Non-Functional Requirements | a measurable NFR (e.g. "p95 < 200ms under 100 rps") |
| Security / data-protection | constitution.md (if it's a standing rule) or spec NFR | a constraint on all/this generation |
| Missed behavior / wrong output / edge case | spec.md → Functional Requirements / Edge Cases / Success Criteria | a new or clarified requirement |
| Wrong/invalidated assumption | spec.md → Assumptions | corrected, with a note it caused an incident |
| Cross-cutting architectural lesson | constitution.md | a new or amended principle |

A learning that only mattered for one incident and won't recur does **not** need a spec change —
say so rather than bloating the spec. Scope discipline cuts both ways.

### 3. Propose the amendments (verification gate)

Present a compact change set BEFORE editing:

- Each proposed edit: the target file + section, the exact before/after text, and the one-line
  incident/learning that justifies it (traceability — every change traces to a real learning).
- For a **constitution** amendment, follow its own amendment process if it defines one (rationale,
  version bump, backwards-compat note) — a constitution change is high-impact and ripples into every
  future generation, so call that out explicitly.
- Flag any amendment that would **invalidate existing implemented behavior** (i.e. the code no longer
  matches the amended spec) — that gap is the point, but the user must see it.

Wait for explicit approval. Ask ONE question at a time where a choice is genuinely the user's to make
(same style as `/rudis.clarify`).

### 4. Apply

- Edit only the approved artifacts. Add a dated entry to each amended file's change log / a
  "Post-Incident Amendments" note, citing the incident, so the *why* is never lost.
- Never rewrite an artifact wholesale — make the smallest edit that captures the learning, preserving
  everything else.

### 5. Report & route forward

- Summary of amendments, per file, each linked to its originating learning.
- The **resulting drift**: list any FR/SC/NFR now unsatisfied by the current code because the spec
  moved ahead of it.
- Route that drift into the normal flow: if code must change to meet the amended spec, recommend
  `/rudis.plan` → `/rudis.tasks` → `/rudis.implement` (for a substantial change) or `/rudis.redesign`
  (for a scoped one). This is the regeneration that makes the fix permanent.

## Rules

- Never edit application source code — this command amends specification/governance artifacts only.
- Every amendment must trace to a concrete, stated learning; do not speculatively harden the spec.
- Never apply an amendment (especially to the constitution) without explicit user approval.
- Preserve existing content — smallest edit that captures the learning; always leave a dated,
  incident-cited trail.
- Surface, don't hide, any drift the amendment creates between spec and current code.
