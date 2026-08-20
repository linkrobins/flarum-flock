import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import FlockStatus from './FlockStatus';
import PlanModal from './PlanModal';
import type Mithril from 'mithril';
import type Plan from '../../common/models/Plan';

/**
 * Everything an owner sets up, on one page.
 *
 * A custom page rather than the generated settings list, because plans are
 * records to create and edit rather than key-value fields, and because the
 * order matters: the key's standing first, since it is the thing most likely to
 * be wrong, then the two keys, then what is on sale.
 */
export default class FlockPage extends ExtensionPage {
  plans: Plan[] = [];

  loadingPlans = true;

  syncing = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.loadPlans();
  }

  content() {
    return m('div', { className: 'ExtensionPage-settings FlockAdmin' }, [
      m('div', { className: 'container' }, [m(FlockStatus), this.keys(), this.plansSection()]),
    ]);
  }

  keys(): Mithril.Children {
    return m('section', { className: 'FlockAdmin-keys' }, [
      this.buildSettingComponent({
        setting: 'linkrobins-flock.key',
        type: 'text',
        label: app.translator.trans('linkrobins-flock.admin.key_label'),
        help: app.translator.trans('linkrobins-flock.admin.key_help'),
      }),
      this.buildSettingComponent({
        setting: 'linkrobins-flock.stripe_key',
        type: 'text',
        label: app.translator.trans('linkrobins-flock.admin.stripe_key_label'),
        help: app.translator.trans('linkrobins-flock.admin.stripe_key_help'),
      }),
      this.buildSettingComponent({
        setting: 'linkrobins-flock.grace_days',
        type: 'number',
        default: 7,
        label: app.translator.trans('linkrobins-flock.admin.grace_days_label'),
        help: app.translator.trans('linkrobins-flock.admin.grace_days_help'),
      }),
      this.submitButton(),
    ]);
  }

  plansSection(): Mithril.Children {
    return m('section', { className: 'FlockAdmin-plans' }, [
      m('h3', null, app.translator.trans('linkrobins-flock.admin.plans_heading')),
      m('p', { className: 'helpText' }, app.translator.trans('linkrobins-flock.admin.plans_help')),
      this.loadingPlans ? m(LoadingIndicator, { display: 'block' }) : this.planList(),
      m('div', { className: 'FlockAdmin-planControls' }, [
        m(
          Button,
          { className: 'Button', icon: 'fas fa-plus', onclick: () => this.edit(null) },
          app.translator.trans('linkrobins-flock.admin.add_plan')
        ),
        m(
          Button,
          { className: 'Button Button--link', loading: this.syncing, onclick: () => this.sync() },
          app.translator.trans('linkrobins-flock.admin.sync_now')
        ),
      ]),
    ]);
  }

  planList(): Mithril.Children {
    if (!this.plans.length) {
      return m('p', { className: 'FlockAdmin-empty' }, app.translator.trans('linkrobins-flock.admin.no_plans'));
    }

    return m(
      'ul',
      { className: 'FlockAdmin-planList' },
      this.plans.map((plan) =>
        m('li', { className: 'FlockAdmin-plan' }, [
          m('span', { className: 'FlockAdmin-planName' }, plan.name()),
          m('span', { className: 'FlockAdmin-planPrice' }, this.price(plan)),
          // A plan Stripe has not accepted cannot be bought, and saying so here
          // is kinder than an owner finding out from a member.
          plan.isSellable()
            ? null
            : m('span', { className: 'FlockAdmin-planWarning' }, app.translator.trans('linkrobins-flock.admin.plan_not_ready')),
          plan.isActive() ? null : m('span', { className: 'FlockAdmin-planRetired' }, app.translator.trans('linkrobins-flock.admin.plan_retired')),
          m(Button, { className: 'Button Button--link', icon: 'fas fa-pencil', onclick: () => this.edit(plan) }),
        ])
      )
    );
  }

  price(plan: Plan): string {
    const currency = (plan.currency() || 'usd').toUpperCase();
    const interval = app.translator.trans('linkrobins-flock.lib.per_' + plan.interval());

    try {
      return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(plan.amount() / 100) + ' ' + interval;
    } catch {
      return (plan.amount() / 100).toFixed(2) + ' ' + currency + ' ' + interval;
    }
  }

  edit(plan: Plan | null) {
    app.modal.show(PlanModal, { plan, onsaved: () => this.loadPlans() });
  }

  loadPlans() {
    this.loadingPlans = true;

    app.store
      .find<Plan[]>('flock-plans')
      .then((plans) => {
        this.plans = plans;
        this.loadingPlans = false;
        m.redraw();
      })
      .catch(() => {
        this.loadingPlans = false;
        m.redraw();
      });
  }

  /** Ask Stripe about every subscription now, rather than waiting for the lazy path. */
  sync() {
    this.syncing = true;
    m.redraw();

    app
      .request<{ checked: number }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/sync',
      })
      .then((response) => {
        app.alerts.show({ type: 'success' }, app.translator.trans('linkrobins-flock.admin.synced', { count: response.checked }));
      })
      .finally(() => {
        this.syncing = false;
        m.redraw();
      });
  }
}
