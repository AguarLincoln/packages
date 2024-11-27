<?php

namespace Spark\Http\Controllers;

use Illuminate\Http\Request;
use Spark\Contracts\Actions\CreatesSubscriptions;
use Spark\Features;
use Spark\Spark;
use Spark\ValidCountry;
use Spark\ValidPlan;
use Spark\ValidVatNumber;

class NewSubscriptionController
{
    use RetrievesBillableModels;

    /**
     * Create a new subscription.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        $billable = $this->billable();

        $this->validate($request);

        Spark::ensurePlanEligibility(
            $billable,
            Spark::plans($billable->sparkConfiguration('type'))
                ->where('id', $request->plan)
                ->first()
        );

        if (! $request->boolean('direct')) {
            $this->updateBillable($request, $billable);
        }

        $checkout = app(CreatesSubscriptions::class)->create($billable, $request->plan);

        return response()->json([
            'redirect' => $checkout->url,
        ]);
    }

    /**
     * Update the billable from the request.
     *
     * @param  \Spark\Billable  $billable
     * @return void
     */
    private function updateBillable(Request $request, $billable)
    {
        $billable->forceFill($request->only([
            'extra_billing_information',
            'billing_address',
            'billing_address_line_2',
            'billing_city',
            'billing_state',
            'billing_postal_code',
            'billing_country',
            'vat_id',
        ]))->save();
    }

    /**
     * Validate the incoming request.
     */
    protected function validate(Request $request)
    {
        $addressRule = (! $request->boolean('direct') &&
            Features::collectsBillingAddress() &&
            (bool) Features::option('billing-address-collection', 'required')) ? 'required' : 'nullable';

        $countryRule = (! $request->boolean('direct') &&
             Features::collectsBillingAddress()) ? 'required' : 'nullable';

        $request->validate([
            'plan' => ['required', new ValidPlan($request->billableType)],
            'extra_billing_information' => 'max:2048',
            'billing_address' => [$addressRule, 'max:225'],
            'billing_address_line_2' => ['nullable', 'max:225'],
            'billing_city' => [$addressRule, 'max:225'],
            'billing_state' => [$addressRule, 'max:225'],
            'billing_postal_code' => [$addressRule, 'max:225'],
            'billing_country' => [$countryRule, 'max:2', new ValidCountry()],
            'vat_id' => ['nullable', 'max:225', new ValidVatNumber()],
        ]);
    }
}
