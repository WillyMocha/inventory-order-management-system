---
description: Execute the implementation planning workflow using the plan template to generate design artifacts.
handoffs: 
  - label: Create Tasks
    agent: rudis.tasks
    prompt: Break the plan into tasks
    send: true
  - label: Create Checklist
    agent: rudis.checklist
    prompt: Create a checklist for the following domain...
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Interactive Clarification (ask when a design decision is ambiguous)

When a design decision cannot be resolved confidently from the spec, constitution, existing repo, or conventions, ask the user instead of assuming:

- **Ask when** the decision is high-impact or hard to reverse — especially **Architecture Type / Integration Target** (e.g. micro-frontend vs standalone) and **any database deviation from the source** (the schema mirrors the source resource model by default; merging/flattening look-alike resources is an opt-in deviation — confirm before applying it). These are the two decisions that most often cause rework, so do NOT silently assume; if `/rudis.clarify` did not already settle them and the repo/docs don't make them clear, ask.
- **How**: pause and ask ONE specific question at a time. State your recommended option with a one-line reason, then give 2–4 concrete choices plus a short free-form alternative; the user may accept the recommendation by replying "yes" (same style as `/rudis.clarify`).
- **Proceed without asking** when the choice is low-impact and reversible or has a clear conventional default — pick the sensible default, note the assumption in the plan (Technical Context or Complexity Tracking), and continue.
- **Bounded (no wait-loop)**: ask at most twice per decision. If still unresolved, apply your recommended default, mark it `NEEDS CLARIFICATION` in the plan for review, and move on — never block indefinitely.
- **Non-interactive sessions** (CI, `rudis run`, headless): never block on a question — apply the documented default, mark it `NEEDS CLARIFICATION` in the plan, and continue.

## Outline

1. **Setup**: Run `.rudis/scripts/powershell/setup-plan.ps1 -Json` from repo root and parse JSON for FEATURE_SPEC, IMPL_PLAN, SPECS_DIR, BRANCH. For single quotes in args like "I'm Groot", use escape syntax: e.g 'I'\''m Groot' (or double-quote if possible: "I'm Groot").

2. **Load context**: Read FEATURE_SPEC and `.rudis/memory/constitution.md`. Load IMPL_PLAN template (already copied).

3. **Load project architecture context** (MANDATORY before any design decision):
   - Read the agent context file if present (`CLAUDE.md`, `AGENTS.md`, or equivalent) for project conventions.
   - Scan for architecture documentation: `docs/architecture*`, `docs/adr/`, `ARCHITECTURE.md`, and any knowledge/architecture docs referenced by the constitution.
   - Inspect the existing repository layout: detect monorepo vs single app, module federation / micro-frontend config (`webpack.config` with `ModuleFederationPlugin`, `craco.config`, `single-spa`, etc.), existing backend framework, and shared/common packages meant to be reused.
   - Record findings in the plan's Technical Context fields (**Architecture Type**, **Integration Target**). If the architecture cannot be determined from the repo and docs, mark it "NEEDS CLARIFICATION" — do NOT assume a standalone app.
   - New code MUST be designed to fit the detected architecture (e.g., a micro-frontend remote that plugs into the host shell, not a standalone app).
   - **If this feature has a UI**: separately detect the repo's **existing design system** — this is a visual-fit question, distinct from the structural checks above, and just as important for a brownfield repo. Look for: the UI component library actually imported in existing pages (not just listed in package.json — confirm it's used), the theme/token source (a theme file, CSS custom properties, a Tailwind config, a design-tokens package), and the icon set in use. Sample 2-3 existing pages/components if any exist. Record it in the plan's **Existing Design System** field. If the repo has no existing frontend at all, record "None found — greenfield frontend" — only then does a domain profile's own reference design system (if any) apply by default. A repo with its own existing design system takes priority over any profile default; do NOT introduce a second, unrelated component library/token system alongside what's already there. **Greenfield with nothing to inherit (no existing frontend AND no domain profile supplying a reference design system):** do NOT fall back to bare framework defaults — this is exactly where generated UIs come out basic. First establish a minimal but real design system and record it in this field: a brand/accent color plus hue-biased neutrals, semantic status colors (success / warning / danger, kept separate from the accent), a spacing scale, a radius step, a soft elevation/shadow level, a type scale, and one icon set. `/rudis.implement` then builds every page to the FE-011 polish bar against these tokens, so a no-profile project still comes out designed rather than default-flat.
   - **If this feature has a UI**: also carry the spec's **UI/UX & Screens** section forward into the plan — the screen inventory, per-screen states (loading/empty/error/populated), primary interactions/flows, and the design reference/look-and-feel. This is the design intent `/rudis.implement` must build toward; the design-system field above says *which components to use*, this says *what to build with them*. If the feature has a UI but the spec has no UI/UX & Screens section, flag it and derive a minimal screen inventory from the user stories rather than proceeding with no design intent.

