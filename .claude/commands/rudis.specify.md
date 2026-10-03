---
description: Create or update the feature specification from a natural language feature description.
handoffs: 
  - label: Build Technical Plan
    agent: rudis.plan
    prompt: Create a plan for the spec. I am building with...
  - label: Clarify Spec Requirements
    agent: rudis.clarify
    prompt: Clarify specification requirements
    send: true
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty).

## Outline

The text the user typed after `/rudis.specify` in the triggering message **is** the feature description. Assume you always have it available in this conversation even if `$ARGUMENTS` appears literally below. Do not ask the user to repeat it unless they provided an empty command.

Given that feature description, do this:

1. **Generate a concise short name** (2-4 words) for the branch:
   - Analyze the feature description and extract the most meaningful keywords
   - Create a 2-4 word short name that captures the essence of the feature
   - Use action-noun format when possible (e.g., "add-user-auth", "fix-payment-bug")
   - Preserve technical terms and acronyms (OAuth2, API, JWT, etc.)
   - Keep it concise but descriptive enough to understand the feature at a glance
   - Examples:
     - "I want to add user authentication" → "user-auth"
     - "Implement OAuth2 integration for the API" → "oauth2-api-integration"
     - "Create a dashboard for analytics" → "analytics-dashboard"
     - "Fix payment processing timeout bug" → "fix-payment-timeout"

2. **Scaffold the spec (default: zero git changes)**:

   a. **Zero-git-change default (hard rule).** By default `/rudis.specify` must make **no git changes at all**: it does not create a branch, does not switch/checkout any branch, does not commit, does not push, and does not fetch. It stays on whatever branch the developer is on and only scaffolds `specs/<branch-name>/`. Only create a branch when the user explicitly asks for one in this session (e.g. "buatkan branch baru", "create a branch for this"). Never run `git checkout`, `git switch`, `git checkout -b`, `git commit`, or `git push` yourself — the script is the only sanctioned git actor, and only via `--create-branch`.

   b. Determine the feature number **without touching git remotes** in the default path:
      - Specs directories: highest N in directories matching `specs/[0-9]+-<short-name>` (local, no network).
      - Local branches (read-only, no fetch): `git branch | grep -E '^[* ]*[0-9]+-<short-name>$'`.
      - Use N+1 (start at 1 if none). **Do not** run `git fetch`; do not query remotes. (Only when the user asked for a branch, you may additionally check `git ls-remote --heads origin` to avoid a number collision — the script itself fetches in that case.)

   c. Run the script `.rudis/scripts/powershell/create-new-feature.ps1 -Json "$ARGUMENTS"` with the calculated number and short-name:
      - Pass `--number N+1` and `--short-name "your-short-name"` along with the feature description.
      - Bash example (default, no branch): `.rudis/scripts/powershell/create-new-feature.ps1 -Json "$ARGUMENTS" --json --number 5 --short-name "user-auth" "Add user authentication"`
      - PowerShell example: `.rudis/scripts/powershell/create-new-feature.ps1 -Json "$ARGUMENTS" -Json -Number 5 -ShortName "user-auth" "Add user authentication"`
      - **Only if the user explicitly asked for a branch**, add `--create-branch` (PowerShell: `-CreateBranch`). The script stays on the current branch otherwise and, in the default path, makes no remote fetch either.

   d. The `--number`/`--short-name` control the `specs/` directory name in both cases. Check the `BRANCH_CREATED` field in the JSON output: it must be `false` unless the user asked for a branch.

   **IMPORTANT**:
   - Default path: no `git fetch`, no branch switch, no branch creation — spec files only.
   - Only match branches/directories with the exact short-name pattern.
   - If no existing branches/directories found with this short-name, start with number 1.
   - You must only ever run this script once per feature.
   - The JSON is provided in the terminal as output - always refer to it to get the actual content you're looking for
   - The JSON output will contain BRANCH_NAME and SPEC_FILE paths
   - For single quotes in args like "I'm Groot", use escape syntax: e.g 'I'\''m Groot' (or double-quote if possible: "I'm Groot")

3. Load `.rudis/templates/spec-template.md` to understand required sections.

