---
description: End-to-end SDD workflow orchestrator from constitution through implementation with confirmation gates between each phase.
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). The user input typically contains the **feature description** that will be used in Phase 2 (Specify). If empty, you will ask for it when Phase 2 begins.

## Overview

This command orchestrates the complete Spec-Driven Development lifecycle as a single guided workflow. Each phase pauses for user confirmation before advancing.

```text
Constitution ──▸ Specify ──▸ Clarify ──▸ Plan ──▸ Tasks ──▸ Analyze ──▸ Implement
 (required)                  (optional)                     (optional)
   Gate 0        Gate 1      Gate 2      Gate 3   Gate 4    Gate 5      Done
```

**User controls at every gate:**

- **continue** (or **yes**) — proceed to the next phase
- **skip** — skip the current optional phase (clarify, analyze only)
- **stop** — halt the workflow; a summary of completed work and resume instructions is shown

## Phase Script Reference

Each phase requires specific setup scripts. Detect the platform once at the start (`uname` on Unix, or presence of PowerShell) and use the appropriate variant throughout.

| Phase        | Unix (sh)                                                                    | Windows (ps)                                                                   |
| ------------ | ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Specify      | `.rudis/scripts/bash/create-new-feature.sh`                                         | `.rudis/scripts/powershell/create-new-feature.ps1`                                    |
| Clarify      | `.rudis/scripts/bash/check-prerequisites.sh --json --paths-only`                    | `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -PathsOnly`                  |
| Plan         | `.rudis/scripts/bash/setup-plan.sh --json`                                          | `.rudis/scripts/powershell/setup-plan.ps1 -Json`                                      |
| Plan (agent) | `.rudis/scripts/bash/update-agent-context.sh claude`                             | `.rudis/scripts/powershell/update-agent-context.ps1 -AgentType claude`             |
| Tasks        | `.rudis/scripts/bash/check-prerequisites.sh --json`                                 | `.rudis/scripts/powershell/check-prerequisites.ps1 -Json`                             |
| Analyze      | `.rudis/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks` | `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks` |
| Implement    | `.rudis/scripts/bash/check-prerequisites.sh --json --require-tasks --include-tasks` | `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -RequireTasks -IncludeTasks` |

## Pre-flight: Artifact Scan

Before starting any phase, determine the workflow entry point by scanning existing artifacts.

1. **Constitution check** — Read `.rudis/memory/constitution.md`. Search for unfilled placeholder tokens matching the regex pattern `\[[A-Z][A-Z_0-9]+\]` (e.g., `[PROJECT_NAME]`, `[PRINCIPLE_1_NAME]`). Classify:
   - **Missing**: File does not exist
   - **Template**: File exists but contains unfilled placeholder tokens or has less than 100 characters of non-comment content
   - **Ready**: File exists, no placeholder tokens remain, substantive content present

2. **Feature artifacts check** — Run `git branch --show-current` to detect active feature branch (pattern: `NNN-short-name`). If on a feature branch, check which artifacts exist under `specs/<branch>/`:
   - `spec.md` — specification
   - `plan.md` — technical plan
   - `data-model.md`, `contracts/` — design artifacts
   - `tasks.md` — task list

3. **Display Phase Roadmap** with detected status:

   ```text
   RUDIS ULTIMATE - SDD Workflow
   ========================================
    [status] Phase 1: Constitution
    [status] Phase 2: Specify
    [status] Phase 3: Clarify       (optional)
    [status] Phase 4: Plan
    [status] Phase 5: Tasks
    [status] Phase 6: Analyze       (optional)
    [status] Phase 7: Implement
   ========================================
   ```

   Status indicators:

   - `READY` = Artifact exists and valid
   - `PENDING` = Not yet started
   - `OPTIONAL` = Can be skipped

4. **Resume detection** — If artifacts beyond constitution are found, present the user with options:

   > Existing artifacts detected: [list files found]
   >
   > | Option | Action |
   > | ------ | ------ |
   > | A | **Start fresh** — new feature branch from Phase 2 |
   > | B | **Resume from Phase [N]** — continue where you left off |
   > | C | **Full restart** — re-run from constitution |
   >
   > Which option?

   If no artifacts found beyond constitution (or constitution itself is missing), proceed sequentially from Phase 1.

---

## Phase 1: Constitution

**Log**: `[Phase 1/7] Constitution: Checking project principles...`

