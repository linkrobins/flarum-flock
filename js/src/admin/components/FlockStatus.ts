import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import type Mithril from 'mithril';

interface Standing {
  status: string;
  canSellNew: boolean;
  boundTo: string;
  checkedAt: number | null;
  graceEndsAt: number | null;
}

/**
 * Whether this forum's Flock key works.
 *
 * The same banner Warble, Chirp and Forage show: one Alert at the top of the
 * settings page, plain language, the tick inside the translated string rather
 * than an icon of its own, and only the three Alert styles Flarum ships. No
 * stylesheet, because the family does not have one.
 *
 * It draws immediately from the status recorded at the last save, then replaces
 * that with the endpoint's answer, which knows two things the stored value
 * cannot: whether selling is still allowed, and how long that lasts if we are
 * the thing that is broken. Nothing is drawn until there is something true to
 * say, rather than guessing and correcting itself a moment later.
 */
export default class FlockStatus extends Component {
  standing: Standing | null = null;

  rechecking = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.load();
  }

  view() {
    const status = this.status();

    if (!status) {
      return null;
    }

    return m(
      'div',
      { className: this.alertClass(status), style: 'margin-bottom:16px;' },
      this.message(status),
      // Only the states that come good on their own are worth a button. A
      // lapsed subscription is not fixed by pressing anything here.
      status === 'incomplete' || status === 'unreachable'
        ? m(
            'div',
            { style: 'margin-top:10px;' },
            Button.component({ className: 'Button', loading: this.rechecking, onclick: () => this.recheck() }, this.trans('check_again'))
          )
        : null
    );
  }

  /** The live answer if it has arrived, otherwise what the last save recorded. */
  status(): string | null {
    if (this.standing) {
      return this.standing.status;
    }

    const stored = app.data.settings?.['linkrobins-flock.status'];

    return typeof stored === 'string' && stored !== '' ? stored : null;
  }

  alertClass(status: string): string {
    switch (status) {
      case 'active':
        return 'Alert Alert--success';

      case 'canceled':
      case 'invalid_key':
      case 'bound_elsewhere':
        return 'Alert Alert--error';

      case 'unreachable':
        // Us being unreachable is only a fault once it has gone on long enough
        // to stop the owner selling. Until then nothing is wrong for anybody.
        return this.standing && !this.standing.canSellNew ? 'Alert Alert--error' : 'Alert';

      // Nothing pasted yet, or a subscription still being set up: neither is
      // anything wrong.
      default:
        return 'Alert';
    }
  }

  message(status: string): Mithril.Children {
    if (status === 'active') {
      return this.standing?.boundTo ? this.trans('active_bound', { forum: this.standing.boundTo }) : this.trans('active');
    }

    if (status === 'unreachable') {
      return this.standing && !this.standing.canSellNew ? this.trans('unreachable_expired') : this.trans('unreachable', { date: this.graceDate() });
    }

    if (status === 'bound_elsewhere') {
      return this.trans('bound_elsewhere', { forum: this.standing?.boundTo ?? '' });
    }

    return this.trans(status);
  }

  graceDate(): string {
    const at = this.standing?.graceEndsAt;

    return at ? new Date(at * 1000).toLocaleDateString() : '';
  }

  trans(key: string, params: Record<string, unknown> = {}) {
    return app.translator.trans('linkrobins-flock.admin.' + key, params);
  }

  load() {
    app
      .request<Standing>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/status',
      })
      .then((standing) => {
        this.standing = standing;
        m.redraw();
      })
      .catch(() => {
        // Leave whatever the last save recorded on screen: a settings page that
        // cannot reach its own forum is not evidence about the key.
      });
  }

  recheck() {
    this.rechecking = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/recheck',
      })
      .then(() => this.load())
      .catch(() => {})
      .finally(() => {
        this.rechecking = false;
        m.redraw();
      });
  }
}
