=== OmniThread AI – Smart Site Assistant ===
Contributors: omnithreadai
Tags: ai, chatbot, openai, gemini, ollama
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make any WordPress site AI-enabled: industry-ready chat assistant with OpenAI, Azure OpenAI, Gemini, Claude, Amazon Bedrock, Ollama and more – plus a cost-saving Smart Query Mode.

== Description ==

OmniThread AI adds a grounded AI assistant to any WordPress site – independent of your theme and other plugins.

**Industry presets** – 18 ready-made assistants (E-commerce, Education, Healthcare, Finance/Fintech, Real Estate, Travel, Restaurants, Media, SaaS, Legal, Non-profit, Government, Events, Jobs, Fitness, Automotive, Agency, General) with tailored prompts, guardrails, conversation starters and recommended temperature / top_p / top_k values. The plugin auto-detects your industry from installed plugins and content types.

**Bring your own model** – OpenAI, Azure OpenAI, Google Gemini, Anthropic Claude, Amazon Bedrock (SigV4, no SDK), self-hosted Ollama, Mistral, Groq, DeepSeek, OpenRouter, any OpenAI-compatible server (vLLM, LM Studio, LocalAI…), or the OmniThread AI backend.

**Smart Query Mode** – answers from your FAQ and site content first (free), then a response cache, then self-hosted models (Ollama), then paid providers in the order you choose (e.g. OpenAI → Gemini → Azure → Bedrock) with automatic failover and daily caps. Or use *Single provider* or *Free local only* (configured search + database, no answer-model calls; optional loopback Ollama embeddings when explicitly configured).

**Knowledge base** – indexes posts, pages, products, courses, events… and keeps it in sync automatically. Search engine, picked automatically: ElasticPress / WP VIP Enterprise Search if the site already has it → your own Elasticsearch or OpenSearch server → MySQL FULLTEXT or LIKE (the automatic fallback if a search server is down). WooCommerce: live price/stock and logged-in customers' own order status.

**RAG pipeline** – independently select OpenAI, Azure OpenAI or Ollama embeddings. Combine keyword and semantic retrieval using plugin-side reciprocal rank fusion against a plugin-owned Elasticsearch 8+ or OpenSearch 2.4+ vector index. Stable paragraph hashes reuse unchanged embeddings. Durable background jobs coalesce updates, checkpoint large documents and retry failures. Retrieval verifies current WordPress visibility and passage hashes before assembling bounded neighbour context.

**Security and operations controls** – encrypted API keys (or `wp-config.php` constants), PII redaction, rate limiting, same-origin checks, retention policies, GDPR exporter/eraser, usage analytics, conversation review with 👍/👎 feedback, multisite, WP-CLI and many filters. These controls are not a compliance or production-readiness certification; deployment-specific access connectors and load/answer-quality evaluation are required.

**Customer setup** – standalone BYOK with no mandatory seller account. New installations remain unpublished until explicit launch, use shortcode-only/local-only defaults and do not store transcripts by default. Setup & Readiness guides industry, provider and display choices, checks prerequisites and indexing, records explicit service tests, integrates WordPress Site Health and downloads a secret-free support report. Existing installations retain their publication/settings choices.

**Restricted-content safety** – recognised active membership/LMS systems block post indexing and post-based answers unless an explicit anonymous-public-content connector is registered. Curated FAQs/custom knowledge remain available. Recognition is not universal: custom/MU restrictions must register through `otai_access_systems`. This is a shared public index, not a per-member assistant.

== Installation ==

1. Upload the `omnithread-ai` folder to `/wp-content/plugins/` and activate.
2. Go to **OmniThread AI → Setup & Readiness**: choose local site search or BYOK, your industry and display. New installs are unpublished until you explicitly launch.
3. For BYOK, **AI Providers**: enable/configure your chosen primary provider and test it. Local search needs no AI service. Review public-source rules, external processing and privacy/retention.
4. **Knowledge Base**: click *Rebuild knowledge base* to queue indexing. WordPress cron processes jobs; **RAG Pipeline → Process pending jobs** or WP-CLI can run them immediately. Add FAQ entries for instant, free answers.
5. Try real questions in the administrator Dashboard *Playground* while unpublished, resolve blocked setup checks, then click **Publish assistant**. Add the shortcode to a page if using shortcode-only display.

