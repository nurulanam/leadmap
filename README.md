# LeadMap — Phase 2 (v0.3.0)

Collect local business leads from Google Maps by industry and ZIP, straight into WP Admin.

Covers **Search → Enrich → Triage → Audit → Leads → Export**. Outreach arrives in Phase 5;
see `../DOCUMENTATION.md` for the full plan.

## What works now

**Phase 4 — audit**

- **Audit queue** — one lead at a time, every automated signal beside the form
- **Ten issue tags**, seeded from the triage verdict so the form opens half-filled
- **Primary issue** picks which outreach template will be used
- **Problem note** in your own words, which becomes `{{problem}}` in the email — a throwaway
  one is refused, because it would produce a message not worth sending
- **Fit score** (0–100), separate from the opportunity score: how promising the *prospect*
  is, weighted on reachability, business viability and whether the decision is made locally
- Keyboard: number keys toggle problems, `Ctrl`+`Enter` saves and loads the next lead
- **Not a fit** takes a lead out of the pipeline without requiring a write-up
- **Optional Gemini draft** — a button that writes a first version of the problem note from
  the measurements already collected. Free tier, separate key, entirely optional

**Phase 3 — triage**

- **Seven verdicts** — Outdated / Broken / Not mobile / Slow / No SSL / Poor SEO /
  Weak listing (multi-select), plus Skip, No website and Unsure — from the lead page
- **Suggested flags** — the automated checks mark the flags they point at with a dotted
  outline. They are offered, never pre-selected: pre-ticking would turn a judgement into a
  rubber stamp, which is the one thing this stage exists to avoid
- Verdicts save **optimistically** — the card clears and focus moves on immediately, the
  request settles behind you, and a failure puts the card back rather than losing the lead
- **Screenshots from PageSpeed** — Google's own render at each viewport, free, no extra call
- **Optional auto-triage** for the unambiguous cases only; off by default, every automatic
  verdict logged and reversible
- Triage filter on the Leads screen, including an **Auto-triaged** view

**Phase 2 — enrichment**

- Crawls each lead's website (home page plus up to four contact/about pages) and extracts
  email addresses, ranked 0–100 by confidence
- Fingerprints the platform and front-end stack, and flags dated markers (jQuery 1.x, Flash,
  table layouts, old WordPress, dated site builders)
- Checks on-page SEO: HTTPS, mobile viewport, title, description, H1, schema, canonical,
  alt-text coverage, mixed content, footer copyright year
- Measures Google PageSpeed Insights for **both mobile and desktop**, shown as PSI-style
  gauges with Core Web Vitals and Google's own per-metric pass/fail colours
- Rolls all of it into a **staleness score** (0–100) that sorts the most neglected sites first
- Lead detail screen showing every email found, all signals, and the activity trail
- Bulk "Enrich" action, or automatic enrichment on collection

**Search experience**

- **Live area map** on the New Search screen — type a city or ZIP and the map draws the exact
  radius being searched, before any money is spent
- **Live search console** — a running search streams what it is doing (locating, fetching
  page 2, 19 found / 12 new, finished) instead of sitting still for a minute

**Phase 1 — collection**

- Search Google Places by industry + city/ZIP with a radius, run as a background job
- Automatic pagination (20 per page) with hard stops so a search can never run away:
  it halts on the first empty page, after 3 pages of nothing new, at the page budget implied
  by your result cap, and at a per-search spend limit — and says which one stopped it
- Three-way deduplication: place id, normalized domain, E.164 phone
- Leads list with search, filters (status, ZIP, category, has-email), sorting and bulk delete
- CSV export of a selection or the whole database, streamed so large exports don't blow memory,
  now including staleness, PageSpeed, SSL, mobile-ready and platform columns
- Search history with live status, cost per search, re-run and delete
- Encrypted API key storage with a connection test
- Monthly spend cap that halts searches before the bill runs away