4. Follow this execution flow:

    1. Parse user description from Input
       If empty: ERROR "No feature description provided"
    2. Extract key concepts from description
       Identify: actors, actions, data, constraints
    3. For unclear aspects:
       - Make informed guesses based on context and industry standards
       - Only mark with [NEEDS CLARIFICATION: specific question] if:
         - The choice significantly impacts feature scope or user experience
         - Multiple reasonable interpretations exist with different implications
         - No reasonable default exists
       - **LIMIT: Maximum 3 [NEEDS CLARIFICATION] markers total**
       - Prioritize clarifications by impact: scope > security/privacy > user experience > technical details
    4. Fill User Scenarios & Testing section
       If no clear user flow: ERROR "Cannot determine user scenarios"
    5. Generate Functional Requirements
       Each requirement must be testable
       Use reasonable defaults for unspecified details (document assumptions in Assumptions section)
    6. Define Success Criteria
       Create measurable, technology-agnostic outcomes
       Include both quantitative metrics (time, performance, volume) and qualitative measures (user satisfaction, task completion)
       Each criterion must be verifiable without implementation details
    7. Identify Key Entities (if data involved)
       When the description or an attached source document (e.g. a TMF API user guide)
       defines a data model, capture for EACH entity — at the domain level, no
       storage/tech decisions: what it represents, its key attributes, the
       sub-entities/collections it owns, its state lifecycle (allowed states +
       transitions), and its relationships to other entities. This is the domain model
       `/rudis.plan` mirrors into the database schema, so capturing it faithfully
       here is what keeps the DB aligned with the source. When a resource-model digest
       was produced under `inputs/` (see step 5), summarize here and link to it rather
       than duplicating every field.
    8. Return: SUCCESS (spec ready for planning)

5. Write the specification to SPEC_FILE using the template structure, replacing placeholders with concrete details derived from the feature description (arguments) while preserving section order and headings.

   **Preserve source inputs**: If the triggering message attached one or more source
   documents (e.g. a TMF API user guide PDF, a swagger/OpenAPI file, a data dictionary),
   copy them verbatim into `FEATURE_DIR/inputs/` and reference them from the spec's
   **Input** line (relative path). If a file cannot be copied, record its original
   location in the spec instead.

   Because a large binary (e.g. a 100-page PDF) is expensive and error-prone to re-parse
   in later phases — the main cause of a hallucinated data model — also extract a
   **resource-model digest** NOW, while the source is freshly attached and readable, into
   `FEATURE_DIR/inputs/<name>-resource-model.md`. Capture, verbatim from the source and
   with NO invented fields: each entity/resource, its attributes (name · type ·
   cardinality/mandatory), its sub-resources/collections, value objects, enumerations and
   state lifecycles (states + allowed transitions), and relationships. Read a large PDF in
   bounded page ranges (never all at once); slice a large swagger surgically. If a detail
   is absent from the source, write `[not specified in source]` — do NOT guess.
   `/rudis.plan` reads this digest as the authoritative model and opens the original only
   to spot-check.

6. **Specification Quality Validation**: After writing the initial spec, validate it against quality criteria:

   a. **Create Spec Quality Checklist**: Generate a checklist file at `FEATURE_DIR/checklists/requirements.md` using the checklist template structure with these validation items:

      ```markdown
      # Specification Quality Checklist: [FEATURE NAME]
      
      **Purpose**: Validate specification completeness and quality before proceeding to planning
      **Created**: [DATE]
      **Feature**: [Link to spec.md]
      
      ## Content Quality
      
      - [ ] No implementation details (languages, frameworks, APIs) — note: UI/UX intent (screens, states, flows, design reference) is NOT an implementation detail and IS expected
      - [ ] Focused on user value and business needs
      - [ ] Written for non-technical stakeholders
      - [ ] All mandatory sections completed
      
      ## Requirement Completeness
      
      - [ ] No [NEEDS CLARIFICATION] markers remain
      - [ ] Requirements are testable and unambiguous
      - [ ] Success criteria are measurable
      - [ ] Success criteria are technology-agnostic (no implementation details)
      - [ ] All acceptance scenarios are defined
      - [ ] Edge cases are identified
      - [ ] Scope is clearly bounded
      - [ ] Dependencies and assumptions identified
      
      ## Feature Readiness
      
      - [ ] All functional requirements have clear acceptance criteria
      - [ ] User scenarios cover primary flows
      - [ ] Feature meets measurable outcomes defined in Success Criteria
      - [ ] No implementation details leak into specification
      
      ## Notes
      
      - Items marked incomplete require spec updates before `/rudis.clarify` or `/rudis.plan`
      ```

   b. **Run Validation Check**: Review the spec against each checklist item:
      - For each item, determine if it passes or fails
      - Document specific issues found (quote relevant spec sections)

   c. **Handle Validation Results**:

      - **If all items pass**: Mark checklist complete and proceed to step 6

      - **If items fail (excluding [NEEDS CLARIFICATION])**:
        1. List the failing items and specific issues
        2. Update the spec to address each issue
        3. Re-run validation until all items pass (max 3 iterations)
        4. If still failing after 3 iterations, document remaining issues in checklist notes and warn user

      - **If [NEEDS CLARIFICATION] markers remain**:
        1. Extract all [NEEDS CLARIFICATION: ...] markers from the spec
        2. **LIMIT CHECK**: If more than 3 markers exist, keep only the 3 most critical (by scope/security/UX impact) and make informed guesses for the rest
        3. For each clarification needed (max 3), present options to user in this format:

           ```markdown
           ## Question [N]: [Topic]
           
           **Context**: [Quote relevant spec section]
           
           **What we need to know**: [Specific question from NEEDS CLARIFICATION marker]
           
           **Suggested Answers**:
           
           | Option | Answer | Implications |
           |--------|--------|--------------|
           | A      | [First suggested answer] | [What this means for the feature] |
           | B      | [Second suggested answer] | [What this means for the feature] |
           | C      | [Third suggested answer] | [What this means for the feature] |
           | Custom | Provide your own answer | [Explain how to provide custom input] |
           
           **Your choice**: _[Wait for user response]_
           ```

        4. **CRITICAL - Table Formatting**: Ensure markdown tables are properly formatted:
           - Use consistent spacing with pipes aligned
           - Each cell should have spaces around content: `| Content |` not `|Content|`
           - Header separator must have at least 3 dashes: `|--------|`
           - Test that the table renders correctly in markdown preview
        5. Number questions sequentially (Q1, Q2, Q3 - max 3 total)
        6. Present all questions together before waiting for responses
        7. Wait for user to respond with their choices for all questions (e.g., "Q1: A, Q2: Custom - [details], Q3: B")
        8. Update the spec by replacing each [NEEDS CLARIFICATION] marker with the user's selected or provided answer
        9. Re-run validation after all clarifications are resolved

   d. **Update Checklist**: After each validation iteration, update the checklist file with current pass/fail status

