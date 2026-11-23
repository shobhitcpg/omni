# Production RAG pipeline: research and implementation specification

Research date: 2026-11-10.

Status: version 1.3.0 implements the RAG core described below; remaining sections
are the target architecture, not a claim that every feature exists or a
production-readiness certification.
This document covers retrieval-augmented answers, not autonomous agents or tools.
See [RAG operations](RAG-OPERATIONS.md) for the implemented setup and limitations.

## 1. Product and deployment decisions

The plugin must work without a theme, WooCommerce, ElasticPress, a Python process,
or a mandatory cloud account. Advanced semantic retrieval requires a compatible
vector backend and an embedding model; an ordinary WordPress host cannot be
promised enterprise-scale vector search merely by installing a PHP plugin.

Provide three explicit operational profiles:

| Profile | Retrieval | Answers | External inference |
| --- | --- | --- | --- |
| Local search only | Database and approved existing keyword search; optional explicitly configured local vectors | FAQ/extractive answers and source links | None |
| Self-hosted AI | Keyword plus local semantic retrieval when configured | Approved self-hosted model | Approved private endpoints only |
| Connected RAG | Keyword, semantic or hybrid | Independently selected generation provider | Only destinations and purposes the owner approves |

"Local" is a deployment/policy attribute, not a provider name. An Ollama endpoint
can be remote, and an Elasticsearch endpoint can be a paid hosted service.
No inference API fee does not mean zero hosting, memory or operational cost.

First-release vector-backend selection: Elasticsearch and OpenSearch, as confirmed
by the owner. Other adapters remain future work:
Qdrant, pgvector and managed services. Reuse an existing post search index for
keyword retrieval, but do not modify an ElasticPress-managed mapping. Use a
plugin-owned vector index unless its operator explicitly supplies an integration.

Probe distribution, version, vector/filter support, permissions and relevant
feature entitlements. Do not equate "ElasticPress detected" with "vectors ready".
Elasticsearch and OpenSearch have different vector mappings and query dialects.
Do not silently enable paid server-side inference or licensed search features.
Use plugin-side rank fusion when native fusion is unavailable.

## 2. Current implementation versus required work

Implemented in version 1.3.0; the "remaining" column is not shipped functionality:

| Area | Present | Remaining release/enterprise work |
| --- | --- | --- |
| Retrieval | Lexical search, separate profile-based ES/OS vector indexes, plugin-side RRF and bounded neighbour evidence | Reranking, calibrated relevance/abstention, full parent-child hierarchy, atomic index activation/rollback |
| Content updates | Save/meta/term/revocation hooks, durable coalesced queue, eight-input checkpoints, five retries and rolling reconciliation | Sustained load and multi-worker stress tests, operational freshness SLOs |
| Passage identity | Retained IDs for unchanged text, SHA-256 passage hashes and source-modified checks | Strong document-generation migrations and old-profile garbage collection |
| Models | Separate OpenAI/Azure/Ollama embedding contracts; independent generation adapters | Live configured-model validation, rewrite/rerank services, broader embedding providers |
| Prompts | Industry prompt, untrusted evidence, character bounds, structural citation checks, no-evidence abstention | Tokenizer-based total prompt accounting and factual citation/answer evaluations |
| Privacy | Pre-embedding/context PII redaction, outbound embedding consent, local-only inference policy | Deployment-specific egress and regulated-data review; regex redaction is not complete DLP |
| Observability | Usage/errors, retrieval traces, queue health and atomic input budgets | Load/quality evaluation, stage-level metrics and alert integration |
| Access | Query-time public/password/type/filter checks and immediate local revocation | Explicit membership/enrolment ACL connectors; no blanket compatibility claim |

Important limitations to address:

- Save/meta/taxonomy hooks and rolling reconciliation cannot infer arbitrary
  membership permissions. Deploy the explicit access filter for such sites.
- A configured search host is not proof of health, a complete index or permissions.
- Database and external indexes can diverge; stale external text must not become
  the authority for a deleted, private or newly restricted document.
