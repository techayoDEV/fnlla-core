# FNLLA Core Documentation

FNLLA Core is the standalone public core package for the FNLLA framework family,
built for developers working with AI. Its contribution is architectural:
readable PHP, explicit contracts and verifiable behavior that developers and
coding agents can inspect together. The developer remains responsible for
direction, decisions and changes; Core requires no AI model or provider at
runtime.

These notes cover the package boundary and the runtime contracts that are safe
to describe from the Core repository itself.

## Start Here

- [Runtime contracts](framework/RUNTIME-CONTRACTS.md) describe the core CLI,
  HTTP, routing, validation, session, cache, database and queue promises.
- [Product Specification](framework/PRODUCT-SPECIFICATION.md) defines the
  neutral `fnlla.product.v1` intent, evidence, drift and derived-graph layers.
- [Security primitives](framework/SECURITY-PRIMITIVES.md) define Core-only
  RBAC/policies, tenant context/isolation and the neutral audit event contract.
- [Actions and domain events](framework/ACTIONS-AND-DOMAIN-EVENTS.md) define the
  permission-first mutation, transactional receipt/outbox and delivery boundary.
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