7. Report completion with branch name, spec file path, checklist results, and readiness for the next phase (`/rudis.clarify` or `/rudis.plan`).

**NOTE:** The script creates and checks out the new branch and initializes the spec file before writing.

## General Guidelines

## Quick Guidelines

- Focus on **WHAT** users need and **WHY**.
- Avoid HOW to implement (no tech stack, APIs, code structure).
- **UI/UX intent IS in scope — it is not "HOW"**: for any feature with a user interface, fill the **UI/UX & Screens** section (screen inventory, per-screen states, primary interactions/flows, design reference). What the user sees and does is user intent and is REQUIRED so the frontend isn't generated blind; only concrete implementation (component names, CSS, framework APIs) is out of scope.
- Written for business stakeholders, not developers.
- DO NOT create any checklists that are embedded in the spec. That will be a separate command.

### Section Requirements

- **Mandatory sections**: Must be completed for every feature
- **Optional sections**: Include only when relevant to the feature
- When a section doesn't apply, remove it entirely (don't leave as "N/A")

### For AI Generation

When creating this spec from a user prompt:

1. **Make informed guesses**: Use context, industry standards, and common patterns to fill gaps
2. **Document AND surface assumptions**: Record reasonable defaults in the Assumptions section — then, after writing the spec, list those assumptions back to the user (especially any invented default they never stated: auth method, retention period, API style, etc.) and ask them to confirm or correct before running `/rudis.plan`. An unconfirmed invented default must not silently become an authoritative requirement downstream.
3. **Limit clarifications**: Maximum 3 [NEEDS CLARIFICATION] markers - use only for critical decisions that:
   - Significantly impact feature scope or user experience
   - Have multiple reasonable interpretations with different implications
   - Lack any reasonable default
4. **Prioritize clarifications**: scope > security/privacy > user experience > technical details
5. **Think like a tester**: Every vague requirement should fail the "testable and unambiguous" checklist item
6. **Common areas needing clarification** (only if no reasonable default exists):
   - Feature scope and boundaries (include/exclude specific use cases)
   - User types and permissions (if multiple conflicting interpretations possible)
   - Security/compliance requirements (when legally/financially significant)

**Examples of reasonable defaults** (don't ask about these):

- Data retention: Industry-standard practices for the domain
- Performance targets: Standard web/mobile app expectations unless specified
- Error handling: User-friendly messages with appropriate fallbacks
- Authentication method: Standard session-based or OAuth2 for web apps
- Integration patterns: RESTful APIs unless specified otherwise

### Success Criteria Guidelines

Success criteria must be:

1. **Measurable**: Include specific metrics (time, percentage, count, rate)
2. **Technology-agnostic**: No mention of frameworks, languages, databases, or tools
3. **User-focused**: Describe outcomes from user/business perspective, not system internals
4. **Verifiable**: Can be tested/validated without knowing implementation details

**Good examples**:

- "Users can complete checkout in under 3 minutes"
- "System supports 10,000 concurrent users"
- "95% of searches return results in under 1 second"
- "Task completion rate improves by 40%"

**Bad examples** (implementation-focused):

- "API response time is under 200ms" (too technical, use "Users see results instantly")
- "Database can handle 1000 TPS" (implementation detail, use user-facing metric)
- "React components render efficiently" (framework-specific)
- "Redis cache hit rate above 80%" (technology-specific)