- Generation cache keys include exact-normalised questions, conversation,
  settings, site/locale and evidence. Continue evaluating invalidation for
  deployment-specific live integrations.
- Current scoring is not a calibrated probability. Neither lexical scores,
  vector similarity nor a model's self-reported confidence is such a probability.

## 3. Guided setup and industry-specific presets

The wizard gathers business purpose, audience/access policy, content languages,
approved outbound destinations, corpus size and budget. Industry alone is not
enough to choose retrieval or privacy settings.

Detect likely industries from registered post types and known integrations.
Show the evidence, allow multiple site purposes and require owner confirmation.
Do not upload site content to classify it by default. Never automatically grant
access to protected content, increase a budget or enable a cloud API.

Recommendations are versioned, reversible presets. Applying a preset shows a diff
and preserves explicit overrides. Content preview and cost/egress approval precede
the first embedding job.

| Site/use case | Suggested retrieval emphasis | Required policy considerations |
| --- | --- | --- |
| Commerce | Hybrid, exact SKU/category filters, current product data | Fetch price/stock live; private orders stay outside shared indexes |
| Education | Course/lesson structure and parent sections | Respect enrolment rules; avoid leaking assessed/private lesson material |
| Healthcare | Approved, dated service/policy sources | Informational scope, escalation; no diagnosis/prescribing or patient records by default |
| Finance / fintech | Exact products, jurisdiction, effective dates | Approved material; no personalised financial advice or account data in a public corpus |
| Legal / government | Jurisdiction, dates, document authority | Strong attribution; no personalised legal determinations |
| Documentation / SaaS | Hybrid exact identifiers plus section parents | Version/language filters; keep code identifiers and error strings intact |
| News / publishing | Date/entity filters, freshness-aware ranking | Distinguish current and archival reporting |
| HR / intranet | Collections and access-aware hybrid retrieval | Default deny without a verified ACL connector; no public answer cache |
| General business | Pages, FAQs and contact information | Public content by default; concise source-backed answers |

These are starting hypotheses, not clinical/legal compliance guarantees or
universally optimal relevance settings. The existing 18 verticals should map to
these retrieval/policy building blocks rather than duplicate pipeline logic.

## 4. Settings to expose

Use Basic and Advanced views. Basic setup should not require tuning dozens of
technical values. All numeric defaults below are initial experiment settings,
subject to provider limits and evaluation on the client's content.

### Basic settings

| Group | Controls |
| --- | --- |
| Profile | Local search only / self-hosted / connected RAG; approved endpoints and processing region |
| Site purpose | Confirmed industry/use cases, languages, audience and risk profile |
| Sources | Post types, include/exclude collections and IDs; explicit allowlist of custom fields |
| Retrieval | Keyword / semantic / hybrid; backend connection and capability test |
| Embeddings | Provider, model/deployment, dimensions when supported, credentials and data-egress approval |
| Answers | Extractive-first / grounded generation; primary chat model and approved failover list |
| Synchronisation | Automatic updates, reconciliation schedule, pause/resume, queue status and pending sync |
| Safety | Grounded-only answers, citations, no-evidence behaviour, privacy/redaction and human handoff |
| Limits | Ingestion/query budgets, request limits, maximum answer length and response deadline |

### Advanced settings

