import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Plan from './common/models/Plan';
import FlockPage from './admin/components/FlockPage';

app.initializers.add('linkrobins/flock', () => {
  app.registry.for('linkrobins-flock').registerPage(FlockPage);
});

export default [new Extend.Store().add('flock-plans', Plan)];
