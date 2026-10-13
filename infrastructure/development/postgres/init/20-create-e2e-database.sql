-- SPDX-License-Identifier: Apache-2.0
--
-- Runs once, when the PostgreSQL volume is first created. The end-to-end
-- stack (compose.e2e.yaml) uses this database, so the Playwright suite
-- never touches development data. `make e2e-up` creates it on a volume
-- that is older than this file.
CREATE DATABASE hostmesh_e2e;
