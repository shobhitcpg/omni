# Optional site-specific agents

Version 1.5.0 adds an opt-in provider for a separate FastAPI/LangGraph service.
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
4. Select this provider explicitly for Single provider routing if desired. Set
   daily request caps in both WordPress and the service, plus model billing caps.
5. Test the connection. This checks authentication and the site binding only;
   it does not call an LLM or prove CRM/MCP connectivity.
6. Preview representative questions against approved sources before publication.

Local-only routing never calls the agent service. Existing local-first answers
can bypass it. Existing grounded-only rules still abstain when no site evidence
exists. Oversized context fails explicitly instead of being silently truncated.
HTTPS is mandatory except loopback development.

## External service access

The separate runtime implements read-only HubSpot contact/ticket and Zoho Desk
ticket adapters, and an operator-allowlisted MCP client. They require authorized
service credentials and live account testing before use. Their results are
staff-private and **cannot be requested through public visitor chat**.

Instagram, Facebook, YouTube, Zoho CRM, Zendesk, Freshdesk, Mailchimp, Brevo,
Google Calendar, Slack and Teams are planned catalog entries, not functioning
connections. No OAuth connection wizard, social publishing, write action,
automatic campaign or fully autonomous tool-selection loop is shipped.

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
PHP-to-FastAPI/LangGraph HTTP roundtrip. Live providers, social/CRM accounts and
public hosting were not configured. See the separate service README for runnable
installation, provisioning, endpoint contracts and exact limitations.
