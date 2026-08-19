import app from 'flarum/admin/app';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

interface Standing {
  status: string;
  canSellNew: boolean;
  boundTo: string;
  checkedAt: number | null;
  graceEndsAt: number | null;
}

/**
 * What the key is doing, answered by the server rather than guessed here.
 *
 * The wording matters more than usual on this page. Most of these states do not
 * affect a single member: an owner whose key lapsed still has members paying
 * them, and telling them otherwise would be both wrong and alarming.
 */
export default class FlockStatus extends Component {
  standing: Standing | null = null;

  loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    this.load();
  }

  view() {
    if (this.loading) {
      return m('div', { className: 'FlockStatus' }, m(LoadingIndicator, { display: 'inline' }));
    }

    if (!this.standing) {
      return null;
    }

    return m('div', { className: 'FlockStatus FlockStatus--' + this.standing.status }, m('p', null, this.message()), this.retry());
  }

  message(): Mithril.Children {
    const standing = this.standing!;
    const key = 'linkrobins-flock.admin.';

    switch (standing.status) {
      case 'active':
        return standing.boundTo ? app.translator.trans(key + 'active_bound', { forum: standing.boundTo }) : app.translator.trans(key + 'active');

      case 'unreachable':
        // Two very different sentences behind one status: still selling, or
        // not selling any more. The server has already worked out which.
        return standing.canSellNew
          ? app.translator.trans(key + 'unreachable', { date: this.graceDate() })
          : app.translator.trans(key + 'unreachable_expired');

      case 'bound_elsewhere':
        return app.translator.trans(key + 'bound_elsewhere', { forum: standing.boundTo });

      default:
        return app.translator.trans(key + standing.status);
    }
  }

  retry(): Mithril.Children {
    if (this.standing?.status === 'active' || this.standing?.status === 'unconfigured') {
      return null;
    }

    return m(
      Button,
      {
        className: 'Button Button--link',
        loading: this.loading,
        onclick: () => this.recheck(),
      },
      app.translator.trans('linkrobins-flock.admin.check_again')
    );
  }

  graceDate(): string {
    const at = this.standing?.graceEndsAt;

    return at ? new Date(at * 1000).toLocaleDateString() : '';
  }

  load() {
    app
      .request<Standing>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/status',
      })
      .then((standing) => {
        this.standing = standing;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  recheck() {
    this.loading = true;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/linkrobins-flock/recheck',
      })
      .then(() => this.load())
      .catch(() => this.load());
  }
}