See `docs/CUSTOMER-SETUP.md` for the full customer guide, manual ZIP updates and support. This edition does not include managed AI infrastructure, model credits, license-key activation or an automatic updater. Use maintained PHP (8.3+ recommended); mbstring and OpenSSL are required on activation.

= Self-hosting with Ollama =

    curl -fsSL https://ollama.com/install.sh | sh
    ollama pull llama3.1:8b

Set the server URL (default `http://localhost:11434`) – it must be reachable from the web server.

= Free Elasticsearch / OpenSearch (optional) =

Sites already running ElasticPress or WP VIP Enterprise Search are detected and used automatically. Otherwise, run a compatible server, for example (loopback development only):

    docker run -d -p 127.0.0.1:9200:9200 -e discovery.type=single-node -e xpack.security.enabled=false docker.elastic.co/elasticsearch/elasticsearch:8.19.4

Enter `http://localhost:9200` under **Settings → Search Engine**, click *Test connection*, then rebuild the knowledge base. Keep security enabled in production (username/password or API key; or `OTAI_ES_URL`, `OTAI_ES_INDEX`, `OTAI_ES_USERNAME`, `OTAI_ES_PASSWORD`, `OTAI_ES_API_KEY` in wp-config.php).
OpenSearch is Apache-2.0 licensed. Check the license and enabled features of your chosen Elasticsearch distribution; plugin-side rank fusion does not require native licensed RRF.

= Semantic / hybrid RAG setup =

1. Configure and test your own search backend. Existing ElasticPress/VIP search can supply keywords, but does not automatically provision vectors.
2. **RAG Pipeline**: choose Hybrid or Semantic, then select an embedding provider, model, endpoint and matching dimensions.
3. OpenAI starting configuration: `text-embedding-3-small`, 1536 dimensions. Configure a separate embedding key and approve outbound processing. Azure uses your full deployment embeddings URL, including api-version, and the deployment's actual dimensions.
4. Self-hosted alternative: `ollama pull nomic-embed-text`; select Ollama, `nomic-embed-text`, 768 dimensions and the URL reachable from WordPress. Remote endpoints require explicit outbound consent. Local-only mode permits only loopback Ollama embeddings.
5. Choose the answer model independently under **AI Providers**. No answer LLM is necessary for keyword/extractive mode.
6. Save settings, test the embedding/backend, then queue a rebuild and inspect job/error diagnostics.

Save, metadata, taxonomy, trash and delete hooks maintain the index when auto-sync is enabled. A small edit normally embeds only changed passages; changes to title, paragraph boundaries or model/profile can affect more inputs. Manual rebuild queues updates without emptying the live index. Model/profile changes use another index, not an atomic shadow-index migration.

See `docs/RAG-OPERATIONS.md` for scheduler setup, usage limits, failures, testing and release limitations.

= Keys in wp-config.php =

    define( 'OTAI_OPENAI_API_KEY', 'sk-…' );
    define( 'OTAI_BEDROCK_ACCESS_KEY', 'AKIA…' );
    define( 'OTAI_BEDROCK_SECRET_KEY', '…' );
    define( 'OTAI_ENCRYPTION_KEY', 'a-long-random-string' );

Pattern: `OTAI_{PROVIDER}_{FIELD}` (e.g. `OTAI_GEMINI_API_KEY`, `OTAI_AZURE_OPENAI_ENDPOINT`, `OTAI_OLLAMA_BASE_URL`, `OTAI_OPENAI_ENABLED`).

= WP-CLI =

    wp omnithread-ai status
    wp omnithread-ai readiness
    wp omnithread-ai support
    wp omnithread-ai reindex
    wp omnithread-ai work --limit=5
    wp omnithread-ai ask "What is your return policy?"
    wp omnithread-ai test openai
    wp omnithread-ai purge --days=30

= Shortcode =

`[omnithread_ai_chat height="600px"]` renders an inline chat.

== Developer hooks ==

