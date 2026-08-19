import app from 'flarum/admin/app';
import FlockStatus from './admin/components/FlockStatus';

app.initializers.add('linkrobins/flock', () => {
  app.registry
    .for('linkrobins-flock')
    // Above the fields: the first thing an owner wants to know on this page is
    // whether the key they pasted works.
    .registerSetting(() => m(FlockStatus), 100, 'status')
    .registerSetting(
      {
        setting: 'linkrobins-flock.key',
        type: 'text',
        label: app.translator.trans('linkrobins-flock.admin.key_label'),
        help: app.translator.trans('linkrobins-flock.admin.key_help'),
      },
      90
    )
    .registerSetting(
      {
        setting: 'linkrobins-flock.stripe_key',
        type: 'text',
        label: app.translator.trans('linkrobins-flock.admin.stripe_key_label'),
        help: app.translator.trans('linkrobins-flock.admin.stripe_key_help'),
      },
      80
    )
    .registerSetting(
      {
        setting: 'linkrobins-flock.grace_days',
        type: 'number',
        default: 7,
        label: app.translator.trans('linkrobins-flock.admin.grace_days_label'),
        help: app.translator.trans('linkrobins-flock.admin.grace_days_help'),
      },
      70
    );
});
