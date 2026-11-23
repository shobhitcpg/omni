# RAG operations (1.4.2)

The plugin implements incremental RAG, not autonomous agents. It remains
theme-independent; keyword-only mode needs no embedding service.
For first installation and the new explicit publication flow, start with
[Customer setup](CUSTOMER-SETUP.md).

## Models and setup

1. Configure your search service under **Search Engine**. An existing ElasticPress
   index can supply keywords; vectors need a separately configured plugin-owned
   Elasticsearch 8+ or OpenSearch 2.4+ index. Do not alter shared mappings.
2. Under **RAG Pipeline**, select keyword, semantic or hybrid retrieval.
3. Independently configure embeddings:

   | Provider | Starting model | Dimensions | Endpoint |
   | --- | --- | --- | --- |
   | OpenAI | `text-embedding-3-small` | 1536 | Default OpenAI base URL |
   | Azure OpenAI | Your deployed embedding model | Actual deployment output | Full HTTPS deployment embeddings URL, including `api-version` |
   | Ollama | `nomic-embed-text` | 768 | Reachable Ollama base URL; download the model yourself |

   These are example configurations, not a universal quality recommendation.
   Azure deployments using reduced dimensions must return exactly the configured
   count; this adapter does not request dimension reduction for Azure.
4. Supply a separate embedding key and approve non-loopback outbound processing.
   Overrides: `OTAI_EMBED_PROVIDER`, `OTAI_EMBED_MODEL`, `OTAI_EMBED_URL`,
   `OTAI_EMBED_KEY`, `OTAI_EMBED_DIMENSIONS`.
5. Save, test embeddings and vector backend, then queue **Rebuild knowledge base**.
   Embedding tests consume a text-input reservation. Inspect queue and last-error
   diagnostics. A successful connection test does not prove the corpus is ready.
6. Configure the answer model independently in **AI Providers**. A chat model is
   not an embedding model; mixing OpenAI embeddings with Ollama answers is valid.

Keyword is the safe default. Switching provider/model/dimensions selects another
vector index and raises a reindex notice. Queue a rebuild explicitly; this is not
an atomic shadow-index switch. Compatible caches do not include credentials in
their identity. Existing profile indexes/caches are retained for operator-managed
cleanup; do not assume they are automatically deleted.

Local-only never calls an answer model. It may use configured search services and
explicitly selected loopback Ollama embeddings; it blocks remote/cloud embeddings.
Self-hosted remote Ollama can be used in other routing modes with consent.
Secure all production endpoints, use least-privilege index credentials and keep
security-disabled examples restricted to loopback development.

## Synchronisation and scheduling

With auto-sync on, post, metadata and taxonomy events enqueue document updates.
Trash/delete/password/private changes remove local passages immediately and queue
remote cleanup. Retrieval verifies current visibility and hashes, so remote text
is never the authority. Recognised membership/LMS systems now deny post indexing
and retrieval by default without an explicit anonymous-public-content
`otai_index_post_allowed` connector. Custom/MU restrictions must register through
`otai_access_systems`. Recognition is not universal; see the access-policy
requirements in [Customer setup](CUSTOMER-SETUP.md). The shared index is not an
enrolment-aware private assistant.

Paragraph-aware splitting retains IDs and cached vectors for identical inputs.
A one-word body edit normally embeds one changed paragraph. Title edits can affect
many inputs; paragraph-boundary changes, long-paragraph splits, redaction changes
or a new model/profile can require additional embeddings.

The queue is a **plugin-owned durable MySQL queue**, rather than the Action
Scheduler dependency proposed in the architecture. This avoids requiring another
plugin or bundled runner. It uses document coalescing, generation fencing,
one-hour leases and checkpoints after eight new embedding inputs. Progress does
not count as a failed attempt. Jobs retry with backoff and stop after five failed
attempts; fix the cause, then **Retry failed jobs**. That button does not reset the
daily embedding budget.

WordPress cron is traffic-driven. For predictable ingestion, invoke a server
scheduler against the correct site, for example every minute:

```sh
wp omnithread-ai work --limit=5
```

