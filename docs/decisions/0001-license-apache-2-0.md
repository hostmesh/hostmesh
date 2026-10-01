# 0001. License: Apache-2.0

- Status: accepted
- Date: 2026-10-01
- Issue: Adopt Apache-2.0: license files, SPDX policy and README

## Context

For now the project earns from support, custom work and add-ons on an openly licensed core, not from a managed service or commercial licensing. Operators should be able to restyle the customer portal without friction, and the portal is delivered to customers' browsers. In the open-source part of this market the billing alternative FOSSBilling is Apache-2.0, while cPanel and WHMCS are proprietary.

## Options considered

- **Apache-2.0**: permissive, explicit patent grant, no obligations for private modifications or proprietary add-ons.
- **MPL-2.0**: file-level copyleft, themes can stay private, no network clause.
- **AGPL-3.0**: strongest protection against closed or hosted forks, and enables dual licensing, but adds friction for operators who customize the portal.
- **GPL-3.0**: copyleft on distribution only; little benefit for network software.
- **Source-available (BSL, FSL and similar)**: not open source, which conflicts with the project's positioning.

## Decision

Apache-2.0 for the whole repository. The name and logos are not licensed by it.

## Consequences

- Anyone may fork and use the code commercially, including hosted offerings.
- A trademark policy is needed, and package names should be reserved.
- If the model later moves to a managed service or commercial licensing, only future versions can change, and already published versions stay Apache-2.0.
- Add-ons need documented extension points for backend modules (v0.2 backlog).
- This record is not legal advice. A short legal review is recommended before the repository is made public.
