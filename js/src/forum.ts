import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Extend from 'flarum/common/extenders';
import LinkButton from 'flarum/common/components/LinkButton';
import Plan from './common/models/Plan';
import PlansPage from './forum/components/PlansPage';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

app.initializers.add('linkrobins/flock', () => {
  // By path, so IndexPage's chunk is not pulled in eagerly just to add a link
  // to the sidebar.
  extend('flarum/forum/components/IndexPage', 'navItems', function (items: ItemList<Mithril.Children>) {
    // Nothing to join is nothing to advertise, and a forum whose owner has not
    // set Flock up should look exactly as it did before installing it.
    if (!app.forum.attribute('flockSelling')) {
      return;
    }

    items.add(
      'flock',
      LinkButton.component({ href: app.route('flock.plans'), icon: 'fas fa-feather' }, app.translator.trans('linkrobins-flock.forum.nav')),
      -10
    );
  });
});

export default [new Extend.Store().add('flock-plans', Plan), new Extend.Routes().add('flock.plans', '/flock/plans', PlansPage)];
