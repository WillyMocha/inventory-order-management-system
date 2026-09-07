# Feature Specification: [FEATURE NAME]

**Feature Branch**: `[###-feature-name]`  
**Created**: [DATE]  
**Status**: Draft  
**Input**: User description: "$ARGUMENTS"

## User Scenarios & Testing *(mandatory)*

<!--
  IMPORTANT: User stories should be PRIORITIZED as user journeys ordered by importance.
  Each user story/journey must be INDEPENDENTLY TESTABLE - meaning if you implement just ONE of them,
  you should still have a viable MVP (Minimum Viable Product) that delivers value.
  
  Assign priorities (P1, P2, P3, etc.) to each story, where P1 is the most critical.
  Think of each story as a standalone slice of functionality that can be:
  - Developed independently
  - Tested independently
  - Deployed independently
  - Demonstrated to users independently
-->

### User Story 1 - [Brief Title] (Priority: P1)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently - e.g., "Can be fully tested by [specific action] and delivers [specific value]"]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]
2. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

### User Story 2 - [Brief Title] (Priority: P2)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

### User Story 3 - [Brief Title] (Priority: P3)

[Describe this user journey in plain language]

**Why this priority**: [Explain the value and why it has this priority level]

**Independent Test**: [Describe how this can be tested independently]

**Acceptance Scenarios**:

1. **Given** [initial state], **When** [action], **Then** [expected outcome]

---

[Add more user stories as needed, each with an assigned priority]

### Edge Cases

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right edge cases.
-->

- What happens when [boundary condition]?
- How does system handle [error scenario]?

## Requirements *(mandatory)*

<!--
  ACTION REQUIRED: The content in this section represents placeholders.
  Fill them out with the right functional requirements.
-->

### Functional Requirements

- **FR-001**: System MUST [specific capability, e.g., "allow users to create accounts"]
- **FR-002**: System MUST [specific capability, e.g., "validate email addresses"]  
- **FR-003**: Users MUST be able to [key interaction, e.g., "reset their password"]
- **FR-004**: System MUST [data requirement, e.g., "persist user preferences"]
- **FR-005**: System MUST [behavior, e.g., "log all security events"]

*Example of marking unclear requirements:*

- **FR-006**: System MUST authenticate users via [NEEDS CLARIFICATION: auth method not specified - email/password, SSO, OAuth?]
- **FR-007**: System MUST retain user data for [NEEDS CLARIFICATION: retention period not specified]

### Non-Functional Requirements

<!--
  Quality attributes (performance, security, availability, scalability, accessibility,
  compliance). Give each a stable NFR-### id so /rudis.analyze can map task coverage by id.
  Keep them measurable and technology-agnostic. Omit this subsection if none apply.
-->

- **NFR-001**: [e.g., "95% of list requests complete in under 500ms at 1000 concurrent users"]
- **NFR-002**: [e.g., "All access to customer data is authenticated and audit-logged"]

### Key Entities *(include if feature involves data)*

<!--
  Capture the domain model faithfully — this is what /rudis.plan normalizes into the
  database schema, so it keeps the DB aligned with the source. Stay at the DOMAIN level
  (no tables, columns, types, or storage decisions). When the feature is based on a
  source document (e.g. a TMF API user guide), mirror its resource model here. For each
  entity capture: key attributes, sub-entities/collections it owns, its state lifecycle
  (allowed states + transitions), and its relationships to other entities.
-->

- **[Entity 1]**: [What it represents]
  - Key attributes: [attribute, attribute, …]
  - Owns / contains: [sub-entities or collections, e.g. "one or more Cost Items"]
  - State lifecycle: [allowed states and transitions, if any]
  - Relationships: [how it relates to other entities]
- **[Entity 2]**: [What it represents — repeat the same sub-points as above]

## Success Criteria *(mandatory)*

<!--
  ACTION REQUIRED: Define measurable success criteria.
  These must be technology-agnostic and measurable.
-->

### Measurable Outcomes