## Build a release zip

From the repository root:

```bash
./build.sh                # lint, test, then write dist/leadmap-<version>.zip
./build.sh --dev          # include tests/ in the archive
./build.sh --skip-tests   # package without running the suite
```

The script refuses to package a broken build: it lints every PHP file, runs the test suite,
checks the plugin header version matches the `LEADMAP_VERSION` constant, rejects leftover
debug output, and verifies the finished archive has exactly one top-level `leadmap/` folder
with nothing outside it.

## Install

**From a zip:** WordPress admin → Plugins → Add New → Upload Plugin → choose
`dist/leadmap-<version>.zip`. Or `wp plugin install dist/leadmap-<version>.zip --force --activate`.

**From source:**

1. Copy the `leadmap` folder into `wp-content/plugins/`.
2. Activate **LeadMap** in Plugins.
3. Add your Google API key (see below).
4. Go to **LeadMap → New Search**.

Requires PHP 8.1+ and WordPress 6.4+. No Composer step — the plugin autoloads its own classes
and vendors nothing.

## API key

Recommended — keep it out of the database entirely, in `wp-config.php`:

```php
define( 'LEADMAP_GOOGLE_API_KEY', 'AIza...' );
```

Otherwise paste it under **LeadMap → Settings**, where it is stored AES-256-GCM encrypted and
never sent back to the browser.

**Enable on the Google Cloud project:** Places API (New), Geocoding API.
**Restrict the key by server IP address**, not by HTTP referrer — this key is used server-side,
and a referrer restriction would leave it open to anyone.
Billing must be enabled or Places returns `REQUEST_DENIED`.

Use **Test connection** on the settings screen to confirm the key before running a search.

## Cost

**The first 1,000 Text Search calls each month are free** — that is roughly 20,000 leads, so
normal use costs nothing.

Past that it is **$35 per 1,000 calls** (Text Search Enterprise), which works out to
**$0.035 per page of 20 results**, or about **$0.11 for a 60-result search**.

Enterprise is the cheapest tier that returns a phone number and website, so there is no way to
go lower while still collecting usable leads. The field mask deliberately omits reviews and
price level, which would move the call into the pricier Atmosphere SKU for data we do not use.
The plugin also uses Text Search only — no separate Place Details call, which would double the
per-lead cost.

Geocoding (one call per search, to resolve the ZIP) has its own 10,000 free calls per month.

Three guards: a **per-search spend limit** (default $0.50), a **monthly spend cap**, and — most
importantly — a hard daily quota cap set in Google Cloud under *APIs & Services → Quotas*.

The per-search limit matters because Google will keep issuing page tokens after it has run out
of businesses. Paging on a result cap alone is not safe: if no new results arrive, the cap is
never reached and the search pages forever. A search with no results now costs at most about
$0.04 before it stops.

## Background jobs

Searches run through Action Scheduler when a plugin on the site provides it (WooCommerce, or
Action Scheduler standalone), and fall back to WP-Cron otherwise. One job fetches one page and
re-enqueues itself, so no request runs long and a stalled search resumes rather than restarting.

If searches stay stuck on *Queued*, WP-Cron is probably disabled. Either install Action
Scheduler or set up a real system cron hitting `wp-cron.php`.

## Email discovery — what to expect

No Google Maps source returns email addresses; the field does not exist in the API. Emails come from crawling the business's own website, which means:

- **Expect a 35–60% hit rate** on small local businesses. Plan the funnel around that.
- **Cloudflare email obfuscation is decoded.** Cloudflare strips `mailto:` out of the HTML
  entirely and leaves a hex blob its own JavaScript decodes in the browser. It is on by
  default on many plans, so without handling it a large share of small business sites look as
  though they publish no email at all. The encoding is a single-byte XOR and is decoded during
  extraction.
- Leads with no email are still reachable by phone, and are marked so you can find them
  manually.
