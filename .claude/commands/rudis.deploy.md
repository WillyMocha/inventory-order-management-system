---
description: Prepare deployment automation for an implemented feature — containerization, CI/CD pipeline, and environment config — following Plan-Verify-Generate. First phase of the Operations lifecycle after /rudis.implement.
handoffs:
  - label: Wire Observability
    agent: rudis.operate
    prompt: Set up telemetry, monitoring, and anomaly detection for what was just prepared for deployment
---

## User Input

```text
$ARGUMENTS
```

You **MUST** consider the user input before proceeding (if not empty). Treat it as scope/target
for the deployment (e.g. "just the staging pipeline", "containerize the API only", "target
Cloud Run") — not a new feature description.

## Goal

`/rudis.implement` produces working, tested code; this command prepares that code to **ship and
run in production**. It is the first phase of the Operations lifecycle. It exists because velocity
gains from faster building are lost if the path to production stays slow: a 10x faster build behind
a slow, manual pipeline delivers nothing faster. This command generates the deployment automation
so shipping keeps pace with building.

## Plan-Verify-Generate (STRICT)

This command **never deploys to a real environment on its own**, and never generates infrastructure
without showing a plan first:

1. **Plan** — survey the project, propose a concrete deployment approach, and show it.
2. **Verify** — wait for explicit user approval of the plan before writing any file.
3. **Generate** — write the deployment artifacts (config, manifests, pipeline definitions).

A live deploy to any shared/production environment (running the pipeline, pushing an image to a
registry, applying manifests to a cluster) is an **irreversible, outward-facing action**: propose it,
state exactly what will happen and where, and execute it ONLY after an explicit, specific "yes". By
default this command stops at generated-and-validated artifacts plus a dry-run — it does not push.

## Execution Steps

### 1. Initialize & discover

Run `.rudis/scripts/powershell/check-prerequisites.ps1 -Json -PathsOnly` from repo root and parse FEATURE_DIR (and available paths). Then discover the
deployment-relevant facts — sample, don't read everything:

- **Tech stack & architecture**: read plan.md (Architecture Type, Integration Target, tech stack) if
  it exists; otherwise detect from build manifests (`package.json`, `pyproject.toml`, `pom.xml`,
  `go.mod`, `*.csproj`, etc.) and the agent context file (`CLAUDE.md`/`AGENTS.md`). A micro-frontend
  remote deploys differently from a standalone app — honor what the project actually is.
- **Existing deployment setup** (do not duplicate or fight it): existing `Dockerfile*`,
  `docker-compose*`, `.gitlab-ci.yml`/`.github/workflows/`, `k8s/`/`helm/` charts, `Procfile`,
  serverless configs, or IaC (`*.tf`). If a pipeline already exists, this is an **extend**, not a
  rewrite — add the feature's build/test/deploy steps to the existing structure.
- **Runtime needs**: from data-model.md / research.md / plan.md — datastores, migrations, external
  services, required environment variables and secrets (names only — never values).
- **Build & test commands**: the project's real build, lint, and test commands (from plan.md or the
  manifest's scripts) — the pipeline must run the same checks a developer runs locally.
- **Git remote host**: `git remote -v` — GitLab vs GitHub decides which CI format to generate
  (`.gitlab-ci.yml` vs `.github/workflows/*.yml`). Match the repo's actual host; do not assume.

### 2. Present the deployment plan (verification gate)

Before writing anything, show a compact plan:

- Target environment(s) and platform (and how you inferred them — ask if genuinely undeterminable).
- Artifacts you will create or extend, one line each (e.g. `Dockerfile`, `.gitlab-ci.yml` build+test+deploy stages, `.env.example`).
- Pipeline stages in order (build → test → containerize → deploy), and which checks each runs.
- Secrets/env vars the pipeline will **reference** (by name) and where the user must set them — you never write secret values into the repo.
- What is explicitly out of scope (e.g. provisioning cloud infrastructure, DNS, TLS certs).

Wait for explicit user confirmation. If the target platform is ambiguous, ask ONE question with a
recommended default (same style as `/rudis.clarify`) rather than guessing.

### 3. Checkpoint

Record the current HEAD as the rollback point; if the working tree is dirty, tell the user and offer
to commit/stash first. Append a session entry to `FEATURE_DIR/implementation-log.md` (date,
checkpoint HEAD, "deploy: <targets>", planned files).

### 4. Generate deployment artifacts

Generate only the approved artifacts, matching what the repo already uses:

- **Containerization** (if applicable): a multi-stage `Dockerfile` (build → slim runtime), a
  `.dockerignore`, and — for local parity — a `docker-compose.yml` wiring the app to its datastores
  and required services.
- **CI/CD pipeline**: build → test (the project's real checks, not placeholders) → containerize →
  deploy, in the repo's native CI format. Deploy stages targeting a shared environment MUST be gated
  (manual trigger / protected environment / approval), never fire automatically on every push.
- **Configuration**: an `.env.example` listing every required variable by name with a one-line note
  (no real values). If migrations exist, wire a migration step to run before the app starts.
- **Health & readiness**: ensure the app exposes (or add) a health/readiness endpoint the platform
  can probe, if the stack supports one.
- Mimic the project's existing config conventions (naming, structure, indentation) rather than
  importing an unrelated house style.

### 5. Validate (no live deploy)

- Validate/lint the generated config with the cheapest available check: `docker build` (or at least
  `docker build --check` / hadolint if present), CI linter (`gitlab-ci-lint` / actionlint) if
  available, `docker compose config`, `helm lint`, `terraform validate` — whatever applies.
- **Dry-run only**: do NOT run the pipeline against a real environment, push an image, or apply
  manifests to a cluster. If the user explicitly asked for a live deploy, re-confirm the exact target
  and consequences first (see Plan-Verify-Generate), and only then proceed.
- **Self-fix loop**: fix validation failures and re-run, up to 3 attempts per distinct error. If one
  survives, mark it UNRESOLVED in the log with a diagnosis and say so plainly.
- **Token thrift**: collapse passing build/validate output to counts; keep verbatim only the failing
  messages needed to diagnose.

### 6. Report

- Artifacts created/extended, per file (from the log).
- The exact manual steps to actually deploy (set these secrets → run this pipeline / trigger this
  stage), so the user — not this command — performs the irreversible action.
- Any UNRESOLVED validation error with diagnosis.
- Rollback instructions: `git checkout <checkpoint> -- <path>` per file, or `git reset --hard
  <checkpoint>` to undo everything (warn: discards all changes).
- Suggested next step: `/rudis.operate` to wire telemetry, monitoring, and anomaly detection for the
  deployed service.

## Rules

- Never perform a real, irreversible deploy without an explicit, specific user "yes" naming the target.
- Never write secret values into the repository — reference them by name and tell the user where to set them.
- Extend an existing pipeline; never silently replace one.
- Honor plan.md's Architecture Type / Integration Target — deployment shape follows the real architecture.
- Never declare done while a generated config fails validation.