| Stage | Controls and proposed starting values |
| --- | --- |
| Extraction | Main-content fields, supported block/builder adapters, table/list preservation, boilerplate removal, document-size limits |
| Parsing | WordPress content first; approved PDF/document connectors later, with MIME/size limits, isolated parsing and malware handling |
| Chunking | Structure-aware child passages around 350 tokens; target overlap 40 tokens only where needed; bounded parent sections around 1,200 tokens |
| Embedding | Batch limits, provider token limit, request timeout, rate limit, retry policy, version/dimensions, query/document task mode |
| Candidates | Keyword top 40 and vector top 40 per query; independent backend candidate/exploration limits |
| Fusion | Reciprocal rank fusion, initial constant 60; optional evaluated source weights; deduplicate by canonical passage |
| Filters | Collection, language, content type, document version, effective date and authorised audience |
| Reranking | Off by default; optional provider/model, top 20 candidates, return up to 6, independent egress and spend limits |
| Parent retrieval | Off for short items; expand selected child hits to authorised parent sections, bounded by total context budget |
| Diversity | At most 2 selected child hits per document initially; deduplicate overlapping parent sections |
| Query processing | Original query retained; optional follow-up rewrite, maximum 2 extra query variants, optional glossary/synonym expansion |
| Context | Initial evidence budget 4,000 tokens; include title, heading, canonical URL, source ID and effective date |
| Generation | Model-capability-aware sampling/output settings; output cap initially 600 tokens; timeout and explicit failover policy |
| Prompt | Versioned site instructions, answer style/language, approved glossary, clarification policy, preview/test/rollback |
| Output checks | Citation ID/URL validation, evidence coverage checks, moderation/PII policy and safe rendering |
| Operations | Worker concurrency, lease duration, retry ceiling, reconciliation batch size, latency/degradation policy and retention |

Validate dependent settings: candidate windows must cover final selection;
overlap must be smaller than passage size; context plus instructions, history,
question and reserved output must fit the selected model's context window.
Use a suitable tokenizer or a conservative documented bound, never a fixed
English characters-per-token estimate as a universal hard limit.

Avoid one global "top_k": distinguish retrieval count, ANN exploration, reranker
selection and generation sampling. Hide or disable unsupported model parameters.
Do not use the current fixed keyword thresholds as semantic/hybrid thresholds.

## 5. Independent models and capability registry

Use separate adapters/configurations for:

1. Embeddings: batch document vectors and interactive query vectors.
2. Generation: answer model and approved failovers.
3. Optional query rewriting: a separately budgeted model or the answer model.
4. Optional reranking: a cross-encoder/rerank API, not an ordinary embedding model.
5. Optional policy evaluation: local checks and approved moderation/grounding services.

Every adapter declares supported operations, model/deployment ID, input/output
limits, supported languages/task modes, dimensions, timeout/rate limits, region,
credential reference and approved destinations. Verify responses: expected batch
count, finite numeric values, vector dimensions and valid provider result shapes.

Candidate adapter families: Ollama/local compatible embedding endpoints, OpenAI,
Azure deployments, Gemini and Bedrock embeddings. Existing generation adapters
do not establish embedding support. Do not advertise an adapter as supported
until its integration tests and provider-specific contract are complete.

Verified example: Bedrock Titan Text Embeddings V2 supports 1,024/512/256 output
dimensions and does not use generation sampling parameters [6]. Its multilingual
and cross-language limitations require dataset testing. Provider model IDs are
configurable; do not make current marketing names permanent architecture defaults.

Direct OpenAI and Gemini documentation access was blocked by this environment's
network policy during research. Their current model catalogues, dimensions,
prices and API limits are not verified here. Do not bypass that restriction or
claim live provider validation. Obtain approved documentation/access before
finalising those adapters.

Document and query embeddings must use the same compatible model space, revision,
dimensions and task conventions. Never fail over a query embedding to an unrelated
provider/model against the existing index. Fallback to keyword retrieval or a
separately built compatible index, and disclose degradation.

Embedding/profile changes create a shadow index and a resumable migration. Switch
the active manifest only after consistency checks; retain rollback according to
policy. Changing the chat model alone does not require re-embedding.

## 6. Ingestion and incremental synchronisation

```text
WordPress content/access event
  -> durable job / coalescing by site + document
  -> extract current authorised source and revision
  -> normalise with a versioned extraction policy
  -> split into stable sections/children
  -> diff hashes against the current document manifest
  -> reuse unchanged compatible embeddings
  -> embed only new/changed embedding inputs
  -> upsert keyword/vector entries with revision metadata
  -> verify writes and publish the new active manifest
  -> invalidate affected caches and remove obsolete entries
```

Use `wp_after_insert_post` where appropriate because it fires after terms/meta
are saved [1], plus narrowly scoped metadata/taxonomy and integration events.
Ignore revisions/autosaves and debounce repeated edits. Workers read current state
rather than trusting stale event payloads. Bulk imports need reconciliation.

