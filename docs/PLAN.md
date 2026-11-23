# OmniThread AI – Smart Site Assistant: Product & Technical Plan

A self-contained WordPress plugin that makes **any** WordPress site AI-enabled in minutes.
It has zero dependency on the active theme or other plugins (WooCommerce, LMS, events
plugins etc. are *optionally* detected and used when present).

Version 1.3.0 implements incremental embedding ingestion, a durable background
queue and Elasticsearch/OpenSearch hybrid RAG. See [RAG operations](RAG-OPERATIONS.md)
for setup, validation and explicit release limitations; this product plan is not
a production-readiness certification.

Version 1.4.0 adds standalone BYOK customer onboarding, safe first installation,
explicit launch, Site Health/support diagnostics and clean ZIP packaging.
See [Customer setup](CUSTOMER-SETUP.md) and [Commercial release gates](COMMERCIAL-RELEASE.md).

Version 1.4.1 replaces race-prone request limits with shared atomic reservations,
includes failed calls/tests in provider attempt caps and fails closed on quota
storage errors. These caps do not replace provider-side currency spending limits.

Version 1.4.2 adds recognised-access-system fail-closed defaults, fixes blog-bound
settings caching and paginates privacy requests with explicit storage errors.
Real multisite, concurrent offline pipelines, database-grant recovery, SQL restore
and a standard-theme browser smoke fixture now have measured evidence; the
commercial checklist distinguishes this evidence from untested deployment claims.

Version 1.5.0 adds an optional separately deployed FastAPI/LangGraph bridge with
public site context. Staff-only read connectors and MCP allowlists are isolated
from visitor chat; the plugin still requires no Python runtime.
See [Agent service](AGENT-SERVICE.md). This is not autonomous write execution or
a finished CRM/social OAuth connection marketplace.

---

## 1. Goals

