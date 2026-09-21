# LeadMap — Phase 1 (v0.1.0)

Collect local business leads from Google Maps by industry and ZIP, straight into WP Admin.

Phase 1 covers **Search → Leads → Export**. Enrichment, triage, audit and outreach arrive in
later phases; see `../DOCUMENTATION.md` for the full plan.

## What works now

- Search Google Places by industry + city/ZIP with a radius, run as a background job
- Automatic pagination (20 per page) up to your result cap
- Three-way deduplication: place id, normalized domain, E.164 phone
- Leads list with search, filters (status, ZIP, category, has-email), sorting and bulk delete
- CSV export of a selection or the whole database, streamed so large exports don't blow memory
- Search history with live status, cost per search, re-run and delete
- Encrypted API key storage with a connection test
- Monthly spend cap that halts searches before the bill runs away

## Install

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

Two guards: the plugin's monthly spend cap in Settings, and — more importantly — a hard daily
quota cap set in Google Cloud under *APIs & Services → Quotas*.

## Background jobs

Searches run through Action Scheduler when a plugin on the site provides it (WooCommerce, or
Action Scheduler standalone), and fall back to WP-Cron otherwise. One job fetches one page and
re-enqueues itself, so no request runs long and a stalled search resumes rather than restarting.

If searches stay stuck on *Queued*, WP-Cron is probably disabled. Either install Action
Scheduler or set up a real system cron hitting `wp-cron.php`.

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
touches. Covers domain/phone normalization, key encryption, the query object and the Places
response mapping.

## Extending

Register another lead source without touching the plugin:

```php
add_action( 'leadmap_register_providers', function ( $registry ) {
	$registry->add( new My_Provider() ); // implements LeadMap\Providers\Search_Provider
} );
```

`leadmap_lead_created` fires with a lead id whenever a new business is stored — that is the
hook Phase 2's enrichment will use.
