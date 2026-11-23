# RAG operations (1.3.0 core, 1.4.0 customer setup)

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
is never the authority. Arbitrary membership systems require an explicit
`otai_index_post_allowed` connector; do not deploy this as an enrolment-aware
assistant without one.

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
**Delete data on uninstall** setting opts into removal of all seven local tables,
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

Set `OTAI_TEST_WP_CORE` and `OTAI_TEST_DB_HOST=127.0.0.1:19306`, then run:

```sh
php tests/rag-integration.php
php tests/rag-lifecycle.php
```

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

Verified for this build:

| Validation | Result |
| --- | --- |
| Customer onboarding, publication, support/permissions and generation HTTP contracts (1.4.0) | 34 checks passed |
| WordPress/MySQL integration and mocked provider/backend contracts | 49 checks passed |
| Schema, scheduling and opt-in uninstall lifecycle | 7 checks passed |
| Real Elasticsearch 8.19.4 indexing/retrieval/update/deletion | 9 checks passed |
| Real OpenSearch 2.19.0 indexing/retrieval/update/deletion | 9 checks passed |

The real-backend suites were run for the 1.3.0 RAG implementation; the 1.4.0
onboarding/rebuild/provider-contract changes were checked with the isolated
WordPress/MySQL suites, not a second real-backend run.
Both real-backend suites used deterministic synthetic embeddings. No live
OpenAI/Azure/Ollama embedding or generation model was tested, and the declared
minimum backend versions were not all exercised.

Before an enterprise production rollout, validate the actual embedding and answer
models, semantic relevance/abstention, multi-worker load, backups, outage recovery,
membership ACLs and the site's freshness/privacy requirements. Reranking, query
rewriting, tokenizer-based total prompt budgeting, full parent-child hierarchy,
atomic index migrations and agents remain future work, not implemented settings.