| Goal | How the plugin meets it |
|------|-------------------------|
| Works on any WP site | Pure WP APIs, own DB tables, Shadow-DOM widget (theme CSS can't leak in/out), PHP 7.4+, WP 6.0+, single-site & multisite |
| Industry-ready out of the box | 18 vertical presets (prompt, guardrails, starters, recommended model parameters, content types to index) |
| Bring-your-own LLM | OpenAI, Azure OpenAI, Google Gemini, Anthropic Claude, Amazon Bedrock, Ollama (self-hosted), Mistral, Groq, DeepSeek, OpenRouter, any OpenAI-compatible server (vLLM, LM Studio, LocalAI, Together…), OmniThread AI backend |
| Cost control | **Smart Query Mode**: free FAQ/knowledge-base answers first → self-hosted Ollama → paid clouds in a user-defined order, with response cache, per-provider daily caps and automatic failover |
| Small sites *and* enterprises | Sensible auto-detected defaults for SMBs; for enterprises: keys via `wp-config.php` constants, encrypted keys at rest, PII redaction, rate limiting, retention policies, usage logs, WP-CLI, multisite, hooks/filters everywhere |

---

## 2. Major WordPress site categories and the ready-made AI solution for each

| # | Vertical | Typical plugins detected | Ready-made assistant | Temp | top_p | top_k | Why these values |
|---|----------|--------------------------|----------------------|------|-------|-------|------------------|
| 1 | E-commerce / Retail | WooCommerce, Easy Digital Downloads | Product finder, comparisons, shipping/returns, **live product lookup, logged-in order status** | 0.5 | 0.9 | 40 | Friendly & persuasive but grounded in catalogue |
| 2 | Education / e-Learning | LearnDash, LearnPress, Tutor LMS, LifterLMS, Sensei | Course advisor, enrolment help, study tutor with academic-integrity guardrail | 0.4 | 0.9 | 40 | Clear explanations, some variety |
| 3 | Healthcare / Clinics | post types: doctor, appointment | Services, doctors, timings, appointment guidance; **never diagnoses**, emergency redirect | 0.2 | 0.8 | 20 | Factual, conservative |
| 4 | Finance / Fintech / Banking / Insurance | – | Product explainer, fees, onboarding/KYC steps; **no personal investment advice, never asks for OTP/passwords** | 0.1 | 0.8 | 20 | Highest precision, compliance |
| 5 | Real Estate | post type `property` / `listing` | Property search, neighbourhood info & viewing scheduling | 0.4 | 0.9 | 40 | Balanced |
| 6 | Travel & Hospitality | booking plugins | Destination ideas, room/tour info, booking help | 0.6 | 0.95 | 50 | Inspirational tone |
| 7 | Restaurants & Food | menu plugins | Menu, dietary/allergen info (with caution), reservations, hours | 0.6 | 0.95 | 50 | Warm, conversational |
| 8 | News / Media / Blog / Publishing | – | Article finder & summariser, always cites sources | 0.3 | 0.85 | 30 | Accuracy, no invented facts |
| 9 | SaaS / Technology / Support | – | Docs-grounded technical support, troubleshooting | 0.3 | 0.85 | 30 | Precise, step-by-step |
| 10 | Legal & Professional services | – | Practice areas, consultation booking; **not legal advice** | 0.2 | 0.8 | 20 | Conservative |
| 11 | Non-profit / NGO / Charity | GiveWP, Charitable | Mission, donation & volunteering guidance | 0.5 | 0.9 | 40 | Empathetic |
| 12 | Government / Public sector | – | Service & form navigator, plain-language answers | 0.2 | 0.8 | 20 | Neutral & accurate |
| 13 | Events & Conferences | The Events Calendar, Events Manager | Agenda, speakers, tickets, venue | 0.4 | 0.9 | 40 | Balanced |
| 14 | Jobs / HR / Recruitment | WP Job Manager | Job search, application help, fairness guardrail | 0.4 | 0.9 | 40 | Balanced, unbiased |
| 15 | Fitness / Wellness / Beauty | – | Classes, memberships, trainer info, health disclaimer | 0.5 | 0.9 | 40 | Motivational |
| 16 | Automotive | post type `vehicle` / `car` | Inventory, test-drive booking, service scheduling | 0.4 | 0.9 | 40 | Balanced |
| 17 | Agency / Portfolio / Creative | – | Services, case studies, lead capture | 0.7 | 0.95 | 60 | Creative voice |
| 18 | General business / Corporate | – | Company info, services, contact routing | 0.4 | 0.9 | 40 | Safe default |

The admin picks a vertical (auto-suggested from detected plugins / post types) and clicks
**“Apply recommended settings”**; the Model Tuning tab explains *why* each value is recommended.

### Parameter guide (also shown in the admin)
* **Temperature** – randomness. 0–0.3 factual/regulated, 0.4–0.6 conversational, 0.7+ creative.
* **top_p** – nucleus sampling. Lower = safer word choice.
* **top_k** – sample only from the K most likely tokens (Gemini, Claude, Ollama). 20 conservative, 40 balanced, 60+ creative.
* **Max tokens** – answer length cap & cost cap.
* **Presence / frequency penalty** – discourage repetition (OpenAI-compatible providers).
* **History turns** – conversation memory sent to the model (cost vs. coherence).
* **Context chunks** – knowledge-base passages injected (grounding vs. cost).

---

## 3. Architecture

```
omnithread-ai/
├── omnithread-ai.php              bootstrap, constants, hooks
├── uninstall.php                  optional data removal
├── includes/
│   ├── class-plugin.php           service wiring
│   ├── class-installer.php        tables, cron, multisite
│   ├── class-settings.php         single option, sanitising, wp-config overrides
│   ├── class-crypto.php           AES-256 + HMAC encryption of API keys
│   ├── class-verticals.php        18 industry presets + auto-detection
│   ├── class-knowledge-base.php   indexing + retrieval (search engine + DB merge, rescoring) + FAQ matching
│   ├── class-search-engine.php    ElasticPress/VIP detection, own Elasticsearch/OpenSearch client + index sync, health circuit-breaker
│   ├── class-smart-router.php     FAQ → KB → cache → provider cascade with failover
│   ├── class-chat-service.php     prompt building, guardrails, PII redaction, logging
│   ├── class-conversations.php    transcripts & feedback
│   ├── class-usage.php            tokens / latency / answer-source analytics, daily caps
│   ├── class-rate-limiter.php     per-IP minute + day limits
│   ├── class-rest-controller.php  /wp-json/omnithread-ai/v1/{chat,feedback}
│   ├── class-frontend.php         widget loader + [omnithread_ai_chat] shortcode
│   ├── class-admin.php            Dashboard, Settings (7 tabs), Knowledge, Conversations
│   ├── class-cli.php              wp omnithread-ai status|reindex|ask|purge
│   ├── integrations/class-woocommerce.php   live products + own-order lookup
│   └── providers/                 one class per LLM vendor + registry
└── assets/ (widget.js, widget.css, admin.js, admin.css)
```

### Request flow
1. Widget (vanilla JS, Shadow DOM) → `POST /wp-json/omnithread-ai/v1/chat`.
2. REST: origin check, rate limit, length limit, session validation.
3. Chat service: retrieval (KB chunks, WooCommerce products/orders) → PII redaction → system prompt (vertical + persona + guardrails + grounded context).
4. Smart router (below) returns answer + provider + answer source.
5. Log usage (always, anonymous) and transcript (if enabled); return answer + source links.

### Smart Query Mode (cost-optimised cascade)
```
FAQ near match ──────────────► free answer
      │ no
KB high-confidence passage ──► free (extractive) answer
      │ no
Response cache hit ──────────► free answer
      │ no
Self-hosted models first (Ollama, local OpenAI-compatible) ► then paid clouds in drag & drop order (OpenAI ► Gemini ► Azure ► Bedrock …)
  – skip unconfigured / disabled / over daily cap
  – on timeout or error → next provider
      │ all failed
Graceful fallback: best KB links + contact info
```
Other modes: **Single provider** (predictable) and **Free local only** (Elasticsearch + database search, no external AI calls – zero cost, maximum privacy).

### Retrieval / search engine
Chosen automatically (Settings → Search Engine, overridable):
1. **ElasticPress / WP VIP Enterprise Search** already on the site → `WP_Query( ep_integrate )` ranks posts, mapped to our passages. Nothing extra to index.
2. **Own Elasticsearch / OpenSearch** (free, open source; URL + optional basic/API-key auth, wp-config constants) → plugin-managed index (English stemming, fuzzy matching), synced on save/trash/FAQ changes and on full rebuilds.
3. **MySQL FULLTEXT** with LIKE fallback – always available.

Search-engine hits are merged with DB results and re-scored by keyword coverage (+ engine relevance). Fuzzy-only matches ground AI answers but never trigger a direct extractive answer. If the server errors, calls pause for 5 minutes (DB search takes over, index flagged stale → admin "Rebuild" notice).

---

## 4. Security & compliance
* API keys encrypted at rest (AES-256-CBC + HMAC, key from `OTAI_ENCRYPTION_KEY` or WP salts); never sent to the browser; masked in admin.
* Keys can live in `wp-config.php`: `define( 'OTAI_OPENAI_API_KEY', '…' );` – pattern `OTAI_{PROVIDER}_{FIELD}` (uppercase).
* PII redaction (emails, phones, cards, national-ID-like numbers) before text leaves the server.
* Per-IP rate limiting (minute & day), message-length limits, same-origin check, context labelled as untrusted data (prompt-injection hardening).
* Order lookups only for the logged-in owner of the order.
* Retention policy with daily cron purge; transcript storage can be disabled; privacy-policy text registered.
* Admin requires `manage_options` (filterable via `otai_capability`) + nonces.

## 5. Extensibility (hooks)
`otai_verticals`, `otai_providers`, `otai_system_prompt`, `otai_retrieved_context`, `otai_chat_messages`,
`otai_response`, `otai_index_post_text`, `otai_indexable_post_types`, `otai_show_widget`, `otai_client_ip`,
`otai_redact_patterns`, `otai_capability`.

## 6. Roadmap (post v1)
Production RAG research and the proposed settings, pipeline, migrations and
release gates are documented in [RAG-PIPELINE.md](./RAG-PIPELINE.md). This is a
design specification, not a claim that semantic retrieval is already implemented.

Streaming (SSE) responses · vector embeddings (pgvector/Qdrant/OpenSearch) · lead capture & human handoff (email/Slack/Teams) ·
voice input · multilingual auto-translation · Gutenberg block · network-level defaults for multisite · analytics export.

## 7. Reuse of the existing `omnithreadai` monorepo
* Its **strategy-per-vertical** pattern → `class-verticals.php` presets.
* Its **Shadow-DOM widget** → `assets/js/widget.js` (rewritten for WordPress, accessible, mobile-friendly).
* Its NestJS backend can be plugged in as a provider (**OmniThread AI Backend**) via `/api/sessions/init` + `/api/chat/message`, so enterprises can run central orchestration and use this plugin as the WordPress front door.
