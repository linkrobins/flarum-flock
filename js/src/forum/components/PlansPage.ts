import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import IndexPage from 'flarum/forum/components/IndexPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Page from 'flarum/common/components/Page';
import type Mithril from 'mithril';
import type Plan from '../../common/models/Plan';

/**
 * The Join page: what is on sale, and one button per plan.
 *
 * It shows a member what they already have rather than offering it again,
 * because the alternative is somebody paying twice for the same group and the
 * owner handling the refund.
 */
export default class PlansPage extends Page {
  plans: Plan[] = [];

  loading = true;

  /** The plan whose button is waiting on Stripe, so only that one spins. */
  starting: number | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    app.store
      .find<Plan[]>('flock-plans')
      .then((plans) => {
        this.plans = plans;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return m('div', { className: 'FlockPlansPage' }, [
      IndexPage.prototype.hero.call(this),
      m('div', { className: 'container' }, this.loading ? m(LoadingIndicator, { display: 'block' }) : this.content()),
    ]);
  }

  content(): Mithril.Children {
    const sellable = this.plans.filter((plan) => plan.isSellable());

    if (!sellable.length) {
      return m('p', { className: 'FlockPlans-empty' }, app.translator.trans('linkrobins-flock.forum.no_plans'));
    }

    return m(
      'ul',
      { className: 'FlockPlans' },
      sellable.map((plan) => this.plan(plan))
    );
  }

  plan(plan: Plan): Mithril.Children {
    const held = this.holds(plan);

    return m('li', { className: 'FlockPlan' }, [
      m('h3', { className: 'FlockPlan-name' }, plan.name()),
      m('div', { className: 'FlockPlan-price' }, [
        m('span', { className: 'FlockPlan-amount' }, this.price(plan)),
        m('span', { className: 'FlockPlan-interval' }, app.translator.trans('linkrobins-flock.lib.per_' + plan.interval())),
      ]),
      plan.description() ? m('p', { className: 'FlockPlan-description' }, plan.description()) : null,
      held
        ? m('span', { className: 'FlockPlan-held' }, app.translator.trans('linkrobins-flock.forum.you_have_this'))
        : m(
            Button,
            {
              className: 'Button Button--primary FlockPlan-join',
              loading: this.starting === Number(plan.id()),
              disabled: !app.forum.attribute('flockSelling'),
              onclick: () => this.join(plan),
            },
            app.translator.trans('linkrobins-flock.forum.join')
          ),
    ]);
  }

  /** Whether this member is already entitled to the plan's group. */
  holds(plan: Plan): boolean {
    const ids = (app.forum.attribute<number[]>('flockPlanIds') || []).map(Number);

    return ids.includes(Number(plan.id()));
  }

  price(plan: Plan): string {
    const currency = (plan.currency() || 'usd').toUpperCase();

    try {
      return new Intl.NumberFormat(document.documentElement.lang || 'en', {
        style: 'currency',
        currency,
      }).format(plan.amount() / 100);
    } catch {
      // An unknown currency code should cost a nice symbol, not the page.
      return (plan.amount() / 100).toFixed(2) + ' ' + currency;
    }
  }

  join(plan: Plan) {
    if (!app.session.user) {
      app.modal.show(() => import('flarum/forum/components/LogInModal'));

      return;
    }

    this.starting = Number(plan.id());
    m.redraw();

    app
      .request<{ url: string }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/checkout',
        body: { planId: plan.id() },
      })
      .then((response) => {
        // Stripe's hosted page from here on, which is the point: no card
        // details ever touch this forum.
        window.location.assign(response.url);
      })
      .catch(() => {
        this.starting = null;
        m.redraw();
      });
  }
}
