# AGENTS.md — Working Rules

## Working rules
- Verify facts, paths, schemas, and contracts from source. Never invent them.
- Investigate enough to implement the current task safely: read relevant
  documentation and affected code, then trace contracts, call sites,
  schemas/migrations, tests, and material dependencies as needed.
- Do not inspect unrelated areas or read every repository file. Expand scope
  only when ownership, behavior, safety, contracts, or verification is unclear.
- Once you know what must change, where, which contract to preserve, and how
  to verify it, implement rather than continuing to investigate.
- Preserve security, authorization, workspace isolation, data correctness,
  state transitions, and infrastructure restrictions.
- Verify acceptance criteria and report checks run, results, and any blocker.
- NEVER ask the user to send, paste, or upload files; inspect the authorized
  workspace directly. Only ask the user for DECISIONS (preferences, tradeoffs).
- If something is genuinely missing, note it and proceed with what exists.
- For changes: explain the plan, then implement. Follow existing conventions.