* `otai_verticals` – add/modify industry presets.
* `otai_providers` – register custom providers (class extending `OmniThreadAI\Providers\Base_Provider`).
* `otai_retrieved_context` – inject CRM/ERP/API data into the context.
* `otai_system_prompt`, `otai_chat_messages`, `otai_response`.
* `otai_index_post_text`, `otai_indexable_post_types`.
* `otai_index_post_allowed` – must deny membership/restricted content at both indexing and retrieval; public WordPress visibility is the default boundary.
* `otai_use_elasticpress`, `otai_elasticpress_limit`, `otai_es_index_body`, `otai_es_search_timeout`, `otai_es_retry_after`.
* `otai_show_widget`, `otai_allowed_origins`, `otai_client_ip`, `otai_redact_patterns`, `otai_capability`.

== Changelog ==

= 1.4.2 =
* Added fail-closed post-source defaults for recognised membership/LMS systems, setup blocking and administrator warnings without an access connector.
* Fixed settings isolation when switching sites in a multisite request.
* Paginated WordPress personal-data export/erasure and surfaced privacy storage failures; failed deletion does not claim successful erasure.
* Added real multisite, access revocation, concurrent indexing/chat, privacy, denied database grants, SQL backup/restore and loopback browser fixtures.
* Added a reproducible offline validation runner and documented measured release evidence and remaining live-provider/commercial gates.

= 1.4.1 =
* Persistent atomic visitor rate limits and provider attempt caps prevent count-then-call overshoot across PHP workers.
* Failed generation calls and administrator connectivity tests consume the same provider allowance; no refunds on uncertain upstream outcomes.
* Daily provider reservations seed from existing usage on upgrade. Request caps are not currency budgets.
* Explicit 503 on capped-request storage failure; rate-limited REST replies include Retry-After and all plugin REST replies prohibit caching.
* Disabled providers are no longer selected in single-provider routing. Setup warns about uncapped eligible cloud providers.
* Database version five adds the request-budget table; maintenance and opt-in uninstall cover it.

= 1.4.0 =
* Added customer Setup & Readiness, explicit first-run publication and authenticated administrator preview while unpublished.
* New installs default to local-only, shortcode-only and transcript storage off; upgrades preserve existing settings.
* Added configuration-bound expiring connection-test records, WordPress Site Health and allowlisted support downloads.
* Added CLI readiness/support and clean ZIP packaging without developer tests/tools.
* Rebuild storage/queue failures now surface explicitly; FAQ-only sites can complete onboarding.
* Generation requests verify TLS and do not follow credential-bearing redirects.
* Added customer installation/update instructions and an explicit commercial-release acceptance checklist.

= 1.3.0 =
* Implemented separate embedding adapters, persistent compatible cache and an atomic daily text-input budget.
* Stable incremental passages, durable leased/coalesced jobs, bounded embedding batches, retries and rolling reconciliation.
* Elasticsearch/OpenSearch vector mappings and query adapters; semantic retrieval and hybrid reciprocal rank fusion.
* Current-source visibility/hash checks, bounded neighbour evidence, no-evidence abstention and structural citation validation.
* RAG settings, connection tests, diagnostics, queue controls and WP-CLI worker.
* Opt-in uninstall covers all seven local tables and clears scheduled jobs. Remote indexes are deliberately retained for operator-managed cleanup.

= 1.2.0 =
* Elasticsearch support: reuses ElasticPress / WP VIP Enterprise Search when present, or connects to your own open-source Elasticsearch / OpenSearch server (index created and synced automatically). Typo-tolerant, stemmed search; the database search fills gaps and takes over if the server is down.
* New Settings → Search Engine tab with detection status, install guide and connection test.
* "Free local only" mode now uses Elasticsearch + database search. New option to always try self-hosted models (Ollama) before paid providers.

= 1.1.1 =
* Cleaner answers from site content: decoded titles (no more &#8211;), coherent passages instead of fragments, no merged words from accordions/buttons.

= 1.1.0 =
* Display targeting: entire site, selected pages / post types / URLs, or shortcode only; per-page show/hide box in the editor; URL exclusions.
* Tables and lists are indexed row by row, and answers from site content are formatted with the page title and bullets. Rebuild the knowledge base after updating.

= 1.0.0 =
* Initial release.
