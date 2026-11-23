# Customer installation and launch

## What you need

- A WordPress site with administrator access, database CREATE/ALTER privileges,
  PHP mbstring and OpenSSL. Use a maintained PHP version (8.3+ recommended),
  HTTPS, backups and a working WordPress/server scheduler.
- For basic public-content search: no AI account, model, Elasticsearch or seller
  account is required. Responses are extracted from indexed content, not generated.
- For generated answers: your own enabled AI provider and API key, or a reachable
  Ollama installation with a downloaded chat model. Provider and hosting costs
  are separate from the plugin purchase.
- For semantic/hybrid search: your own supported Elasticsearch/OpenSearch service
  plus an embedding model, configured separately from the answer model.
  Shared WordPress hosting does not automatically include these services.

The plugin does not install server software or download models without approval.
You can start with database search and add AI/vector services later.

## First installation

1. In WordPress, **Plugins > Add New > Upload Plugin**, upload the supplied ZIP
   and activate. New installations are unpublished: no public chat endpoint or
   widget, transcript storage off, local-only routing, shortcode-only display.
2. Open **OmniThread AI > Setup & Readiness**. Choose your industry, experience and
   display mode. Preset application is optional and explicitly replaces the
   settings controlled by that preset; existing custom instructions and saved
   keys are otherwise preserved.
3. Choose **Local site search** to get started without services. For BYOK choose
   your primary answer provider, then enable/configure it under **AI Providers**.
   The guided BYOK choice uses one provider, not an automatic paid cascade.
   Advanced routing remains available separately.
4. Select public post types and/or add FAQs under **Content sources**. Do not
   index membership, enrolment or other restricted content without an explicit
   access connector. Generic public WordPress checks cannot infer every plugin.
5. If wanted, set up embeddings and your search server under **RAG Pipeline**.
   See [RAG operations](RAG-OPERATIONS.md). Keyword-only needs neither.
6. Review privacy/access rules, external processing, disclosure text and retention.
   Acknowledge the owner review in setup; this is not a legal/compliance check.
7. **Build knowledge base**, then process jobs under **RAG Pipeline** or through
   the scheduled CLI worker. Queued work is not completed indexing.
8. Run the required connection tests for the services you selected. Connectivity
   tests use synthetic text, not site content, but provider charges may apply.
   Launch requires tests within 24 hours. Relevant configuration changes invalidate
   saved tests. An unchanged, already published deployment does not require daily
   paid connectivity tests; saved checks are not a live availability guarantee.
9. Try representative questions in the administrator dashboard Playground while
   unpublished. Verify correct answers, abstention and source links. Anonymous
   visitors cannot use the unpublished chat.
10. Resolve all **blocked** readiness checks, review warnings, then click
    **Publish assistant**. For shortcode-only display add `[omnithread_ai_chat]`
    to the desired page. The General settings Enabled switch stops public chat.

The launch gate is a configuration check, not a guarantee of answer quality,
current remote-service health, capacity, security certification or compliance.
Existing installations retain their enabled/display/routing/storage choices on
upgrade. They are not forcibly unpublished.

## Membership, LMS and custom access rules

Version 1.4.2 recognises common active membership/LMS plugin directory names,
including network-active plugins, and denies **all post sources** by default
when one is found. Setup blocks post-based launch and administrators receive a
notice until a connector is registered. This also applies to previously indexed
posts at retrieval time: old local/remote passages are not permission to answer.
Curated FAQs and custom knowledge are deliberately public; do not paste private
course, patient, customer or account data into them.

An integration must implement `otai_index_post_allowed` to allow only content
that an **anonymous visitor** can read. A callback's presence only removes the
configuration blocker; it does not prove that the callback is correct. Never use
the current visitor's enrolment/role to authorize a shared public index. This
release is not a per-member/private-corpus assistant.

Detection is best effort, not universal access-policy discovery. Developers of
custom/MU access systems must register an identifier/label before indexing:

```php
add_filter( 'otai_access_systems', function ( $systems ) {
    $systems['my-access-system'] = 'My access system';
    return $systems;
} );
```

