<?php

namespace Spark\Actions;

use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Payment;
use Spark\Concerns\HandlesPaymentFailures;
use Spark\Contracts\Actions\PaysInvoices;
use Spark\Events\AttemptingPayment;
use Spark\Events\PaymentAttempted;
use Stripe\Exception\CardException;

class PayInvoice implements PaysInvoices
{
    use HandlesPaymentFailures;

    /**
     * Pay the invoice.
     *
     * @param  \Spark\Billable  $billable
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Laravel\Cashier\Exceptions\IncompletePayment
     */
    public function pay($billable, Invoice $invoice)
    {
        if (! $invoice->isOpen()) {
            throw ValidationException::withMessages([
                '*' => __('This invoice is no longer open.'),
            ]);
        }

        event(new AttemptingPayment($billable, $invoice));

        $invoice = $this->attemptPayment(function () use ($billable, $invoice) {
            try {
                return $invoice->pay();
            } catch (CardException $e) {
                $payment = new Payment(
                    $billable->stripe()->paymentIntents->retrieve(
                        $invoice->asStripeInvoice()->refresh()->payment_intent,
                        ['expand' => ['invoice.subscription']]
                    )
                );

                $payment->validate();
            }
        });

        event(new PaymentAttempted($billable, $invoice));
    }
}
