-- SPDX-License-Identifier: Apache-2.0
--
-- Runs once, when the PostgreSQL volume is first created. The control
-- plane's test suite uses this database, so tests never touch development
-- data.
CREATE DATABASE hostmesh_test;
