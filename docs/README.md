# FNLLA Core Documentation

- [Runtime reliability and migration contracts (unreleased)](framework/RUNTIME-RELIABILITY.md)

- [Application snapshot primitives (unreleased)](framework/APPLICATION-SNAPSHOTS.md)

FNLLA Core is the standalone public core package for the FNLLA framework family,
built for developers working with AI. Its contribution is architectural:
readable PHP, explicit contracts and verifiable behavior that developers and
coding agents can inspect together. The developer remains responsible for
direction, decisions and changes; Core requires no AI model or provider at
runtime.

This is the maintained documentation for **Core 2.7.0**. Guides describe the
implemented contracts and their limits. The documentation on the development
branch may be newer than the files in an immutable release ZIP; updating a guide
does not replace that release or upgrade an installed dependency.

- [Resilience: safe defaults and operations](resilience.md)

## Choose your path

The 2.6.0 starter also includes [FNLLA Navigation Steps 1–4](framework/NAVIGATION.md).
These optional starter enhancements preserve PHP SSR and native forms. Earlier immutable releases remain unchanged.

| Goal | Read first | Continue with |
| --- | --- | --- |
| Create a PHP application | [Getting started](framework/GETTING-STARTED.md) | [Runtime contracts](framework/RUNTIME-CONTRACTS.md) |
| Understand Core and Full | [Architecture](framework/ARCHITECTURE.md) | [Capability reference](framework/CAPABILITIES.md) |
| Write a reusable application operation | [First capability](framework/FIRST-CAPABILITY.md) | [Actions and events](framework/ACTIONS-AND-DOMAIN-EVENTS.md) |
| Configure permissions and tenancy | [Security primitives](framework/SECURITY-PRIMITIVES.md) | [Runtime hardening](framework/AUDIT-HARDENING.md) |
| Operate or upgrade an application | [Operations](framework/OPERATIONS.md) | [2.5.0 upgrade notes](releases/2.5.0.md), [outbox](framework/OUTBOX-OPERATIONS.md) |
| Work with coding agents | [Developer workflow](framework/DEVELOPER-WORKFLOW.md) | Repository [AGENTS.md](../AGENTS.md) |
| Build a trusted extension | [Product Specification](framework/PRODUCT-SPECIFICATION.md) | [Capability lifecycle](framework/CAPABILITIES.md#plugins-and-lifecycle) |

## Core and the API platform

Core defines application capabilities, validates their inputs and outputs,
enforces permissions, executes actions and projects machine-readable metadata.
FNLLA Full owns optional REST, capability discovery, MCP and SDK tooling.
Applications own domain handlers, identity and explicit exposure decisions.

Installing Core does not enable an HTTP API or add a Developer Panel. Core's
explicit route OpenAPI exporter remains available independently. See the
[responsibility matrix](framework/ARCHITECTURE.md#core-application-and-full).

## Reference library

- [Core 2.5.0 upgrade notes](releases/2.5.0.md) and the
  [maintainer release procedure](RELEASING.md).
- [Developer workflow](framework/DEVELOPER-WORKFLOW.md): source-derived context,
  bounded readiness, explicit OpenAPI and reproducible coding-agent tasks.
- [Outbox operations](framework/OUTBOX-OPERATIONS.md): leases, quarantine, retries
  and Redis queue migration requirements.
- [Runtime contracts](framework/RUNTIME-CONTRACTS.md) describe the core CLI,
  HTTP, routing, validation, session, cache, database and queue promises.
- [Product Specification](framework/PRODUCT-SPECIFICATION.md) defines the
  neutral `fnlla.product.v1` intent, evidence, drift and derived-graph layers.
- [Security primitives](framework/SECURITY-PRIMITIVES.md) define Core-only
  RBAC/policies, tenant context/isolation and the neutral audit event contract.
- [Actions and domain events](framework/ACTIONS-AND-DOMAIN-EVENTS.md) define the
  permission-first mutation, transactional receipt/outbox and delivery boundary.
- [Application capabilities](framework/CAPABILITIES.md) define shared actions,
  trusted contexts and safe metadata for downstream interfaces.
- [Runtime hardening](framework/AUDIT-HARDENING.md) explains identity, transaction,
  validation, logging and module-state compatibility changes in 2.5.0.
- [Trademark notice](framework/TRADEMARKS.md) explains how the FNLLA name and
  marks may be referenced.
- [Support policy](framework/SUPPORT.md) defines the public, best-effort support
  boundary.
- [Security policy](../SECURITY.md) explains private vulnerability reporting.

## Core Project Template

`php fnlla make:project <target-path> "App Name"` creates a minimal public
FNLLA Core application from this repository. The command only supports the Core
profile; the full FNLLA product owns the broader platform profile and is
available through [fnlla.com](https://fnlla.com).

## Boundary

Core documentation should describe reusable framework behavior, public package
contracts and supported extension points. The wider FNLLA product documentation
belongs at [fnlla.com](https://fnlla.com).

## FNLLA Family

FNLLA Core is the open framework core package. FNLLA is the full product and
application platform built around that core. `https://fnlla.com` is the public
website and documentation hub for both.

The FNLLA name comes from Finella Gardens in Dundee, Scotland. FNLLA Core is
created and maintained by **TechAyo**.

Lead Developer / Product Manager - **Marcin Kordyaczny**.
