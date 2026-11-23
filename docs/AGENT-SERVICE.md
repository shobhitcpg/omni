# Optional site-specific agents

Version 1.5.0 adds an opt-in provider for a separate FastAPI/LangGraph service.
Version 1.6.0 adds selection of a centrally built, published public agent.
The WordPress plugin remains independent and works without Python or a seller
account. The server source is maintained separately at
`services/omnithread-agent` in the development repository and is **not in this ZIP**.

## What the installed plugin can share

After explicit provider configuration/consent, it sends only:

- The site's public URL, name, description, language and configured industry.
- The current (redacted when enabled) visitor question.
- Approved retrieved context from the existing RAG/access pipeline.

It does not export the database, plugin/theme list, keys, administrator email,
customer accounts or conversation history. It does not silently scan external
accounts. Restricted content still requires the existing access connector rules.
Trusted custom context hooks must classify/protect anything they append.
Sending context to your own server is still external processing and needs review.

The Python service binds the site credential to a server-configured tenant and
matching site URL. Each client can have its own agent name, instructions, answer
model and connector configuration. No model is trained automatically.

## WordPress setup

1. Deploy/provision the separate service using its own README; obtain its base
   HTTPS URL and **public-chat site credential**.
2. In **AI Providers**, enable **Agent Service (FastAPI / LangGraph)**.
3. Enter those values and approve the outbound processing checkbox. No request
   can proceed without consent. Never enter a staff/operator key.
4. Open your separately deployed service's `/builder` page using your staff
   operator credential behind your SSO/MFA gateway. Create/save/preview a public
   agent and explicitly publish it. Do not use a site-chat key to manage agents.
5. Copy its ID into **Published agent ID** in WordPress. Blank uses the existing
   tenant default. Staff, unpublished and other clients' agents are rejected.
6. Select this provider explicitly for Single provider routing if desired. Set
   daily request caps in both WordPress and the service, plus model billing caps.
7. Test the connection. This checks authentication, site binding and the selected
   agent's publication/audience, not LLM or CRM/MCP connectivity.
8. Preview representative questions against approved sources before publication.

Local-only routing never calls the agent service. Existing local-first answers
can bypass it. Existing grounded-only rules still abstain when no site evidence
exists. Oversized context fails explicitly instead of being silently truncated.
HTTPS is mandatory except loopback development.

The central builder saves tenant-isolated draft and published snapshots with
revision conflict checks. Public templates include support and store guides.
Staff templates can select configured read operations with explicit record tests,
but do not generate model answers from private records. Missing credentials and
planned integrations cannot be published as working connections.

WordPress response caching is bypassed when the agent service is a routing
candidate so central changes are checked on each remote turn. Unpublishing an
agent does not turn off local FAQ/RAG routing or cancel an in-flight call. Existing
local fallback remains possible on service errors; disable the assistant in
WordPress if the entire public chat must stop.

## External service access

The separate runtime implements read-only HubSpot contact/ticket and Zoho Desk
ticket adapters, and an operator-allowlisted MCP client. They require authorized
service credentials and live account testing before use. Their results are
staff-private and **cannot be requested through public visitor chat**.

Instagram, Facebook, YouTube, Zoho CRM, Zendesk, Freshdesk, Mailchimp, Brevo,
Google Calendar, Slack and Teams are planned catalog entries, not functioning
connections. No OAuth connection wizard, social publishing, write action,
automatic campaign or fully autonomous tool-selection loop is shipped.
Forms (WPForms/Gravity Forms/Contact Form 7), LearnDash and Amelia bookings are
also clearly labelled planned entries; no private submissions/enrolments are read.

To answer a customer's own ticket/order question publicly, first implement
verified identity and record ownership. To perform writes later, add scoped
permissions, explicit user approval, idempotency, audit and revocation. MCP
alone supplies none of those guarantees.

## Deployment and confidentiality

Python runs on your managed server, not inside customer WordPress. Keep separate
tenant credentials and external service tokens in a secret manager, protect
storage/logs, disable body logging, require HTTPS and review data retention.
Separation is an architectural boundary, not a confidentiality certification.
The initial runtime uses single-host SQLite quotas/audit and no transcript memory;
high-scale/HA operation needs additional infrastructure and qualification.

The bridge was tested with WordPress/MySQL contracts and a real local
PHP-to-FastAPI/LangGraph HTTP roundtrip including a selected published agent.
The central builder also passed browser smoke checks for draft/preview/publish,
staff read permissions with synthetic data, mobile layout and locking.
Live providers, social/CRM accounts and
public hosting were not configured. See the separate service README for runnable
installation, provisioning, endpoint contracts and exact limitations.