- Addresses are ranked: an address on the site's own domain found in a `mailto:` on the
  contact page scores near 100; a Gmail address in a footer scores low because it is more
  often the web designer's than the business's; a role account (`info@`, `sales@`) is usable
  but ranked below a named person.

### Finding the contact page

Old sites are the target, and old sites have idiosyncratic URLs. Link matching squashes the
href, link text, `title`, `alt` and `aria-label` down to bare alphanumerics before comparing,
so one rule covers every shape of the same page:

```
/contact-us        /Contact_Us.aspx      /contactus.htm
/contact.php       /contact%20us.html    /contac-tus.html
/index.php?page=contact                  <a href="/p/9"><img alt="Contact Us"></a>
```

Query strings are searched as well as paths, because older sites often carry the page identity
there. Non-English pages are covered too — `kontakt`, `contacto`, `contatti`, `contato`,
`nous-contacter`, `impressum`, with accents folded. A short exclusion list keeps obvious traps
out: an optician's `/contact-lenses` is not a contact page.

When a site offers no recognisable link at all — an image-only nav, a JavaScript menu — the
crawler falls back to trying a handful of common paths directly (`/contact`, `/contact.html`,
`/contact-us.php` …), stopping at the first hit and skipping soft 404s that redirect home.

The crawler is deliberately polite: honest user agent, `robots.txt` respected, one request per
second per host, 2 MB response cap, at most five pages per site.

## Timeouts

Three settings bound how long a lead can take, so one unresponsive site never stalls the queue:

| Setting | Default | What it does |
|---|---|---|
| Per-request timeout | 10s | How long to wait for a single page |
| Time budget per lead | 60s | Total for the whole crawl. When it runs out the home page is kept and remaining contact pages are abandoned — a partial result beats a job that never ends |
| Treat as stuck after | 15 min | An hourly watchdog rescues leads left in `Enriching` after a killed worker or PHP timeout |

The watchdog retries a stalled lead once, then gives up and records why, so a site that accepts
connections but never responds cannot consume jobs indefinitely.

## The Gemini draft (optional)

