# OpenRouter generation boundaries

## Owners

- `OpenRouterRequestPolicy` owns configured model selection and the complete
  provider request contract: allowed models, compatible provider-side fallbacks,
  reasoning/sampling, bounded output, hashed request identity, document parsing,
  optional search and streaming usage. It reads configuration, not credentials,
  HTTP, cache, learner budgets or settlement state.
- `OpenRouterService` owns a single network generation, including credentials,
  circuit state, deadlines, redirect/rewind refusal, stream transport, safe
  diagnostics, HTTP failure classification and the immediate durable-landing
  callback. `OpenRouterEventStream` and `OpenRouterCurlFactory` retain their
  existing protocol responsibilities. There is no local model retry loop.
- `OpenRouterResponseDecoder` projects an already received success envelope into
  visible text, usage evidence, separate citation/file annotations and transport
  identifiers. It does not perform HTTP, select models or settle reservations.

Course prompt context, project relevance evaluation and project feedback jobs
resolve the request policy directly for model selection. The transport no longer
exposes `configuredModel` or a forwarding compatibility method. Queue payloads
and job constructors are unchanged; only injected runtime dependencies changed.

## Preserved contracts

The model names, parameter rules, prices, budget policies, timeouts, request
identity hash, response keys and circuit key are unchanged. Request projection
now happens before the circuit/extension check; it performs no network/cache
work and a blocked request still cannot generate or land a result.

Missing cost is not reported zero cost. Unusable success envelopes and interrupted
streams remain unknown paid outcomes, never automatic safe retries. Technical
lesson text is not confused with an HTTP error. Only accepted, usable results
reach durable landing, and a landing failure cannot trigger another generation.

## Evidence

`OpenRouterOwnershipTest` exercises policy and decoding with transport, paid-call
execution and budget/settlement services forbidden as dependencies, no HTTP and
no circuit access. It covers selection changes, allowlists, optional/bounded
payloads, identity, missing/zero cost, annotation separation and unusable output.

Existing payload/accounting/stream tests still exercise the composed service.
Transport fixtures use a real local socket for partial streams, deadlines,
interruptions, redirects and rewind refusal. Additional integration checks cover
failed durable landing and pre-generation rejection. This proves local contracts,
not availability of a live external provider or production deployment readiness.