**If constitution is Ready** (from pre-flight scan):

- Display a brief summary: project name, number of principles, version number
- Log: `[Phase 1/7] Constitution: Already configured. Skipping.`
- Proceed to Gate 0

**If constitution is Missing or Template**:

- Log: `[Phase 1/7] Constitution: Creating project principles...`
- Read `.claude/commands/rudis.constitution.md` and execute its full instructions
- This includes: collecting principle values, filling the template, consistency propagation, validation, and writing the file
- After completion, display the version and list of principles created

**Gate 0**:

> **Phase 1 complete** — Constitution is ready at `.rudis/memory/constitution.md`
>
> Next: Phase 2 - Specify (create feature specification)
>
> Continue? *(yes / stop)*

---

## Phase 2: Specify

**Log**: `[Phase 2/7] Specify: Creating feature specification...`

**Feature description**: Use `$ARGUMENTS` as the feature description. If `$ARGUMENTS` is empty:

> No feature description provided. What feature would you like to build? Describe it in natural language.

Wait for the user's response before proceeding.

**Execution**: Read `.claude/commands/rudis.specify.md` and execute its full instructions.

After completion, display:

- Branch name
- Spec file path
- Quality validation summary

**Gate 1**:

> **Phase 2 complete** — Feature specification created
>
> - Branch: `NNN-short-name`
> - Spec: `specs/<branch>/spec.md`
>
> Next phase options:
>
> - **continue** — Phase 3: Clarify (reduce ambiguity in the spec)
> - **skip** — Jump to Phase 4: Plan
> - **stop** — Halt workflow
>
> What would you like to do?

---

## Phase 3: Clarify (Optional)

**Log**: `[Phase 3/7] Clarify: Reducing specification ambiguity...`

**Execution**: Read `.claude/commands/rudis.clarify.md` and execute its full instructions.

After completion, display:

- Number of questions asked and answered
- Sections updated
- Coverage summary

**Gate 2**:

> **Phase 3 complete** — Specification clarified
>
> - Questions resolved: N
> - Sections updated: [list]
>
> Next: Phase 4 - Plan (create technical implementation plan)
>
> Continue? *(yes / stop)*

---

## Phase 4: Plan

**Log**: `[Phase 4/7] Plan: Generating technical implementation plan...`

**Execution**: Read `.claude/commands/rudis.plan.md` and execute its full instructions.

After completion, display:

- Plan file path
- List of generated design artifacts
- Any constitution gate warnings

**Gate 3**:

> **Phase 4 complete** — Technical plan generated
>
> - Plan: `specs/<branch>/plan.md`
> - Artifacts: [list generated files]
>
> Next: Phase 5 - Tasks (generate executable task list)
>
> Continue? *(yes / stop)*

---

## Phase 5: Tasks

**Log**: `[Phase 5/7] Tasks: Generating executable task list...`

**Execution**: Read `.claude/commands/rudis.tasks.md` and execute its full instructions.

After completion, display:

- Tasks file path
- Total task count and per-story breakdown
- Parallel opportunities

**Gate 4**:

> **Phase 5 complete** — Task list generated
>
> - Tasks: `specs/<branch>/tasks.md`
> - Total tasks: N (N parallel opportunities)
>
> Next phase options:
>
> - **continue** — Phase 6: Analyze (cross-artifact consistency check)
> - **skip** — Jump to Phase 7: Implement
> - **stop** — Halt workflow
>
> What would you like to do?

---

## Phase 6: Analyze (Optional)

**Log**: `[Phase 6/7] Analyze: Cross-artifact consistency check...`

**Execution**: Read `.claude/commands/rudis.analyze.md` and execute its full instructions.

**If CRITICAL issues found**:

> Critical issues detected in the analysis:
> [list critical issues]
>
> Options:
>
> - **resolve** — Address critical issues before implementing (recommended)
> - **proceed** — Continue to implementation with known issues
> - **stop** — Halt workflow
>
> What would you like to do?

If the user chooses **resolve**: present remediation suggestions and apply approved edits, then re-run each directly affected earlier phase **at most once** to regenerate its artifact. After that single remediation pass, advance forward to **Gate 5** — do **not** automatically loop back into Phase 6 (Analyze). If the user explicitly wants another consistency check, that is a fresh choice they make at Gate 5, not an automatic re-run. This guarantees the analyze → resolve → re-run cycle cannot ping-pong.