Recommended queue: bundle a compatible Action Scheduler library, independent of
WooCommerce, behind a queue interface [3]. Record idempotency keys, attempts,
leases, backoff/jitter and actionable terminal failures. Run bounded batches;
support WP-CLI/system cron for large sites. WP-Cron alone has no punctuality
guarantee on quiet sites [2]. Saving a post must not wait for embedding calls.

Hash the exact embedding input plus model/profile, extraction/chunker version and
any title/heading context included in that input. Match unchanged text by hash,
not only by passage ordinal. A small edit can affect neighbours/overlap; reuse
unaffected passages without promising exactly one changed vector every time.

Metadata-only URL changes update metadata without re-embedding unless the URL is
part of the embedding input. Source deletion, new password protection, unpublish
or access revocation immediately disables serving that document in the local
authority, even if remote deletion is queued or the backend is offline.

Workers must reject superseded revisions and avoid publishing stale jobs.
Cross-store writes are not a distributed transaction: use revision manifests,
query-time validation, durable write outcomes and idempotent retry to reconcile.
A rebuild creates a new generation rather than deleting the live index first.
Deletion tombstones prevent old retries from resurrecting removed content.

## 7. Retrieval and answer pipeline

```text
Validate question, identity, audience, rate and budget
  -> apply access/egress policy before external processing
  -> exact FAQ or extractive path when the selected answer mode permits
  -> bounded follow-up rewrite (optional, original query preserved)
  -> permitted collection/version/language filters
  -> keyword candidate search + compatible query embedding / vector search
  -> reciprocal rank fusion of eligible candidate lists
  -> optional rerank of authorised text
  -> bounded parent-section expansion and overlap deduplication
  -> final live access/revision validation
  -> token-budgeted evidence with stable citation IDs
  -> versioned prompt + allowed generation provider
  -> output/citation checks, abstention/handoff as needed
  -> trace usage, return answer and verified source links
```

Hybrid means two retrieval signals, not keyword search followed by a paid LLM.
Combine ranks rather than adding unrelated BM25, cosine and reranker scores [4,5].
Large corpora need filtered ANN search, bounded candidate sets and load tests,
not loading all vectors into PHP and computing every cosine similarity.
OpenSearch filtered k-NN support is engine/version dependent [7].

Parent-child retrieval finds precise child passages and supplies larger relevant
sections to the answer model [8]. It is useful for long policy/manual/course
documents, not a universal improvement. Parents must satisfy the same ACL,
version and token constraints. Avoid automatically including entire long posts.

Optional query variants/HyDE, semantic chunking and contextual enrichment are
experimental controls, off by default. They add cost/latency and can introduce
false assumptions. Enable only if the client's evaluation demonstrates a gain.
Structured identifiers, negation, dates and language must survive query handling.

No evidence: ask a useful clarification, return verified source links, or hand
off. Grounded mode must not silently switch to unrestricted model knowledge.
Keyword fallback must be visible in admin traces and configurable for strict
clients. Policy/authentication failures fail closed rather than relax access.

## 8. Prompt layers, privacy and guardrails

Prompt layers, in order: fixed safety contract; deployment/client policy; confirmed
industry rules; approved owner instructions; trusted output schema; labelled
untrusted evidence; bounded history; current user question. Persist prompt version
and provide preview, evaluation and rollback. Owner style instructions cannot
disable server-side access or egress checks.

Require evidence for factual site claims, preserve qualifiers/effective dates,
ask when ambiguous and abstain when unsupported. Citations use server-issued
source IDs; map them to authorised URLs server-side. Valid citation syntax does
not prove factual support: add evidence-grounding evaluation separately.

Apply privacy rules to source text before cloud embedding, to visitor questions
before cloud semantic search/rewrite, to reranker inputs, context/history before
generation, and outputs/logs. Explain the retrieval trade-offs of redaction.
Keep sensitive personal lookups separate from shared embeddings/caches.

Treat retrieved instructions and user-supplied role markers as data. Prompt
delimiters, regex detection or low temperature alone do not eliminate injection;
use trust separation, restricted capabilities, input/output validation and
adversarial tests [9]. Optional provider guardrails have independent permissions,
pricing, language/region support and limitations [10].

