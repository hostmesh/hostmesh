# 0022. Creating customer accounts: a console command now, registration and an admin screen in Slice 6

- Status: accepted
- Date: 2026-10-22
- Issue: Create a customer account without tinker (#134)

## Context

Administrators are created with `php artisan administrator:create` (issue #122, spec section 28). Nothing created a customer's account, the login of the customer realm (the `User` model, decision 0010): the development guide used tinker and the factory.

The spec lists "Customers" and "Users" in the v0.1 scope (section 31) but describes neither registration nor an admin screen for customers. Its core workflow (section 20) starts with "Customer selects product → Checkout", which is Slice 6 (section 33), together with the admin screens for products, plans, subscriptions and invoices.

## Options considered

1. **A console command,** like `administrator:create`: for an operator with shell access, now.
2. **An admin endpoint and screen** to create customers: needs a customers list and, before it, the navigation question of section 39 (#250).
3. **Registration** in the portal: a public endpoint, email verification, abuse protection and accepting the terms. The spec's workflow creates the customer at checkout.

## Decision

Chosen by the owner on 2026-10-22: all three, each in its slice.

- **Now (Slice 0):** `php artisan customer:create <email> --name=<name>`. It creates the account through `App\Application\Commands\CreateCustomer` with a generated password of 40 characters, printed once and never taken from the command line, and records `user.created` (actor `system`, resource `user`) in the same transaction (decision 0013). Two-factor stays off; a customer turns it on in the portal. An address that is taken, in any case, is refused. An administrator and a customer may share an address: they are different realms. The command is registered in production.
- **Slice 6:** registration with checkout (#256), after a `spec-change` that describes it, and an admin screen to list and create customers (#257), which calls the same application command with the administrator as actor.

## Consequences

- The development guide creates a customer with the command instead of tinker.
- The admin app's audit screen has a label for `user.created` ("Customer created").
- "Customer" here is the login of the customer realm. If Slice 6 brings a separate billing customer (section 31 lists both "Customers" and "Users"), the command and the screen create both, or the names are revisited with that design.
