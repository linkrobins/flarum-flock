import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Modal from 'flarum/common/components/Modal';
import Select from 'flarum/common/components/Select';
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
    // Core's own form container rather than hand-rolled margins: it is what
    // gives every other Flarum form its spacing, and it stretches its children
    // to full width, which is where the save button gets its size.
    return m(
      'div',
      { className: 'Modal-body' },
      m('div', { className: 'Form' }, [
        this.field('name', m('input', { className: 'FormControl', bidi: this.name, required: true })),

        this.field('description', m('textarea', { className: 'FormControl', bidi: this.description, rows: 2 })),

        this.field(
          'price',
          m('input', {
            className: 'FormControl',
            type: 'number',
            step: '0.01',
            min: '0.01',
            // A phone should offer digits and a decimal point for a price, not a
            // full keyboard.
            inputmode: 'decimal',
            bidi: this.price,
            required: true,
          }),
          // Under the price, where it is about to matter, rather than floating
          // above the save button: Stripe will not let a price change be undone,
          // and an existing subscriber keeps the one they agreed to.
          this.attrs.plan ? app.translator.trans('linkrobins-flock.admin.price_change_help') : null
        ),

        this.field(
          'currency',
          m('input', {
            className: 'FormControl',
            bidi: this.currency,
            maxlength: 3,
            autocapitalize: 'off',
            autocorrect: 'off',
            spellcheck: false,
            required: true,
          }),
          app.translator.trans('linkrobins-flock.admin.currency_help')
        ),

        // Core's Select rather than a bare <select>: it turns off the native
        // appearance, which is what stops iOS clipping the chosen option inside a
        // fixed-height control.
        this.field(
          'interval',
          Select.component({
            options: {
              month: app.translator.trans('linkrobins-flock.admin.monthly'),
              year: app.translator.trans('linkrobins-flock.admin.yearly'),
            },
            value: this.interval(),
            onchange: (value: string) => this.interval(value),
          })
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
      ])
    );
  }

  field(key: string, control: Mithril.Children, help: Mithril.Children = null): Mithril.Children {
    return m('div', { className: 'Form-group' }, [
      m('label', null, app.translator.trans('linkrobins-flock.admin.plan_' + key)),
      control,
      help ? m('p', { className: 'helpText' }, help) : null,
    ]);
  }

  /**
   * Kept away from Save, and looking like what it is.
   *
   * It sits below a rule rather than beside the button somebody presses every
   * time they edit a price: the two actions are not in the same league.
   */
  deleteControl(): Mithril.Children {
    return m(
      'div',
      { className: 'FlockPlanModal-delete' },
      m(
        Button,
        { className: 'Button Button--text FlockPlanModal-deleteButton', icon: 'fas fa-trash-can', onclick: () => this.remove() },
        app.translator.trans('linkrobins-flock.admin.delete_plan')
      )
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
