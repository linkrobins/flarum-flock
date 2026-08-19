# Flock

Paid memberships for Flarum. Your Stripe, your money, your groups.

A member subscribes through Stripe Checkout, and their subscription drives a
Flarum **group**. You scope whatever you like to that group using Flarum's own
permission screens: a private tag, posting rights, an upload limit, anything
that takes a group. Flock never touches tag logic, which is why it composes
with the permission extensions you already run.

Your members pay **you**. The money moves between their card and your own
Stripe account, and never passes through Link Robins.

## What you need

- Flarum 2.0
- A Stripe account
- A Flock key from [linkrobins.com/flock](https://linkrobins.com/flock)

## Setting it up

```
composer require linkrobins/flarum-flock
```

Then enable Flock in the admin panel, open its settings, and fill in two
fields: your Flock key, and a Stripe **restricted** key from your own Stripe
dashboard (Developers, API keys, Create restricted key). Never paste your
secret key. Flock creates its own webhook endpoint in your Stripe account when
you save, so there is nothing to configure there by hand.

## If your key lapses

New memberships stop being offered. That is all that happens. Everyone already
paying you keeps their group, keeps their access, and keeps being charged by
you, because that is a relationship between you and your members that has
nothing to do with us. The same is true if Link Robins is unreachable: your
forum carries on selling for a week before it pauses, and nobody loses
anything at any point.

## What it does not do

- It does not take a cut, and it never handles your members' money.
- It does not ask for your Stripe secret key.
- It does not remove users or their content, ever. Only group membership.
- It does not rebuild your Stripe dashboard. Revenue questions belong there.
