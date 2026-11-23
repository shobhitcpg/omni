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
| Cost awareness | Single-provider guided BYOK, explicit tests, independent embedding cap; hosting/inference costs disclosed |
| Retrieval lifecycle | Incremental stable passages, durable retry/checkpoint queue and source visibility checks |
| Operations | Site Health, configuration-bound test records, CLI readiness/worker/support |
| Customer support | Allowlisted opt-in download; no automatic diagnostic uploads |
| Distribution | Clean ZIP excludes tests/tools; standard WordPress upload/replace |
| Data ownership | Retained on deactivation/default uninstall; explicit local-data deletion option |

## Required gates before a general commercial production launch

These are real remaining work, not completed features:

| Gate | Required evidence or decision |
| --- | --- |
| Live provider qualification | Test actual embedding and generation models/API versions, limits, retries, quotas and outages; current contracts are not live-model certification |
| Answer-quality evaluation | Representative corpora/questions, negative questions, factual citations, prompt-injection/abstention evaluation and explicit acceptance thresholds per supported use case |
| Capacity and resilience | Parallel-worker/chat load tests, latency/memory targets, durable rate/spend controls under concurrency, backup/restore and service-outage drills |
| Compatibility matrix | Maintained PHP/WordPress versions, multiple themes/hosts/object caches, multisite, database grants and supported WooCommerce versions |
| Access controls | Explicit tested membership/enrolment/tenant connectors for each advertised restricted-content integration |
| Independent security review | Review public REST, credentials/egress, queue, storage, integrations and browser rendering before release; remediation and regression evidence |
| Regulated data | Threat/privacy assessment, provider/processor agreements, retention/deletion/export verification and operator-specific policies |
| Upgrade/rollback | Staging upgrades from supported prior versions, schema failure recovery and matched files/database rollback tests |
| Support commitments | Seller contact, support process, maintenance/security-update policy, supported versions and refund/terms/privacy disclosures |
| Distribution/legal | Final brand ownership, trademark/domain/contact review, GPL distribution obligations, third-party notices and server/model license review |
| Release integrity | Approved package/checksum, no secrets/dev fixtures, release notes and reproducible validation steps; signed updates if a real update service is added |

## Advanced capabilities not shipped

- Reranking, query rewriting and a full parent-child section hierarchy.
- Tokenizer-based total prompt accounting and calibrated semantic abstention.
- Atomic shadow-index activation/rollback and automated old-profile cleanup.
- Broad membership connectors, SSO/tenant administration, agent/tool execution.
- Managed infrastructure, license verification, a sales portal or automatic updater.

Not every advanced capability is mandatory for a small public FAQ site. Their
absence must be reflected in the product tier and claims. Enterprise contracts
need deployment-specific acceptance rather than a universal "enterprise" label.

## Packaging

From the repository on Windows:

```powershell
.\plugins\omnithread-ai\tools\package.ps1 -Destination 'C:\releases\omnithread-ai-1.4.0.zip'
```

Use a new destination outside the source tree. The tool copies only runtime
assets, documentation and plugin entry/uninstall files into a temporary staging
directory, checks the ZIP entry point, rejects developer-folder leakage, emits a
SHA-256 checksum and removes its staging files. It does not publish or upload.

Run the isolated tests described in [RAG operations](RAG-OPERATIONS.md) plus
`php tests/setup-integration.php`. Setup tests cover safe first install,
publication gating, FAQ-only onboarding, explicit rebuild failures, credential
preservation, test invalidation/expiry, support-report allowlisting and permissions.
They are not a substitute for browser, provider, compatibility and load testing.