Registration fails closed; it does not grant access. Implement and test a
site-specific anonymous-public-only connector separately, or select no post types
and use approved public FAQs/custom knowledge. Re-test activation, changed
metadata, role restrictions, deactivation and stale remote results. Queue a
rebuild/cleanup after changing policy. Retrieval denial is immediate, but historic
remote embeddings are not automatically purged on access-plugin activation;
remove those separately if your processing/retention policy requires it.

## Ongoing operations

Set a **Daily request-attempt limit (UTC)** for each enabled cloud provider under
AI Providers. Zero means unlimited. The limit is reserved atomically before a
call and includes unsuccessful calls and connectivity tests: an HTTP timeout does
not prove the provider did not process/bill the request. Cache hits do not consume
model attempts. Smart routing can use the next eligible provider's independent
allowance; single-provider mode does not. Disabled providers are not selected.

These are request counts, **not dollar/token spending limits**. Set upstream
provider billing/spending caps too. The embedding input budget is separate.
Owner/admin preview bypasses visitor IP rate limits but not model-attempt caps.
Visitors share an allowance when they share an IP address (for example an office).
If your site is behind a proxy, configure the documented `otai_client_ip` hook to
trust only your actual proxy; do not blindly trust arbitrary forwarded headers.
Missing quota storage fails closed for rate-limited/capped requests, not as an
unlimited fallback. Fixed windows reset on minute/UTC day boundaries and can
permit a burst spanning a boundary; this is not a sliding-window limiter.

Use **Tools > Site Health** and **Setup & Readiness** to review local checks.
These page loads never contact an inference or search service. Remote tests are
explicit owner actions. Schedule reliable workers for low-traffic/large sites:

```sh
wp omnithread-ai readiness
wp omnithread-ai reindex
wp omnithread-ai work --limit=5
wp omnithread-ai support
```

In multisite, run the worker with each site's `--url`. Activation/uninstall
network-scale behavior still needs validation for your network size.

Connection tests establish connectivity, not retrieval quality. Test real
questions again after changing models, source rules, prompts or industry.
Changing embedding profiles requires rebuilding the vector corpus; there is no
atomic old/new profile switch yet. Treat membership ACL changes as a deployment
change requiring tests.

### Privacy operations and backups

Transcripts are off by default. When enabled, WordPress personal-data export
returns up to 100 messages per page, and erasure processes up to 100 conversations
per callback until complete. Storage errors fail the privacy request instead of
claiming success; fix database access and retry. The email-based tools identify
logged-in users' linked conversations, not arbitrary anonymous sessions. Establish
your own verified process for anonymous-session requests and provider-side data.
Retention removes old local conversations and their messages; it is not deletion
from a third-party provider or a visitor's browser storage.

Back up plugin files, the WordPress database and the salts/encryption-key
configuration together through your normal secured backup service. Restoring
encrypted keys requires the same encryption secret; do not put those secrets in
a public ZIP or support report. Use InnoDB for plugin tables and test recovery on
staging. A local synthetic SQL restore drill passed, but that is not proof of your
host's disaster recovery or a downgrade between arbitrary schema versions.

## Support and manual updates

**Download safe support report** produces JSON containing versions, published
state, retrieval/routing mode and readiness IDs/statuses. It deliberately omits
site identity, endpoints, credentials, content, transcripts, prompts and raw
errors. Review the report before sharing. It is not uploaded automatically.
Share detailed logs only through your agreed secure support process and redact
them first.

For updates:

1. Back up files/database; validate the new release on staging.
2. Upload the new ZIP through WordPress and use **Replace current with uploaded**.
   Do not delete the old plugin first.
3. Verify settings, Site Health, indexing jobs, a sample public answer and an
   unpublished/private-content test after upgrade. Complete any reindex notice.
4. For rollback, restore a matched files/database backup when schemas differ.
   Replacing code alone is not a guaranteed database downgrade.

The current product uses manual ZIP updates. It has no license server,
automatic updater, payment processing or seller account requirement.
Deactivation retains data. Uninstall retains data unless the owner explicitly
enabled deletion; remote indexes remain operator-managed.
