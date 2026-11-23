# Commercial release requirements

## Product boundary

This is the standalone BYOK edition, distributed as a WordPress-installable ZIP
with manual updates. The seller does not supply hosting, paid model credits,
license activation or automatic updates in this edition.

The setup, publication gate and local health checks reduce installation mistakes.
They do **not** make every WordPress combination enterprise-certified. Do not
advertise blanket membership/LMS compatibility, guaranteed correct answers,
unlimited scale, HIPAA/GDPR compliance or fully autonomous agents.

## Implemented customer-facing foundations

| Requirement | Implemented behavior |
| --- | --- |
| No theme/plugin dependency | Own plugin tables/assets; optional integrations |
| First-run experience | Industry, local/BYOK, primary provider, display, optional presets and privacy review |
| Safe first install | Unpublished, local-only, shortcode-only, transcript storage off |
| Explicit launch | Blocks incomplete schema/index/jobs/review/service checks; admin-only preview before launch |
| Cost awareness | Single-provider guided BYOK, atomic per-provider attempt caps shared by chat/tests, atomic visitor windows and independent embedding cap; upstream currency limits still required |
| Retrieval lifecycle | Incremental stable passages, durable retry/checkpoint queue and source visibility checks |
| Restricted-content defaults | Recognised membership/LMS systems deny post sources without a connector; custom systems must register, shared index permits anonymous-public content only |
| Privacy operations | Bounded personal-data export/erasure, explicit database errors, retention and opt-in transcript storage |
| Operations | Site Health, configuration-bound test records, CLI readiness/worker/support |
| Optional agent bridge (1.5.0) | Public site profile/context to separately deployed FastAPI/LangGraph service; tenant-specific configuration; staff read tools isolated from visitor chat |
| Customer support | Allowlisted opt-in download; no automatic diagnostic uploads |
| Distribution | Clean ZIP excludes tests/tools; standard WordPress upload/replace |
| Data ownership | Retained on deactivation/default uninstall; explicit local-data deletion option |

## Required gates before a general commercial production launch

Some offline evidence is now available. The remaining deployment/business gates
are real work, not features that a synthetic test can certify:

| Gate | Required evidence or decision |
| --- | --- |
| Live provider qualification | Test actual embedding and generation models/API versions, limits, retries, quotas and outages; current contracts are not live-model certification |
| Answer-quality evaluation | Representative corpora/questions, negative questions, factual citations, prompt-injection/abstention evaluation and explicit acceptance thresholds per supported use case |
| Capacity and resilience | Six-process queue/offline-chat, quota/storage failure and actual SQL restore drills passed. Still establish sustained HTTP/generative-load, memory and recovery objectives on the target deployment |
| Compatibility matrix | WP 6.9.4/PHP 8.2.29/MySQL 8.4.0, single-site/subdirectory multisite, Twenty Twenty-One browser smoke and denied CREATE/ALTER recovery passed. Other PHP versions, hosts, caches, themes and actual WooCommerce versions still require qualification |
| Access controls | Fail-closed recognised-system mitigation and revocation tests passed. No blanket membership/enrolment/tenant connector support; each advertised integration needs an actual tested anonymous-public-only connector |
| Security review | Scoped AI-assisted static review identified a restricted-content exposure risk; remediation and regression tests passed. This is not an independent professional audit, penetration test or certification |
| Regulated data | Paginated local export/erasure, retention and database-failure tests passed. Threat/privacy assessment, processor agreements, external deletion and operator policies remain deployment-specific |
| Upgrade/rollback | Prior-schema missing-column/denied-grant recovery and current-version SQL restore passed. Validate supported real prior release migrations and matched files/database rollback on the customer's staging host |
| Support commitments | Seller contact, support process, maintenance/security-update policy, supported versions and refund/terms/privacy disclosures |
| Distribution/legal | Final brand ownership, trademark/domain/contact review, GPL distribution obligations, third-party notices and server/model license review |
| Release integrity | Approved package/checksum, no secrets/dev fixtures, release notes and reproducible validation steps; signed updates if a real update service is added |

## Advanced capabilities not shipped

- Reranking, query rewriting and a full parent-child section hierarchy.
- Tokenizer-based total prompt accounting and calibrated semantic abstention.
- Atomic shadow-index activation/rollback and automated old-profile cleanup.
- Broad membership connectors, SSO administration, autonomous agent/write execution.
- One-click external OAuth onboarding and live-qualified CRM/social connectors.
- Managed infrastructure, license verification, a sales portal or automatic updater.

Not every advanced capability is mandatory for a small public FAQ site. Their
absence must be reflected in the product tier and claims. Enterprise contracts
need deployment-specific acceptance rather than a universal "enterprise" label.

## Packaging

From the repository on Windows:

```powershell
.\plugins\omnithread-ai\tools\package.ps1 -Destination 'C:\releases\omnithread-ai-1.5.0.zip'
```

Use a new destination outside the source tree. The tool copies only runtime
assets, documentation and plugin entry/uninstall files into a temporary staging
directory, checks the ZIP entry point, rejects developer-folder leakage, emits a
SHA-256 checksum and removes its staging files. It does not publish or upload.

Run the isolated validation runner described in [RAG operations](RAG-OPERATIONS.md).
The current offline database suites and SQL restoration drill passed 156 checks;
17 browser assertions and two actual local HTTP checks passed separately for 1.4.2.
Version 1.5.0 adds 17 bridge contract checks plus two actual PHP/FastAPI/LangGraph
HTTP checks; the separate service has 34 Python contract tests. These do not
qualify real CRM/social accounts or hosted service operation.
Setup tests cover safe first install,
publication gating, FAQ-only onboarding, explicit rebuild failures, credential
preservation, test invalidation/expiry, support-report allowlisting and permissions.
They are not a substitute for browser, provider, compatibility and load testing.

The budget suite launches 12 actual PHP worker processes behind a start barrier.
Of 60 simultaneous reservation attempts, exactly seven may succeed against a
quota of seven. It also checks failed-call/test accounting, successful/cache
routing, independent provider failover, storage-outage errors and schema upgrade.
This tests the quota primitive, not a sustained production chat benchmark.

The release suite runs six real PHP processes against a shared durable queue and
six against the offline chat service. It also checks 20 exact-topic questions and
an unknown-topic abstention on synthetic public content. This does not evaluate
semantic relevance, factual LLM answers, multilingual quality or adversarial model
behavior. See the operations guide for measured timings and the exact matrix.

## What is needed to earn from this edition

The plugin can be delivered as a paid downloadable ZIP without a license server.
The seller still needs an actual checkout/payment account, fulfillment/download
delivery, support channel and public terms/refund/privacy information. These
services are external to customer WordPress installations. Do not embed seller
payment credentials in the distributed plugin.

Choose a payment/store service and fulfill this release through that service's
approved download flow. No checkout or license integration is included because
the selected distribution model is manual ZIP updates, not a managed subscription.
Selling a GPL plugin is permitted, but GPL redistribution rights cannot be removed
by an invented license-key restriction. Obtain appropriate legal advice for final
commercial terms.

Offer a limited public-content pilot before a broad enterprise launch. Validate
the exact customer models, hosts and sources; charge for the supported product
and service you actually deliver, not unimplemented enterprise/agent features.
