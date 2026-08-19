# Paid Memberships for Flarum — spec v1 (2026-08-19)

Name: **Flock** (Karl, 2026-08-19). Price: **$30/yr** flat.
Decided by Karl 2026-08-19: **owner's own Stripe keys** (we are never in the
money flow) and **flat yearly srvup key, Birdseye-style** (extension free/MIT
on Packagist, paid key unlocks it).

## What it is

A forum owner connects their own Stripe, defines membership plans, and members
subscribe. Subscription state drives **Flarum group membership**; the owner
scopes tag permissions to that group using Flarum's own native restriction UI.
We never touch tag logic — which is why this composes with every other
permission extension for free.

Member money goes owner's-Stripe direct. srvup's revenue is the key
(suggest $29–49/yr — it makes the owner money, so it prices above Birdseye;
final number is Karl's).

## The one sentence that constrains everything

**A member's paid access must never depend on srvup being up.**
The key check follows Birdseye's status-endpoint pattern but FAILS OPEN with a
cached last-good validation (~7 days grace). A lapsed key stops NEW
subscriptions; it never strips access members already paid the owner for.

## Architecture

### Extension (public, MIT, Flarum 2.0 only)
- **Settings** (encrypted, hidden from the admin payload — HideKeysFromAdmin
  pattern from forage): srvup key, Stripe **restricted** key (never the full
  secret; scopes: Checkout Sessions w, Subscriptions rw, Customers rw,
  Products/Prices w, Webhook Endpoints w), webhook signing secret (minted by
  the extension itself via the API on save — no manual dashboard step).
- **Plans**: name, price, interval (month/year), currency = the Stripe
  account's default, → **one Flarum group per plan** (extension creates the
  group, owner may repoint it). Plan create/edit pushes Product+Price to
  Stripe. Prices are immutable in Stripe: price change = new Price, existing
  subscribers keep the old one (grandfathering is free, say so in the UI).
- **Member flow**: logged-in members only (no guest checkout — the
  subscription attaches to the user). Join button → Stripe **hosted Checkout**
  (SAQ-A, zero PCI burden, no card forms in Flarum) →
  `allow_promotion_codes: true` (owner's coupons work day one for free) →
  webhook lands → local subscription row + group grant.
- **Webhook endpoint** `/api/linkrobins-memberships/stripe`: CSRF-exempt,
  signature-verified, and does its work IN the webhook request — no queue
  worker required for correctness (the queue-portability contract; digest-style
  features are where workers matter, not here).
- **Zombie-card rule** (learned on our own billing): no local row until the
  webhook confirms. An abandoned checkout leaves nothing behind.

### Lifecycle rules
- **Cancel** = `cancel_at_period_end`. Access until the period ends. Never
  delete the user or content — only the group membership, ever.
- **Failed renewal**: Stripe smart-retries; `past_due` keeps access for a
  configurable grace (default 7 days), then the group is removed on
  `subscription.deleted`/final failure. Rejoin = new checkout.
- **Refund/dispute**: handled in the owner's Stripe dashboard; the extension
  treats subscription status as the single source of truth and reacts to the
  status events only.
- **Reconciliation without cron**: webhooks get missed (host downtime). No
  scheduled job dependency: lazily re-verify a member's own subscription
  against Stripe on their session start when the local row is stale >24h,
  plus a manual "sync now" button in admin. (A hosted webcron trigger is the
  drawer idea if this ever needs to be push-based.)

### srvup side (clone the Birdseye rails)
- `MembershipKey` model (`flk_` prefix), flat yearly, status
  incomplete|active|canceled, last_seen_at.
- Account tab (chirp tile/slide-over idiom): buy, reveal, rotate, cancel,
  billing portal. Admin list page. Lander (route name TBD with product name).
- Billing service `lr_flock`; webhook `is_memberships` → activate/cancel
  the key. No provisioning jobs — there is no container. Like Birdseye there is
  zero per-customer infrastructure; unlike Birdseye there is also zero RUNTIME
  involvement (Birdseye crunches every forum-day of events through
  /api/birdseye/process; here the only touchpoint is the fail-open key check).
- Status endpoint like `/api/birdseye/status`: reports key standing; never
  binds forum_url on a read (the PR #68 lesson).

## MVP cut (v1.0)
One Stripe account per forum; hosted Checkout only; month+year intervals;
plans→groups 1:1; cancel/grace/reconcile as above; owner revenue summary =
a link to their Stripe dashboard, NOT a rebuilt dashboard.
Explicitly OUT of v1: trials, metered/tiered seats, gifting, per-discussion
paywalls, VAT/tax handling (Stripe Tax toggle passes through if the owner
enables it — we surface the checkbox, nothing more), multi-currency.

## Build notes
- BEFORE scaffolding: run `flarum_dev` (scaffold/backend/frontend topics) —
  same contracts as forage (string-path extends, committed js/dist, fail-closed
  API fields, CommentPost-style concrete-class traps won't apply here but
  group mutations must use Flarum's own GroupRepository/events so other
  extensions see them).
- Group grant/revoke must fire Flarum's events (badges, notifications, and
  audit extensions listen to them).
- Tests that matter most: webhook signature rejection; zombie checkout leaves
  no row; grace window boundaries; group NOT removed on transient `past_due`;
  reconcile-on-login when stale; key fail-open (srvup unreachable ≠ members
  locked out).
- Repo: `linkrobins/flarum-flock` (public, MIT) once named — creating
  the GitHub repo is a Karl-visible action, ask first.