The audit screen can draft the problem note for you. It is off unless you add a key, and it
needs its **own** key — a Gemini key from [aistudio.google.com/apikey](https://aistudio.google.com/apikey),
not the Google Cloud Maps key. The free tier is enough for this.

Two rules shape the implementation:

**It sees only measured values.** The prompt is assembled from the actual numbers — PageSpeed
scores, largest-paint timing, missing viewport, plain HTTP, alt-text counts, footer copyright
year, detected jQuery version — and the instructions forbid introducing any others. A cold
email whose one specific claim is invented is worse than no email: that claim is the thing the
recipient can check, and the pitch rests on it being true. A lead with nothing measured is
refused rather than guessed at.

**It drafts, you decide.** The text lands in the textarea for editing and is never saved until
you save it. It will not overwrite something you have already written without asking.

## Two scores, and why they differ

**Opportunity** (0–100) measures how much is wrong with the website. **Fit** (0–100) measures
how promising the business is as a prospect. They are not the same thing, and conflating them
wastes time: a catastrophic site belonging to a dormant business you cannot email is a poor
prospect however much work it needs, while a moderately dated site belonging to a busy firm
you can reach by name is a good one.

Triage and the opportunity score sort *what to look at*. Fit sorts *who to write to*.

## Triage

The audit takes minutes per lead. Running it on a business whose site is already modern is
wasted effort — there is no problem to sell against. Triage is a cheap filter in front of an
expensive one, and it is a **hard gate**: a lead that fails it leaves the pipeline and costs
nothing further. It happens on the lead page; there is no separate screen.

Verdicts 1–4 are multi-select, because a site can be both outdated and broken, and they seed
the deep audit's issue tags so it starts half-filled rather than blank.

### Screenshots

There is one source: PageSpeed. Lighthouse renders each page in a real Chrome at the requested
viewport and returns the result as a `final-screenshot` audit, so a speed check yields a
**genuine mobile render and a genuine desktop render at no extra cost** — no third party, no
API key, no separate request, nothing to configure.

Third-party providers were supported here and were removed. None produced a true mobile render
for free: they render at desktop width and crop, which looks like a phone view while showing
none of what a phone visitor sees. That is worse than no screenshot, because it is convincing.

A lead has no screenshot until its speed check runs. The triage card says so plainly and links
to the live site, rather than showing a misleading placeholder.

Captures live in `uploads/leadmap-shots/` and are deleted when a lead is deleted or skipped.

Auto-triage skips a lead only when **every** signal says the site is healthy: PageSpeed 90+,
mobile viewport, valid HTTPS, no mixed content, and a staleness score of zero. Anything
ambiguous still comes to you — an automatic skip is the one mistake that silently loses a real
lead.

## The map

Leaflet and OpenStreetMap, bundled in `src/Admin/assets/vendor/leaflet` (BSD-2). Deliberately
**not** Google Maps JavaScript: the Google key is restricted to this server's IP address, which
is correct for server-side use but means it cannot be used from a browser at all. Google Maps JS
would need a second, referrer-restricted key and would cost per map load. Leaflet needs no key
and costs nothing.

Geocoding for the preview is proxied through `leadmap/v1/geocode`, so the key stays server-side.
Results are cached for a week, because the map is queried on every pause in typing.

## Security

The crawler fetches URLs that came from a third-party API, so it is a classic SSRF surface.
`Support\Url_Guard` validates every request and every redirect hop:

- Only `http`/`https`, only ports 80 and 443, no credentials in the URL, no control characters
- Every IP the hostname resolves to (A **and** AAAA) must be publicly routable — one private
  answer blocks the host, so a split-horizon name cannot slip through
- Cloud metadata endpoints, RFC 1918, loopback, link-local, CGNAT and IPv4-mapped IPv6
  are all blocked
- Redirects are followed manually, at most three, with the full check repeated on each hop

`tests/test-url-guard.php` holds 60 assertions covering these. They are the security contract
of the crawler: if they start failing, it can be pointed at internal infrastructure.

Residual risk: DNS rebinding. The name is resolved during validation and could resolve
differently when the socket opens. Fully closing that needs connecting to a pinned IP with a
forced Host header, which the WP HTTP API cannot express — it is the same exposure carried by
core's own `wp_safe_remote_get()`.

## Data

Four tables, all prefixed `{$wpdb->prefix}leadmap_`: `searches`, `leads`, `lead_emails`, `events`.

Deleting the plugin keeps your leads. To have uninstall drop the tables too, opt in first:

```php
update_option( 'leadmap_delete_data_on_uninstall', 1 );
```

## Tests

```bash
./tests/run.sh
```

Standalone — no WordPress or PHPUnit needed. Each file stubs the few WP functions its subject
touches.

| File | Covers |
|---|---|
| `test-url-guard.php` | SSRF defences (60 assertions) |
| `test-email-extractor.php` | Email discovery, de-obfuscation, ranking |
| `test-enrichment.php` | Tech fingerprinting, SEO checks, staleness, robots.txt |
| `test-normalize.php` | Domain/phone normalization, encryption, query object |
| `test-places-mapping.php` | Google Places response mapping |
| `test-paging-body.php` | The paging contract with Google |
| `test-error-messages.php` | Google error → actionable instruction mapping |

## Extending

Register another lead source without touching the plugin:

```php
add_action( 'leadmap_register_providers', function ( $registry ) {
	$registry->add( new My_Provider() ); // implements LeadMap\Providers\Search_Provider
} );
```

`leadmap_lead_created` fires with a lead id whenever a new business is stored; enrichment
hangs off it. Enrichment jobs are `leadmap/lead/enrich` and `leadmap/lead/speed`.