The command processes at most 20 jobs and checks a 25-second wall-time boundary
between jobs. One document can exceed 25 seconds; HTTP operations are individually
bounded. Use CLI, not a web request, for large corpora. Rolling reconciliation
scans at most 100 post IDs per minute when the worker runs, skipping already
pending/running/failed jobs. Reconciliation is not an immediate full-site scan.
Use the site's real `--url` in multisite CLI invocations.

```sh
wp omnithread-ai status
wp omnithread-ai reindex
wp omnithread-ai work --limit=5
```

Reindex queues updates without emptying the live index. Pausing the queue does not
disable retrieval-time access checks. Network deactivation clears worker schedules
per site. Uninstall clears schedules and preserves data by default; the existing
**Delete data on uninstall** setting opts into removal of all eight local tables,
local plugin options/transients and widget metadata. Remote search indexes are
never deleted automatically. Persistent object-cache entries expire by their TTL.

## Failures, budgets and grounding

- An atomic UTC daily text-input limit covers ingestion and query embedding.
  This is not a token or currency budget; failed HTTP requests retain reservations.
- Embedding output must be complete, finite, nonzero and dimension-compatible.
  Credential-bearing calls do not follow redirects.
- Semantic-only returns an explicit error when unavailable; hybrid reports
  degradation and uses keyword results. Inspect diagnostics, not just an answer.
- Evidence is bounded in **characters**, not model tokens. Neighbour expansion
  is not full parent-child retrieval. Trusted integration hooks can append context
  after indexed evidence assembly; integrators must bound and protect that data.
- Numeric citations and Markdown source links are checked against supplied
  sources. This does not verify that every statement is supported. Similarity is
  not confidence, and retrieved neighbours may be irrelevant.
- With grounded-only enabled and no evidence, the router abstains without calling
  an answer LLM. Regex PII redaction is not guaranteed regulated-data removal.

## Validation and production release gates

[bootstrap.php](../tests/bootstrap.php) requires an isolated MySQL test instance,
database `otai_rag_test` and a WordPress core directory. It deliberately avoids the
live site's configuration. Do not run tests against a production database.

Start a dedicated **disposable** loopback MySQL instance on port 19306 with a
test-only root account and no password. Never point it at the site's data
directory or expose the port publicly. It creates only `otai_rag_test` and
`otai_rag_network_test`. The grant suite temporarily creates a SELECT-only account
on this disposable server and removes it afterward.

For individual suites, set `OTAI_TEST_WP_CORE` and
`OTAI_TEST_DB_HOST=127.0.0.1:19306`, then run:

```sh
php tests/rag-integration.php
php tests/rag-lifecycle.php
php tests/budget-integration.php
php tests/setup-integration.php
php tests/release-integration.php
php tests/privacy-integration.php
php tests/grants-integration.php
```

For real multisite, prepare the separate network database once using
`OTAI_TEST_NETWORK=prepare` with `php tests/network-prepare.php`; then set
`OTAI_TEST_NETWORK=run` for `php tests/network-integration.php`. Clear that
environment variable before single-site suites. Do not prepare an existing
network again.

On Windows, the reproducible runner checks every PHP file, both JavaScript files,
all single-site suites, an actual mysqldump/mutate/restore/verify drill, and
optionally the prepared multisite fixture:

```powershell
.\plugins\omnithread-ai\tools\validate.ps1 `
    -WordPressCore 'C:\test\wordpress' `
    -PHPBinary 'C:\test\php\php.exe' `
    -MySQLBin 'C:\test\mysql\bin' `
    -NodeBinary 'C:\Program Files\nodejs\node.exe' `
    -IncludeNetwork
```

PHP needs mysqli, mbstring, OpenSSL and curl in its sibling `ext` directory.
The runner requires exit zero **and** a RESULT marker: a WordPress fatal HTML
page with exit zero is a failure, not a test pass. Stop the browser fixture before
dump/restore. Only synthetic fixture data is dumped; successful drills remove
their temporary backup. Interrupted drills retain it with a warning for recovery.

The suite uses real WordPress/MySQL with mocked embedding/search HTTP contracts.
It verifies incremental reuse, both adapter dialects, leases/generation fencing,
failure retries, budgets, revocation, context limits and settings rendering. It
does not evaluate real model quality.

For an actual disposable backend, set `OTAI_TEST_BACKEND_URL` to
`http://127.0.0.1:19442` (Elasticsearch) or `http://127.0.0.1:19443` (OpenSearch):

