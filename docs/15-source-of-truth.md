# Source of Truth and Document Maintenance

## Source priority
1. Original SEVIMA task brief and any official mock gateway materials.
2. Verified behavior of the actual project and automated tests.
3. Final decisions in `DECISIONS.md`.
4. Proposal documents in this knowledge base.

If proposal documents conflict with the official brief, the brief wins. If a proposal conflicts with the verified implementation, update the proposal or implementation deliberately—do not leave silent contradictions.

## Maintenance checklist
When changing a route, payload, status, schema, or security rule:
- Update the relevant docs file.
- Update `contracts/openapi.yaml` if API contract changes.
- Add/update automated tests.
- Update `DECISIONS.md` if a technical decision changes.
- Ensure `CLAUDE.md` stays concise and points to authoritative docs.
- Record real AI collaboration in `AI_NOTES.md`.
