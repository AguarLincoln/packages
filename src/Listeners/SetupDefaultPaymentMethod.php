<?php

namespace Spark\Listeners;

use Laravel\Cashier\Cashier;

/**
 * @todo Refactor to use "payment_method.attached" webhook event instead.
 */
class SetupDefaultPaymentMethod
{
    /**
     * Handle the event.
     *
     * @param  \Laravel\Cashier\Events\WebhookHandled  $event
     * @return void
     */
    public function handle($event)
    {
        if (! in_array($event->payload['type'], ['customer.subscription.created', 'customer.subscription.updated'])) {
            return;
        }

        if ($billable = Cashier::findBillable($event->payload['data']['object']['customer'])) {
            $defaultPaymentMethod = $billable->defaultPaymentMethod();

            if (is_null($defaultPaymentMethod) && ! is_null($paymentMethod = $billable->paymentMethods()->first())) {
                $billable->updateDefaultPaymentMethod(
                    $paymentMethod->asStripePaymentMethod()
                );
            }
        }
    }
}
