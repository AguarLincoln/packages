<?php

namespace Spark\Http\Controllers;

use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierController;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Payment;
use Spark\Events\PaymentSucceeded;
use Spark\Events\SubscriptionCancelled;
use Spark\Events\SubscriptionCreated;
use Spark\Events\SubscriptionUpdated;
use Spark\Features;
use Spark\Mail\ConfirmPayment;
use Spark\Mail\NewInvoice;
use Stripe\Subscription;

class WebhookController extends CashierController
{
    /**
     * {@inheritDoc}
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        if ($billable = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            $subscription = $billable->subscriptions()->where('stripe_id', $payload['data']['object']['id'])->first();

            if ($subscription) {
                $oldStatus = $subscription->stripe_status;

                $newStatus = $payload['data']['object']['status'] ?? null;

                parent::handleCustomerSubscriptionUpdated($payload);

                if ($newStatus &&
                    $newStatus == Subscription::STATUS_ACTIVE &&
                    ! in_array($oldStatus, [Subscription::STATUS_ACTIVE, Subscription::STATUS_TRIALING])) {
                    event(new SubscriptionCreated($billable, $subscription->refresh()));
                } else {
                    event(new SubscriptionUpdated($billable, $subscription->refresh()));
                }
            } else {
                parent::handleCustomerSubscriptionUpdated($payload);
            }
        }

        return $this->successMethod();
    }

    /**
     * {@inheritDoc}
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        if ($billable = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            parent::handleCustomerSubscriptionDeleted($payload);

            $subscription = $billable->subscriptions()->where('stripe_id', $payload['data']['object']['id'])->first();

            if ($subscription) {
                event(new SubscriptionCancelled($billable, $subscription));

                if (config('spark.void_cancelled_subscription_invoices', false)) {
                    $subscription->invoicesIncludingPending()
                        ->where(fn (Invoice $invoice) => $invoice->isOpen())
                        ->each
                        ->void();
                }
            }
        }

        return $this->successMethod();
    }

    /**
     * {@inheritDoc}
     */
    protected function handleCustomerDeleted(array $payload)
    {
        if ($billable = $this->getUserByStripeId($payload['data']['object']['id'])) {
            parent::handleCustomerDeleted($payload);

            $subscription = $billable->subscriptions()->where('stripe_id', $payload['data']['object']['id'])->first();

            event(new SubscriptionCancelled($billable, $subscription));
        }

        return $this->successMethod();
    }

    /**
     * Handle a successful invoice payment event.
     *
     * @return \Illuminate\Http\Response
     */
    protected function handleInvoicePaymentSucceeded(array $payload)
    {
        if ($billable = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if ($invoice = $billable->findInvoice($payload['data']['object']['id'])) {
                $this->sendInvoiceNotification($billable, $invoice);

                event(new PaymentSucceeded($billable, $invoice));
            }
        }

        return $this->successMethod();
    }

    /**
     * Handle payment action required for invoice.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleInvoicePaymentActionRequired(array $payload)
    {
        if ($payload['data']['object']['metadata']['is_on_session_checkout'] ?? false) {
            return $this->successMethod();
        }

        if ($payload['data']['object']['subscription_details']['metadata']['is_on_session_checkout'] ?? false) {
            return $this->successMethod();
        }

        if ($billable = $this->getUserByStripeId($payload['data']['object']['customer'])) {
            if (in_array(Notifiable::class, class_uses_recursive($billable))) {
                $payment = new Payment($billable->stripe()->paymentIntents->retrieve(
                    $payload['data']['object']['payment_intent']
                ));

                $this->sendPaymentConfirmationNotification($billable, $payment);
            }
        }

        return $this->successMethod();
    }

    /**
     * Send the invoice notification email.
     *
     * @param  \Spark\Billable  $billable
     * @param  \Laravel\Cashier\Invoice|null  $invoice
     * @return void
     */
    protected function sendInvoiceNotification($billable, $invoice)
    {
        if (! config('spark.sends_receipt_emails') && ! Features::sendsInvoiceEmails()) {
            return;
        }

        $mails = Features::optionEnabled('invoice-emails-sending', 'custom-addresses')
            ? $billable->invoice_emails : [];

        if (empty($mails)) {
            $mails = [$billable->stripeEmail()];
        }

        Mail::to($mails)->send(
            new NewInvoice($billable, $invoice)
        );
    }

    /**
     * Send the payment confirmation notification email.
     *
     * @param  \Spark\Billable  $billable
     * @param  \Laravel\Cashier\Payment  $payment
     * @return void
     */
    protected function sendPaymentConfirmationNotification($billable, $payment)
    {
        if (! config('spark.sends_payment_notification_emails') &&
            ! Features::sendsPaymentNotificationEmails()) {
            return;
        }

        Mail::to($billable->stripeEmail())->send(
            new ConfirmPayment($payment)
        );
    }
}
