#!/usr/bin/env python3
"""Rudis governance guard (project-local Claude Code hook).

Enforces invariant G1: at most one `governance`-category plugin may be enabled in a
project's effective Claude Code settings. Managed by the Rudis CLI; do not edit by hand
(re-run `rudis governance` / `rudis init` to regenerate). Catalog of governance plugins:
.rudis/governance-catalog.json.
"""
import json
import os
import sys

BLOCKED_TOOLS = {"Edit", "MultiEdit", "Write", "NotebookEdit", "Bash"}


def _load_json(path):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return json.load(fh)
    except Exception:
        return None


def _effective_enabled_plugins(project_dir):
    """enabledPlugins merged across user + project scope (project wins)."""
    effective = {}
    paths = [
        os.path.join(os.path.expanduser("~"), ".claude", "settings.json"),
        os.path.join(project_dir, ".claude", "settings.json"),  # project last -> overrides
    ]
    for path in paths:
        data = _load_json(path) or {}
        enabled = data.get("enabledPlugins")
        if isinstance(enabled, dict):
            effective.update(enabled)
    return effective


def _active_governance(project_dir):
    catalog = _load_json(os.path.join(project_dir, ".rudis", "governance-catalog.json"))
    if not isinstance(catalog, dict):
        return None  # no catalog -> guard disabled (fail-open)
    names = set(catalog.get("governance_plugins") or [])
    if not names:
        return None
    effective = _effective_enabled_plugins(project_dir)
    return sorted(
        key for key, val in effective.items()
        if val is True and key.split("@", 1)[0] in names
    )


def main():
    try:
        raw = sys.stdin.read()
    except Exception:
        raw = ""
    try:
        payload = json.loads(raw) if raw.strip() else {}
    except Exception:
        payload = {}

    event = payload.get("hook_event_name") or payload.get("hookEventName") or ""
    project_dir = os.environ.get("CLAUDE_PROJECT_DIR") or os.getcwd()

    active = _active_governance(project_dir)
    if active is None:
        return 0  # fail-open

    short = [k.split("@", 1)[0] for k in active]

    if event == "SessionStart":
        if len(active) == 1:
            print(f"[rudis] Active governance: {short[0]}.")
        elif len(active) >= 2:
            print(
                "[rudis] GOVERNANCE CONFLICT: multiple governance plugins are active "
                f"({', '.join(short)}). Only one is allowed. Run `rudis governance disable` "
                "then `rudis governance use <name>` and restart Claude Code. "
                "Repository-modifying tools are blocked until this is resolved."
            )
        return 0

    if event == "PreToolUse" and len(active) >= 2:
        tool = payload.get("tool_name") or payload.get("toolName") or ""
        if tool in BLOCKED_TOOLS:
            reason = (
                f"Rudis governance guard: {len(active)} governance plugins are active "
                f"({', '.join(short)}); exactly one is allowed. Resolve with "
                "`rudis governance disable` then `rudis governance use <name>`, and restart "
                "Claude Code before modifying the repository."
            )
            print(json.dumps({
                "hookSpecificOutput": {
                    "hookEventName": "PreToolUse",
                    "permissionDecision": "deny",
                    "permissionDecisionReason": reason,
                },
                "decision": "block",
                "reason": reason,
            }))
        return 0

    return 0


if __name__ == "__main__":
    sys.exit(main())
