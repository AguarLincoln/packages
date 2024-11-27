<?php

namespace Spark\Listeners;

use Laravel\Cashier\Cashier;

class RemoveSubscriptionPaymentMethod
{
    /**
     * Handle the event.
     *
     * @param  \Laravel\Cashier\Events\WebhookHandled  $event
     * @return void
     */
    public function handle($event)
    {
        if (
            ! in_array($event->payload['type'], ['customer.subscription.created', 'customer.subscription.updated']) ||
            ! isset($event->payload['data']['object']['default_payment_method'])) {
            return;
        }

        $subscription = Cashier::$subscriptionModel::where(
            'stripe_id',
            $event->payload['data']['object']['id']
        )->first();

        if ($subscription) {
            $subscription->updateStripeSubscription([
                'default_payment_method' => null,
            ]);
        }
    }
}
