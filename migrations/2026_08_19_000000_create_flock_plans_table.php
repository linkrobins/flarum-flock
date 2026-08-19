<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('flock_plans', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name');
    $table->text('description')->nullable();

    // The access primitive. One plan grants one group and nothing else, which
    // is what lets this compose with every permission extension already
    // installed: the owner points their own tag permissions at the group.
    $table->unsignedInteger('group_id')->nullable();

    // Minor units, as Stripe counts them, so nothing here ever holds a float.
    $table->unsignedInteger('amount');
    $table->string('currency', 3);
    $table->string('interval', 5);

    // Filled in once the plan exists in the owner's Stripe account. A plan
    // without them has not been pushed yet and cannot be sold.
    $table->string('stripe_product_id')->nullable();
    $table->string('stripe_price_id')->nullable();

    $table->boolean('is_active')->default(true);
    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();
});
