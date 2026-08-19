import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Modal from 'flarum/common/components/Modal';
import Stream from 'flarum/common/utils/Stream';
import Switch from 'flarum/common/components/Switch';
import type Mithril from 'mithril';
import type Plan from '../../common/models/Plan';

export interface PlanModalAttrs {
  plan: Plan | null;
  onsaved: () => void;
}

/**
 * Create or edit one plan.
 *
 * The price is entered the way an owner thinks about it and stored the way
 * Stripe counts it, so 5.00 goes in and 500 goes out. That conversion lives
 * here rather than anywhere further in, because everything past this point
 * should only ever deal in minor units.
 */
export default class PlanModal extends Modal<PlanModalAttrs & any> {
  name!: Stream<string>;
  description!: Stream<string>;
  price!: Stream<string>;
  currency!: Stream<string>;
  interval!: Stream<string>;
  isActive!: Stream<boolean>;

  oninit(vnode: Mithril.Vnode<PlanModalAttrs & any, this>) {
    super.oninit(vnode);

    const plan = this.attrs.plan;

    this.name = Stream(plan?.name() ?? '');
    this.description = Stream(plan?.description() ?? '');
    this.price = Stream(plan ? (plan.amount() / 100).toFixed(2) : '');
    this.currency = Stream(plan?.currency() ?? 'usd');
    this.interval = Stream(plan?.interval() ?? 'month');
    this.isActive = Stream(plan ? plan.isActive() : true);
  }

  className(): string {
    return 'FlockPlanModal Modal--small';
  }

  title(): Mithril.Children {
    return this.attrs.plan ? app.translator.trans('linkrobins-flock.admin.edit_plan') : app.translator.trans('linkrobins-flock.admin.add_plan');
  }

  content(): Mithril.Children {
    return m('div', { className: 'Modal-body' }, [
      this.field('name', m('input', { className: 'FormControl', bidi: this.name, required: true })),
      this.field('description', m('textarea', { className: 'FormControl', bidi: this.description, rows: 2 })),
      this.field('price', m('input', { className: 'FormControl', type: 'number', step: '0.01', min: '0.01', bidi: this.price, required: true })),
      this.field('currency', m('input', { className: 'FormControl', bidi: this.currency, maxlength: 3, required: true })),
      this.field(
        'interval',
        m('select', { className: 'FormControl', bidi: this.interval }, [
          m('option', { value: 'month' }, app.translator.trans('linkrobins-flock.admin.monthly')),
          m('option', { value: 'year' }, app.translator.trans('linkrobins-flock.admin.yearly')),
        ])
      ),
      this.attrs.plan
        ? m(
            'div',
            { className: 'Form-group' },
            m(
              Switch,
              { state: this.isActive(), onchange: (value: boolean) => this.isActive(value) },
              app.translator.trans('linkrobins-flock.admin.plan_on_sale')
            )
          )
        : null,
      // Said here rather than after the fact, because Stripe will not let it be
      // undone: an existing subscriber keeps the price they agreed to.
      this.attrs.plan ? m('p', { className: 'helpText' }, app.translator.trans('linkrobins-flock.admin.price_change_help')) : null,
      m(
        'div',
        { className: 'Form-group' },
        m(
          Button,
          { className: 'Button Button--primary', type: 'submit', loading: this.loading },
          app.translator.trans('linkrobins-flock.admin.save_plan')
        )
      ),
      this.attrs.plan ? this.deleteControl() : null,
    ]);
  }

  field(key: string, control: Mithril.Children): Mithril.Children {
    return m('div', { className: 'Form-group' }, [m('label', null, app.translator.trans('linkrobins-flock.admin.plan_' + key)), control]);
  }

  deleteControl(): Mithril.Children {
    return m(
      'div',
      { className: 'Form-group FlockPlanModal-delete' },
      m(Button, { className: 'Button Button--link', onclick: () => this.remove() }, app.translator.trans('linkrobins-flock.admin.delete_plan'))
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();

    this.loading = true;

    const attributes = {
      name: this.name(),
      description: this.description() || null,
      // Minor units from here on.
      amount: Math.round(parseFloat(this.price() || '0') * 100),
      currency: this.currency().toLowerCase(),
      interval: this.interval(),
      isActive: this.isActive(),
    };

    const plan = this.attrs.plan ?? app.store.createRecord<Plan>('flock-plans');

    plan
      .save(attributes)
      .then(() => {
        this.attrs.onsaved();
        this.hide();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /**
   * Deleting a plan stops it being sold and leaves every subscription alone:
   * Stripe keeps billing them and they keep their group until it ends there.
   */
  remove() {
    if (!confirm(app.translator.trans('linkrobins-flock.admin.delete_plan_confirm') as string)) {
      return;
    }

    this.loading = true;

    this.attrs
      .plan!.delete()
      .then(() => {
        this.attrs.onsaved();
        this.hide();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
