import Model from 'flarum/common/Model';

/**
 * One membership on sale.
 *
 * The type string must match the API resource byte for byte, or the store
 * quietly holds nothing.
 */
export default class Plan extends Model {
  name() {
    return Model.attribute<string>('name').call(this);
  }

  description() {
    return Model.attribute<string | null>('description').call(this);
  }

  /** Minor units, the way Stripe counts. Formatting is the page's job. */
  amount() {
    return Model.attribute<number>('amount').call(this);
  }

  currency() {
    return Model.attribute<string>('currency').call(this);
  }

  interval() {
    return Model.attribute<'month' | 'year'>('interval').call(this);
  }

  isActive() {
    return Model.attribute<boolean>('isActive').call(this);
  }

  isSellable() {
    return Model.attribute<boolean>('isSellable').call(this);
  }

  groupId() {
    return Model.attribute<number | null>('groupId').call(this);
  }
}