```sh
php tests/rag-backend.php
```

Only those dedicated loopback ports are permitted by the harness. It exercises
real vector writes, ANN queries, incremental updates and deletion with synthetic
embeddings, removing only its randomly named indexes afterward.

Verified for 1.4.2 on Windows, WordPress **6.9.4**, PHP **8.2.29**, MySQL **8.4.0**:

| Validation | Result |
| --- | --- |
| Atomic request limits, actual concurrent workers, routing and failure handling | 22 checks passed |
| Customer onboarding, publication, support/permissions and generation HTTP contracts | 34 checks passed |
| WordPress/MySQL integration and mocked provider/backend contracts | 49 checks passed |
| Schema, scheduling and opt-in uninstall lifecycle | 7 checks passed |
| Concurrent queue/chat, lexical evaluation and access revocation | 12 checks passed |
| Privacy pagination, isolation, erasure, retention and storage outage | 10 checks passed |
| Actual denied CREATE/ALTER/write grants and schema retry recovery | 6 checks passed |
| SQL backup, deliberate mutation, restore and state verification | 1 check passed |
| Actual subdirectory multisite activation, provisioning, isolation and uninstall | 15 checks passed |
| Database-suite total | 156 checks passed |
| Twenty Twenty-One widget browser assertions | 17 passed |
| Actual HTTP FAQ/source/no-store and unpublished 403 checks | 2 passed |
| Historical real Elasticsearch 8.19.4 indexing/retrieval/update/deletion (1.3.0) | 9 checks passed |
| Historical real OpenSearch 2.19.0 indexing/retrieval/update/deletion (1.3.0) | 9 checks passed |

Latest synthetic workload: six queue processes completed 61 jobs/180 passages in
2.197 seconds wall time. Six offline-chat processes completed 60 turns in 2.062
seconds including process startup; per-turn p50 was 12.17 ms, p95 56.78 ms and
max 58.14 ms. Twenty exact-topic lexical questions selected the intended first
source (20/20); an unknown question abstained. These are small local service-layer
measurements, not production SLOs, sustained HTTP load or generative-model quality.

The browser fixture renders the standard Twenty Twenty-One theme and uses the
real plugin assets and REST handler against synthetic data. Checked: inline
shortcode, absent shortcode/no script, mobile floating chat, retained conversation,
unpublished suppression, real FAQ response and hostile outer CSS isolation.
It is a smoke test, not a cross-browser, accessibility or all-theme audit.
To reproduce manually: run `php tests/browser-fixture.php seed`, set
`OTAI_TEST_BROWSER=1`, and start PHP's development server bound **only** to
`127.0.0.1:19307`, with `tests/browser-router.php` as router. Open `/`,
`/?view=none`, `/?view=floating`, and `/?view=unpublished`; test a 390px viewport
and the synthetic support-hours question. Stop the server, clear its synthetic
browser chat storage, and run `php tests/browser-fixture.php clean`. The router
requires the dedicated database/port, blocks outbound WordPress HTTP, and never
uses the live site's configuration. Test/router files are excluded from ZIPs.

The real-backend suites were run for the 1.3.0 RAG implementation; later
onboarding/rebuild/provider-contract/request-limit changes were checked with the isolated
WordPress/MySQL suites, not a second real-backend run.
Both real-backend suites used deterministic synthetic embeddings. No live
OpenAI/Azure/Ollama embedding or generation model was tested, and the declared
minimum backend versions were not all exercised.

Before an enterprise production rollout, validate the actual embedding and answer
models, semantic relevance/abstention, sustained deployment load, host backups, outage recovery,
membership ACLs and the site's freshness/privacy requirements. Reranking, query
rewriting, tokenizer-based total prompt budgeting, full parent-child hierarchy,
atomic index migrations and agents remain future work, not implemented settings.