4. **Execute plan workflow**: Follow the structure in IMPL_PLAN template to:
   - Fill Technical Context (mark unknowns as "NEEDS CLARIFICATION")
   - Fill Constitution Check section from constitution
   - Evaluate gates (ERROR if violations unjustified)
   - Phase 0: Generate research.md (resolve all NEEDS CLARIFICATION)
   - Phase 1: Generate data-model.md, contracts/, quickstart.md
   - Phase 1: Update agent context by running the agent script
   - Re-evaluate Constitution Check post-design

5. **Stop and report**: Command ends after Phase 2 planning. Report branch, IMPL_PLAN path, and generated artifacts.

## Phases

### Phase 0: Outline & Research

1. **Extract unknowns from Technical Context** above:
   - For each NEEDS CLARIFICATION → research task
   - For each dependency → best practices task
   - For each integration → patterns task

2. **Generate and dispatch research agents**:

   ```text
   For each unknown in Technical Context:
     Task: "Research {unknown} for {feature context}"
   For each technology choice:
     Task: "Find best practices for {tech} in {domain}"
   ```

3. **Consolidate findings** in `research.md` using format:
   - Decision: [what was chosen]
   - Rationale: [why chosen]
   - Alternatives considered: [what else evaluated]

**Output**: research.md with all NEEDS CLARIFICATION resolved

### Phase 1: Design & Contracts

**Prerequisites:** `research.md` complete

1. **Extract entities from feature spec** → `data-model.md`:
   - Entity name, fields, relationships
   - Validation rules from requirements
   - State transitions if applicable
   - **Use the richest available domain model as input** (do NOT re-invent it): read, in
     this priority order — (1) the resource-model digest `/rudis.specify` wrote under
     `FEATURE_DIR/inputs/*-resource-model.md` (cheap, authoritative text); (2) the raw
     source it references (TMF user guide PDF / swagger) — open it in **bounded page
     ranges or surgical `jq`/`grep`**, never wholesale; (3) the spec's **Key Entities**.
     If a field / sub-resource / state is not found in any of these, flag it
     `NEEDS CLARIFICATION` (or ask, per the clarification rules above) — never fabricate
     attributes, types, or lifecycles to fill the model.

   **Database design rules — mirror the source resource model.** The database schema
   MUST represent EXACTLY what the source spec/PDF defines (read it via the `inputs/`
   digest above) — this is what keeps the DB matching the source instead of "roughly
   similar". Before writing `data-model.md`:
   - **Mirror, don't redesign**: one table per resource and per sub-resource, one column
     per attribute (exact source name mapped to the project's casing, exact type, exact
     required/optional), enumerations/state types with exactly the source's values kept
     PER resource. Do NOT drop, rename, invent, or simplify.
   - **Keep distinct resources distinct**: e.g. `ActualCost` and `ProjectedCost` are
     separate tables (their cost-item state machines differ). Do NOT merge look-alike
     resources or collapse a resource's structure by default. A shared abstract base
     shown in the UML (e.g. both inheriting `Cost` with a discriminator) is documentation
     notation for shared fields, common across most TMF specs — it is NOT by itself
     grounds to merge. Only merge into one table with a discriminator when the source's
     **API Operations** section shows the subtypes are actually exposed/operated on as one
     polymorphic collection (a real shared endpoint returning mixed `@type` values); check
     that section, not just the UML diagram, before deciding.
   - **Sub-resources become child tables** (`1:N` FK); **value objects keep every field**
     (a table or a clearly-named column group) — never drop a nested field.
   - **Deviations (merge/flatten/denormalize) are opt-in only**: allowed solely when the
     user explicitly asks AND a mapper reproduces the exact source contract losslessly.
     Record any approved deviation in the Data Design Decisions table.
   - Add only platform-standard operational columns (id/audit/soft-delete) per the
     constitution beyond the source attributes; reuse existing conventions.
   - **Conformance checklist**: before finalizing, verify every resource, sub-resource,
     attribute, enum/state, and cardinality in the source maps to a table/column/constraint
     — nothing missing, renamed, invented, or simplified.
   - See `architecture/patterns/database-schema-design.md` for the mirroring rules, the
     worked TMF764 example, and the full conformance checklist.

2. **Generate API contracts** from functional requirements:
   - For each user action → endpoint
   - Use standard REST/GraphQL patterns
   - Output OpenAPI/GraphQL schema to `/contracts/`
   - **Per `.rudis/templates/security-standard.md` §2 (Access control)**: for every endpoint, record its required authentication method and authorization rule (role/ownership/tenant scope) directly in the contract — this is what `/rudis.implement`'s security gate checks the generated code against, so a contract with no auth annotation reads as "no auth required," not "unspecified." For any endpoint touching auth, payment, PII, or bulk/destructive actions, also apply the standard's §7 (Insecure Design) checklist now — rate limits, re-auth on sensitive changes, uniform error responses — and record the decision in research.md rather than leaving it to be improvised during implementation.

3. **Agent context update**:
   - Run `.rudis/scripts/powershell/update-agent-context.ps1 -AgentType claude`
   - These scripts detect which AI agent is in use
   - Update the appropriate agent-specific context file
   - Add only new technology from current plan
   - Preserve manual additions between markers

**Output**: data-model.md, /contracts/*, quickstart.md, agent-specific file

## Key rules

- Use absolute paths
- ERROR on gate failures or unresolved clarifications