After completion, display:

- Issue counts by severity
- Coverage percentage
- Critical unresolved items (if proceeding anyway)

**Gate 5**:

> **Phase 6 complete** — Consistency analysis done
>
> - Issues: N critical, N high, N medium, N low
> - Requirement coverage: N%
>
> Next: Phase 7 - Implement (execute the task plan)
>
> Continue? *(yes / stop)*

---

## Phase 7: Implement

**Log**: `[Phase 7/7] Implement: Executing implementation plan...`

**Execution**: Read `.claude/commands/rudis.implement.md` and execute its full instructions.

After completion, display:

- Tasks completed vs total
- Files created/modified
- Test results summary

---

## Workflow Complete

Upon completing all phases, display:

```text
RUDIS ULTIMATE - Workflow Complete
========================================
 DONE      Phase 1: Constitution
 DONE      Phase 2: Specify
 DONE/SKIP Phase 3: Clarify
 DONE      Phase 4: Plan
 DONE      Phase 5: Tasks
 DONE/SKIP Phase 6: Analyze
 DONE      Phase 7: Implement
========================================

Branch: NNN-short-name
Feature directory: specs/<branch>/

Artifacts:
 - .rudis/memory/constitution.md
 - specs/<branch>/spec.md
 - specs/<branch>/plan.md
 - specs/<branch>/data-model.md
 - specs/<branch>/contracts/
 - specs/<branch>/tasks.md
 - specs/<branch>/quickstart.md
 - specs/<branch>/research.md
```

---

## Abort Summary

If the user stops at any gate, display:

```text
RUDIS ULTIMATE - Paused at Phase N
========================================
 DONE     Phase 1: Constitution
 DONE     Phase 2: Specify
 STOPPED  Phase 3: Clarify        <-- stopped here
 PENDING  Phase 4: Plan
 PENDING  Phase 5: Tasks
 PENDING  Phase 6: Analyze
 PENDING  Phase 7: Implement
========================================
```

Then provide manual resume commands:

> To continue later, run these commands in order:
>
> 1. `/rudis.clarify` (if not completed)
> 2. `/rudis.plan`
> 3. `/rudis.tasks`
> 4. `/rudis.implement`
>
> Or run `/rudis.ultimate` again to restart the guided workflow.

## Rules

1. **Always show progress** — Before starting any phase, log the phase number, name, and purpose. After completing, log the result before showing the gate prompt.
2. **Never skip gates** — Always ask for user confirmation, even if everything looks clean. The only exception is Phase 1 when the constitution is already ready (log it and proceed to Gate 0).
3. **Pass context forward** — Maintain awareness of all artifacts generated in previous phases. Do not re-run scripts unnecessarily if the output is already available from a prior phase. Because `/rudis.ultimate` runs as one continuous context, do NOT re-read a document that an earlier phase already loaded or that a prerequisite script already emitted (spec.md, plan.md, constitution, tasks.md — note the Analyze/Implement scripts already pass it via `--include-tasks` — data-model.md, contracts/, research.md). Reference the copy already in context; re-read a file only if a later phase changed it. Re-reading unchanged docs every phase is the single biggest avoidable token cost of the guided run.
4. **Respect user choices** — "stop" means stop immediately with summary. "skip" means skip the optional phase. Do not argue or re-ask.
5. **Error recovery** — If any phase fails, report the error clearly with the failing step, suggest fixes, and ask whether to retry or stop. Never silently proceed past a failure. Never auto-retry: each retry requires explicit user confirmation, and if the same phase fails again after a user-approved retry, stop and hand off to manual resume (see Abort Summary) rather than retrying a third time.
6. **Constitution is non-negotiable** — Phase 1 cannot be skipped. If the constitution has unfilled placeholders, it must be completed before any other phase runs.
7. **Delegate to sibling commands** — For the detailed instructions of each phase, always read the corresponding `rudis.*` command file installed alongside this one (same commands directory, same phase name — e.g. `rudis.specify` for Phase 2). Follow those instructions completely. Do not abbreviate or skip steps defined in those files.
8. **Cross-platform scripts** — Detect the platform at the start. Use the `sh` script variants on Unix/macOS and `ps` variants on Windows. See the Phase Script Reference table.
9. **Single quote safety** — For arguments containing single quotes (e.g., "I'm Groot"), use escape syntax: `'I'\''m Groot'` in bash or wrap in double quotes where possible.