- **SC-001**: [Measurable metric, e.g., "Users can complete account creation in under 2 minutes"]
- **SC-002**: [Measurable metric, e.g., "System handles 1000 concurrent users without degradation"]
- **SC-003**: [User satisfaction metric, e.g., "90% of users successfully complete primary task on first attempt"]
- **SC-004**: [Business metric, e.g., "Reduce support tickets related to [X] by 50%"]

## UI/UX & Screens *(mandatory when the feature has a user interface)*

<!--
  ACTION REQUIRED when this feature has any UI. This is the DESIGN INTENT that /rudis.plan and
  /rudis.implement build toward — without it the UI is generated blind and comes out generic.
  Capture user-facing intent (WHAT the user sees and does), NOT implementation (no component
  names, CSS, or framework APIs). Describing screens, states, and flows here is REQUIRED and is
  explicitly NOT an "implementation detail" — the no-implementation-detail rule does not apply
  to this section.
-->

### Design Reference

- **Design source**: [Figma / mockup / screenshot link or file, or "none — follow the profile's design system"]
- **Look & feel / brand**: [tone, brand colors/logo if any, density, light/dark, sibling app to match]
- **Existing UI to match**: [name the app/module whose look this must stay consistent with, or "greenfield"]

### Screen Inventory

<!-- One row per screen/page the user interacts with. Map each screen to the user story it serves. -->

| Screen | Purpose | Serves story | Key data shown | Primary actions |
| ------ | ------- | ------------ | -------------- | --------------- |
| [e.g. Cost list] | [what the user does here] | [US1] | [entities/fields] | [create, filter, export] |
| [e.g. Cost detail] | [...] | [US1] | [...] | [edit, approve, cancel] |

### Per-Screen Key States

<!--
  For each screen above, state what the user sees in each state. The frontend constitution
  (FE-011) mandates rich states — capture the INTENT here so it isn't skipped downstream.
-->

- **[Screen]**: loading = [what shows]; empty = [heading + helper + CTA the user should see]; error = [message/recovery]; populated = [which summary stats appear above the data, if any].

### Primary Interactions & Flows

- [Key interaction, e.g. "Selecting a row opens the detail in the same view (no full-page reload)"]
- [Navigation between screens, deep-linking needs, confirmations before destructive actions, etc.]

## Business Process Flow *(visual aid)*

<!--
  ACTION REQUIRED: Create a business process diagram showing the main flow(s)
  This helps stakeholders understand the workflow at a glance.
  Use Mermaid flowchart syntax below.
-->

### Primary User Journey Flow

```mermaid
flowchart TD
    Start([User Starts]) --> Step1["Step 1: [Action]"]
    Step1 --> Step2["Step 2: [Action]"]
    Step2 --> Decision{"Decision Point?"}
    Decision -->|Path A| Step3A["Step 3A: [Action]"]
    Decision -->|Path B| Step3B["Step 3B: [Action]"]
    Step3A --> End1([Journey Complete])
    Step3B --> End2([Journey Complete])
    
    style Start fill:#90EE90
    style End1 fill:#FFB6C6
    style End2 fill:#FFB6C6
    style Decision fill:#FFE5B4
```

### Alternative/Secondary Flows

<!--
  Optional: Add additional business process flows for alternate scenarios or edge cases
-->

```mermaid
flowchart TD
    Start([Secondary Flow Start]) --> Check{Condition?}
    Check -->|Yes| Path1["Path 1: [Action]"]
    Check -->|No| Path2["Path 2: [Action]"]
    Path1 --> End([Complete])
    Path2 --> End
```

## Business Actors & Interactions

<!--
  ACTION REQUIRED: Map out the different user roles/actors and their interactions
  Use a simple table to show who does what
-->

| Actor | Role | Key Interactions |
| ----- | ---- | ---------------- |
| [Actor 1] | [Role Description] | [Main actions they perform] |
| [Actor 2] | [Role Description] | [Main actions they perform] |
| [System] | [System Role] | [Automated responses/actions] |
