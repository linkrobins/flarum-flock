<?php

/*
 * This file is part of linkrobins/flarum-flock.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('flock_subscriptions', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id');
    $table->unsignedInteger('plan_id')->nullable();

    // Stripe's id for the subscription, and the reason nothing here can be
    // written twice: the return from checkout and the webhook both arrive with
    // it, and whichever is second must find the row already there.
    $table->string('stripe_subscription_id')->unique();
    $table->string('stripe_customer_id')->nullable();

    $table->string('status', 30);

    // When access lapses if nothing else changes: the end of what they paid
    // for, or the end of the grace a failed payment bought them.
    $table->timestamp('access_until')->nullable();

    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();

    $table->index('user_id');
});