Public content by default. Protected content requires an explicit connector that
can express query-time eligibility; fail closed if it cannot. Enforce site/tenant,
ACL and source-status constraints before reranking, expansion or model calls,
and recheck against current WordPress state before serving. Parent expansion,
citation titles/URLs, traces and caches must not leak otherwise hidden content.

Validate outbound endpoint schemes/hosts and prevent credential-bearing redirects.
Private-network endpoints require explicit approval; do not allow arbitrary user
URLs to turn parsers, search connections or future tools into network proxies.
TLS verification on, least-privilege server credentials and controlled log access.

Do not show nonfunctional "agent settings" now. Reserve a typed, read-only
retrieval interface returning authorised evidence, citations and a trace ID.
Future tools will need separate scopes, approvals, execution budgets and audits;
RAG configuration must not grant write or tool-execution permission.

## 9. Persistence, caching and operations

Logical storage responsibilities (final schema needs migration design):

- Documents: site/source key, current revision, language, status, ACL policy
  reference, canonical URL and active-index generation.
- Sections/passages: stable ID, parent ID, order, content hash, extracted text,
  embedding-input hash, revision and extraction/chunker versions.
- Embedding manifests: provider/model revision, dimensions, task/policy profile,
  cache reference, state, usage and backend revision. Avoid unnecessary duplicate
  vectors in WordPress when the external store is authoritative for vectors.
- Jobs: durable identity, operation, revision, retry/lease state and safe error.
- Index generations/configuration: capability manifest, migration state, active
  pointer, versioned prompts and connection health.
- Traces/evaluations: stage timings, retrieval ranks, citations, costs/usage,
  degradation reasons and release comparison; redact and retain by policy.

Use per-site isolation by default. Share cached embeddings only within an
explicitly equivalent tenant, ACL and processing-policy boundary. Credentials
are secret references, never part of browser-visible traces.

Cache fingerprints include exact-normalised query (preserve negation),
embedding/retrieval/prompt/generation profiles, source revisions, audience/ACL
policy version, language and live-data constraints. Disable shared personal
answer caches. Invalidate on content, access, prompt and model changes.

Budgets distinguish ingestion embeddings, query embeddings, rewrites, reranking,
generation and safety APIs. Reserve limits atomically under concurrent load;
report unknown costs honestly, using token/request usage and owner-configured
prices where needed. A "daily cap" that races across workers is not a hard cap.
Retries must respect remaining budgets and the end-to-end deadline.

Operator controls: source preview, pending/failed jobs, retry/pause, full versus
incremental sync, corpus/vector coverage, oldest pending job, last reconciliation,
backend health, degraded mode, provider usage, prompt rollback and exportable
redacted diagnostics. Do not expose secrets or raw restricted passages by default.

## 10. Verification and production release gates

Do not label the plugin enterprise-ready merely because the pipeline executes.
Document tested WordPress/PHP/database/backend versions, corpus sizes, concurrency
and provider adapters. Use unit tests, WordPress integration tests, API contract
fixtures, real backend tests and owner-approved live provider smoke tests.

| Gate | Required evidence |
| --- | --- |
| Incremental cost | Identical-text save makes zero embedding calls; changed-input tests embed only affected hashes; metadata-only edit makes zero calls |
| Concurrency | Duplicate/reordered jobs converge; lease expiry retries safely; old revisions cannot replace newer ones or undo deletion |
| Access | Zero unauthorised passages, titles or URLs in model calls, responses, caches and permitted diagnostics across the test corpus |
| Index lifecycle | Partial writes/outages remain recoverable; no missing-current-content claim of success; shadow rebuild/rollback preserves service |
| Model compatibility | Wrong dimensions, empty/nonfinite vectors, partial batches and incompatible embedding failover rejected explicitly |
| Retrieval quality | Labelled client questions with hard negatives; compare keyword, vector, hybrid, reranking and parent-child using Recall@k, MRR and nDCG |
| Answers | Correctness, grounded claims, citation support, abstention, conflicting/expired sources, multilingual and no-answer cases |
| Policy | Local-only profile makes zero unapproved outbound inference calls, including rewriting/embeddings/moderation |
| Resilience | 429/5xx/timeouts, quota exhaustion, backend restart, failed jobs, cron disabled and token overflow produce actionable outcomes |
| Performance | Measure p50/p95 by stage, index freshness and editor-save overhead on named datasets/hardware; enforce agreed client SLOs |
| Upgrade/portability | Existing settings preserved; resumable migration; single/multisite and clean WordPress without unrelated plugins tested |

