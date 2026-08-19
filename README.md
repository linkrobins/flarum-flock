# Flock

Paid memberships for Flarum. Your Stripe, your money, your groups.

A member subscribes through Stripe Checkout, and their subscription drives a
Flarum **group**. You scope whatever you like to that group using Flarum's own
permission screens: a private tag, posting rights, an upload limit, anything
that takes a group. Flock never touches tag logic, which is why it composes with
the permission extensions you already run.

Your members pay **you**. The money moves between their card and your own Stripe
account, and never passes through Link Robins.

## What you need

- Flarum 2.0
- A Stripe account
- A Flock key from [linkrobins.com/flock](https://linkrobins.com/flock)

## Setting it up

```
composer require linkrobins/flarum-flock
```

Enable Flock in the admin panel, open its page, and fill in two fields:

- **Your Flock key**, from your linkrobins.com dashboard.
- **A Stripe restricted key**, from your own Stripe dashboard under Developers,
  API keys, Create restricted key. Never paste your secret key. The restricted
  key needs write access to Checkout Sessions, Products, Prices and Webhook
  Endpoints, and read and write on Subscriptions and Customers.

Saving registers Flock's own webhook endpoint in your Stripe account, so there
is nothing to set up there by hand.

Then add a membership: a name, a price, and whether it is billed monthly or
yearly. Each one creates a group on your forum, and pointing your tag or
permission settings at that group is what decides what members get. You can
point a membership at a group you already run instead.

Members find what is on sale under **Memberships** in the sidebar.

## How it behaves

- **Cancelling** keeps access until the end of the period they paid for.
- **A failed renewal** keeps access while Stripe retries the card, for a window
  you set, a week by default.
- **Changing a price** affects new members only. Everyone already subscribed
  keeps the price they signed up at, because Stripe treats a price as permanent
  once somebody is paying it.
- **A missed webhook fixes itself.** Flock re-checks a member's subscription
  with Stripe when their local record is stale or has run out, on requests they
  are already making, so a webhook lost to an outage does not mean a membership
  that never ends or one that ends wrongly. There is a button on the admin page
  to check everything at once.
- **Nothing about a person is ever changed except their group.** No account is
  suspended, no post is touched, whatever happens with a card.

## If your Flock key lapses

New memberships stop being offered. That is all that happens. Everyone already
paying you keeps their group, keeps their access, and keeps being charged by
you, because that is between you and your members. The same is true if Link
Robins is unreachable: your forum keeps selling for a week before it pauses, and
nobody loses anything at any point.

## What it does not do

- It does not take a cut, and it never handles your members' money.
- It does not ask for your Stripe secret key, and no card details touch your
  forum: payment happens on Stripe's own hosted page.
- It does not remove users or their content, ever. Only group membership.
- It does not rebuild your Stripe dashboard. Revenue questions belong there.
- It does not cap anything: as many memberships and members as you like.

## Development

```
composer install
composer test:setup
composer test
```

⚠️ The Stripe SDK ships `lib/agent_plugin_hint.php`, which writes a line to
stderr when it detects that it is running inside a coding-agent environment.
Under PHPUnit's process isolation that line lands where the test result is meant
to be, and every integration test errors with it as the message.

If you hit that, unset the variables the SDK looks for (they are named at the
top of that file) before running the suite. Unsetting is the point: emptying
them is not enough, since the check is `false !== getenv(...)` and an
empty-but-set variable still trips it.

```
env -u VARIABLE_NAME composer test:integration
```

CI is unaffected, because those variables do not exist there.
