## What and why

Closes #

## Checklist

- [ ] A failing test was written first, and all test suites pass
- [ ] Behavior matches the spec, or the spec and its revision history are updated (`spec-change`)
- [ ] If the API changed: OpenAPI spec and TypeScript client regenerated
- [ ] If the agent protocol changed: JSON Schemas updated and `schema_version` bumped where incompatible
- [ ] Security-relevant changes are noted (authentication, isolation, secrets, input handling)
- [ ] Every commit is signed off (`git commit -s`)