Suggested provisional quality experiment: at least 100 labelled questions per
initial client profile; target Recall@10 >= 0.90 and >= 0.95 valid citation IDs
on answerable examples, with separate factual-support annotation. These are
proposed acceptance targets, not achieved scores or universal service guarantees.
ACL/local-only tests are hard zero-violation gates, not statistical tolerances.

## 11. Implementation sequence

1. Confirm first vector backend(s), initial embedding adapters and infrastructure
   constraints; define deployment policies and backwards-compatible settings.
2. Add versioned schema, stable passage manifests, hashing, durable jobs and
   tombstones; migrate existing content without synchronous API work.
3. Add tested embedding adapters, quotas and shadow-index ingestion.
4. Add backend-specific vector queries, access filters, hybrid rank fusion,
   optional reranking and parent-section context assembly.
5. Add prompt profiles, token accounting, citation checks, privacy/egress gates
   and safe explicit fallback for each stage.
6. Deliver guided settings, diagnostics, evaluations, compatibility/load tests,
   operational documentation and only then promote a tested production release.

Each milestone must include working settings, persistence, error surfaces and
tests; no placeholder switches advertised as completed features. Agentic work
starts only after this RAG release meets its acceptance gates.

## 12. Primary research sources

1. [WordPress: wp_after_insert_post](https://developer.wordpress.org/reference/hooks/wp_after_insert_post/)
   - fires after post, terms and metadata are saved.
2. [WordPress: Cron](https://developer.wordpress.org/plugins/cron/)
   - page-load scheduling and limitations on punctual execution.
3. [Action Scheduler](https://actionscheduler.org/)
   - distributable WordPress background queue, claims, batches and tracing.
4. [Elasticsearch: reciprocal rank fusion](https://www.elastic.co/docs/reference/elasticsearch/rest-apis/reciprocal-rank-fusion)
   - combines ranked result sets; confirm deployment feature availability separately.
5. [OpenSearch: hybrid search](https://docs.opensearch.org/latest/vector-search/ai-search/hybrid-search/index/)
   - keyword/semantic combination and search-pipeline behaviour.
6. [Bedrock: Titan embedding models](https://docs.aws.amazon.com/bedrock/latest/userguide/titan-embedding-models.html)
   - dimensions, task limits, generation-parameter differences and language caveats.
7. [OpenSearch: filtered vector search](https://docs.opensearch.org/latest/vector-search/filter-search-knn/index/)
   - filtering choices, engine/version support and post-filter recall limitations.
8. [Bedrock: chunking](https://docs.aws.amazon.com/bedrock/latest/userguide/kb-chunking.html)
   - structure-aware, hierarchical and semantic chunking trade-offs.
9. [OWASP: prompt injection prevention](https://cheatsheetseries.owasp.org/cheatsheets/LLM_Prompt_Injection_Prevention_Cheat_Sheet.html)
   - layered controls and injection threat model, not prompt-only protection.
10. [Bedrock: guardrails](https://docs.aws.amazon.com/bedrock/latest/userguide/guardrails.html)
    - configurable safety, sensitive-information and contextual-grounding checks.
11. [Azure AI Search: hybrid ranking](https://learn.microsoft.com/en-us/azure/search/hybrid-search-ranking)
    - rank-fusion scoring versus separate reranking.

The numerical defaults, module boundaries and release gates are design
recommendations derived from these sources and the current plugin, not vendor
performance claims. Provider catalogues, licensing and actual infrastructure
permissions must be verified for the target installation.
