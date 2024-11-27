<?php

namespace Spark\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spark\Features;
use Spark\Spark;

class PaymentMethodsController
{
    use RetrievesBillableModels;

    /**
     * Setup a billing method for the billable entity.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function setup()
    {
        $billable = $this->billable();
        $billableId = $billable->getKey();
        $billableType = $billable->sparkConfiguration('type');
        $sessionOptions = [];

        if (is_callable($sessionOptionsCallback = Spark::getPaymentMethodSessionOptions($billableType))) {
            $sessionOptions = $sessionOptionsCallback($billable);
        }

        $checkout = $billable->checkout([], array_merge($sessionOptions, array_filter([
            'mode' => 'setup',
            'payment_method_types' => ['card'],
            'billing_address_collection' => Features::collectsBillingAddress() ? 'required' : null,
            'success_url' => route('spark.portal', [$billableType, $billableId]).'?checkout=payment_method_added',
            'cancel_url' => route('spark.portal', [$billableType, $billableId]).'?checkout=cancelled',
        ])));

        return response()->json([
            'redirect' => $checkout->url,
        ]);
    }

    /**
     * Set the default billing method for the billable entity.
     *
     * @return void
     */
    public function default(Request $request)
    {
        $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        $this->billable()->updateDefaultPaymentMethod($request->payment_method);
    }

    /**
     * Delete a billing method of the billable entity.
     *
     * @return void
     */
    public function delete(Request $request)
    {
        $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        $billable = $this->billable();

        $defaultPaymentMethod = $billable->defaultPaymentMethod();

        if ($defaultPaymentMethod && $defaultPaymentMethod->id === $request->payment_method) {
            throw ValidationException::withMessages([
                '*' => __('The default payment method cannot be removed.'),
            ]);
        }

        $billable->deletePaymentMethod($request->payment_method);
    }
}
