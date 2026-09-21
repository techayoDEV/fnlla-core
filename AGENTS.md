# FNLLA Core Maintainer Contract

```yaml
schema: fnlla.agent_contract.v1
repository: fnlla-core
role: provider-neutral open web framework runtime and public contracts
license: MIT
depends_on: []
must_not_depend_on: [techayodev/fnlla, fnlla.com, ai-provider-sdk, search-provider-sdk]
product_positioning:
  primary: Built for developers working with AI.
  architecture: provider-neutral AI-engineering foundation
  human_authority: humans direct, decide, review and control changes
  runtime_ai_required: false
  avoid_as_primary: [AI-ready, agent-compatible]
extensions:
  must_be: [generic, optional, backwards_reviewed, documented, tested]
  preferred_primitives: [container, config, routes, authorization, actions, events, queue]
ai_and_seo:
  provider_logic: forbidden
  product_growth_workflows: forbidden
  neutral_metadata_or_transport_contracts: allowed_when_reusable
security:
  secrets_in_source: forbidden
  fail_closed_for_privileged_actions: true
release:
  require: [strict_composer, tests, lint, package_validation, exact_commit_ci]
  owner_authorization_for: [push, tag, release, visibility_change]
```

Core must remain usable without the commercial FNLLA package, Developer Panel,
fnlla.com, an AI account or a search-provider account. Keep public contracts
small and provider-neutral. Product orchestration, dashboards, provider adapters
and marketing claims belong upstream in `techayodev/fnlla` or on fnlla.com.

Do not rewrite an existing released version. Document public API changes,
compatibility and upgrade impact, and run the relevant Core suites before any
release preparation.
