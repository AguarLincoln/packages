<?php

namespace Spark;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentMethod;
use RuntimeException;
use Stripe\Subscription as StripeSubscription;

class FrontendState
{
    /**
     * Get the data should be shared with the frontend.
     *
     * @param  string  $type
     * @param  \Spark\Billable  $billable
     * @return array
     */
    public function current($type, $billable)
    {
        /** @var \Laravel\Cashier\Subscription|null */
        $subscription = $billable->subscription();

        // Filter out incomplete subscriptions for now...
        if ($subscription && $subscription->incomplete()) {
            $subscription = null;
        }

        $plans = static::getPlans($type, $billable);

        $plan = $subscription && ($subscription->active() || $subscription->pastDue())
                    ? $plans->firstWhere('id', $subscription->stripe_price)
                    : null;

        $user = Auth::user();

        return [
            'appLogo' => $this->logo(),
            'appName' => config('app.name', 'Laravel'),

            'balance' => Inertia::lazy(function () use ($billable) {
                $rawBalance = $billable->rawBalance();

                return [
                    'formatted' => ltrim(Cashier::formatAmount($rawBalance, $billable->preferredCurrency()), '-'),
                    'raw' => $rawBalance,
                ];
            }),

            'invoices' => Inertia::lazy(fn () => [
                'open' => $this->openInvoices($billable),
                'paid' => $this->paidInvoices($billable),
            ]),

            'billable' => $billable->toArray(),
            'billableId' => (string) $billable->id,
            'billableName' => $billable->name,
            'billableType' => $type,
            'billingAddressRequired' => Features::collectsBillingAddress() && (bool) Features::option('billing-address-collection', 'required'),
            'brandColor' => $this->brandColor(),
            'pmType' => $billable->pm_type,
            'pmExpirationDate' => $billable->pm_expiration,
            'pmLastFour' => $billable->pm_last_four,
            'cashierPath' => config('cashier.path'),
            'collectionMethod' => fn () => $subscription ? $subscription->asStripeSubscription()->collection_method : null,
            'collectsVat' => Features::collectsEuVat(),
            'collectsBillingAddress' => Features::collectsBillingAddress(),
            'countries' => Countries::all(),
            'dashboardUrl' => $this->dashboardUrl(),
            'defaultInterval' => config('spark.billables.'.$type.'.default_interval', 'monthly'),
            'genericTrialEndsAt' => $billable->onGenericTrial() ? $billable->genericTrialEndsAt()->translatedFormat(config('spark.date_format', 'F j, Y')) : null,
            'lastPayment' => function () use ($subscription) {
                $latestInvoice = $subscription ? $subscription->latestInvoice() : null;

                return $latestInvoice ?
                    ['amount' => $latestInvoice->realTotal(), 'date' => $latestInvoice->date()->translatedFormat(config('spark.date_format', 'F j, Y'))]
                    : null;
            },
            'message' => request('message', ''),
            'monthlyPlans' => $plans->where('interval', 'monthly')->where('active', true)->values(),
            'nextPayment' => function () use ($subscription) {
                $upcomingInvoice = $subscription ? $subscription->upcomingInvoice() : null;

                return $upcomingInvoice ?
                    ['amount' => $upcomingInvoice->amountDue(), 'date' => $upcomingInvoice->date()->translatedFormat(config('spark.date_format', 'F j, Y'))]
                    : null;
            },
            'paymentMethod' => $billable->pm_last_four ? 'card' : null,
            'paymentMethods' => fn () => $this->paymentMethods($billable),
            'plan' => $plan,
            'seatName' => Spark::seatName($type),
            'sendsInvoicesToCustomAddresses' => Features::optionEnabled('invoice-emails-sending', 'custom-addresses'),
            'sparkPath' => config('spark.path'),
            'state' => $this->state($billable, $subscription),
            'stripeKey' => config('cashier.key'),
            'stripeVersion' => Cashier::STRIPE_VERSION,
            'termsUrl' => $this->termsUrl(),
            'trialEndsAt' => $subscription && $subscription->onTrial() ? $subscription->trial_ends_at->translatedFormat(config('spark.date_format', 'F j, Y')) : null,
            'userAvatar' => isset($user['profile_photo_url']) ? $user->profile_photo_url : null,
            'userName' => $user->name,
            'yearlyPlans' => $plans->where('interval', 'yearly')->where('active', true)->values(),
        ];
    }

    /**
     * Get the logo that is configured for the billing portal.
     *
     * @return string|null
     */
    protected function logo()
    {
        $logo = config('spark.brand.logo');

        if (! empty($logo) && file_exists(realpath($logo))) {
            return file_get_contents(realpath($logo));
        }

        return $logo;
    }

    /**
     * Get the brand color for the application.
     *
     * @return string
     */
    protected function brandColor()
    {
        $color = config('spark.brand.color', 'bg-gray-800');

        return strpos($color, '#') === 0 ? 'bg-custom-hex' : $color;
    }

    /**
     * Get the subscription plans.
     *
     * @param  string  $type
     * @param  \Spark\Billable  $billable
     * @return \Illuminate\Support\Collection
     */
    protected function getPlans($type, $billable)
    {
        $plans = Spark::plans($type);

        $prices = collect($billable->stripe()->prices->all(['limit' => 100])->autoPagingIterator());

        return $plans->map(function ($plan) use ($prices) {
            if (! $stripePrice = $prices->firstWhere('id', $plan->id)) {
                throw new RuntimeException('Price ['.$plan->id.'] does not exist in your Stripe account.');
            }

            $plan->rawPrice = $stripePrice->unit_amount;

            $price = Cashier::formatAmount($stripePrice->unit_amount, $stripePrice->currency);

            if (Str::endsWith($price, '.00')) {
                $price = substr($price, 0, -3);
            }

            if (Str::endsWith($price, '.0')) {
                $price = substr($price, 0, -2);
            }

            $plan->price = $price;

            $plan->currency = $stripePrice->currency;

            return $plan;
        });
    }

    /**
     * Get the current subscription state.
     *
     * @param  \Spark\Billable  $billable
     * @param  \Laravel\Cashier\Subscription|null  $subscription
     * @return string
     */
    protected function state($billable, $subscription)
    {
        if (! $subscription && request('checkout') === 'subscription_started') {
            return 'pending';
        }

        if ($subscription && $subscription->onGracePeriod()) {
            return 'onGracePeriod';
        }

        if ($subscription && $subscription->active()) {
            return 'active';
        }

        if ($subscription && $subscription->pastDue()) {
            return 'past_due';
        }

        return 'none';
    }

    /**
     * Get all of the payment methods for the given billable.
     *
     * @param  \Spark\Billable  $billable
     * @return array
     */
    protected function paymentMethods($billable)
    {
        $defaultPaymentMethod = $billable->defaultPaymentMethod();

        return $billable->paymentMethods()->map(fn (PaymentMethod $paymentMethod) => [
            'id' => $paymentMethod->id,
            'last4' => $paymentMethod->card->last4,
            'brand' => ucfirst($paymentMethod->card->brand),
            'expiration' => Carbon::createFromDate($paymentMethod->card->exp_year, $paymentMethod->card->exp_month, 1)->format('M Y'),
            'country' => $paymentMethod->card->country,
            'default' => $defaultPaymentMethod ? $paymentMethod->id === $defaultPaymentMethod->id : false,
        ])->toArray();
    }

    /**
     * List all open invoices of the given billable.
     *
     * @param  \Spark\Billable  $billable
     * @return array
     */
    protected function openInvoices($billable)
    {
        return $billable->invoicesIncludingPending(['limit' => 100, 'status' => 'open', 'expand' => ['data.subscription']])
            ->filter(function (Invoice $invoice) {
                // If the subscription is cancelled, we will filter out open invoices...
                return ! $invoice->subscription instanceof StripeSubscription ||
                    ($invoice->subscription->status !== StripeSubscription::STATUS_CANCELED &&
                    $invoice->subscription->status !== StripeSubscription::STATUS_INCOMPLETE);
            })
            ->map(fn (Invoice $invoice) => [
                'amount' => $invoice->realTotal(),
                'date' => $invoice->date()->translatedFormat(config('spark.date_format', 'F j, Y')),
                'id' => $invoice->id,
                'invoice_url' => route('spark.invoices.download', [
                    $billable->sparkConfiguration()['type'],
                    $billable->id,
                    $invoice->id,
                ]),
                'status' => $invoice->status,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Paginate all paid invoices of the given billable.
     *
     * @param  \Spark\Billable  $billable
     * @return \Illuminate\Contracts\Pagination\CursorPaginator|array
     */
    protected function paidInvoices($billable)
    {
        return $billable->cursorPaginateInvoices(10, ['status' => 'paid'])
            ->withQueryString()
            ->through(fn (Invoice $invoice) => [
                'amount' => $invoice->realTotal(),
                'date' => $invoice->date()->translatedFormat(config('spark.date_format', 'F j, Y')),
                'id' => $invoice->id,
                'invoice_url' => route('spark.invoices.download', [
                    $billable->sparkConfiguration()['type'],
                    $billable->id,
                    $invoice->id,
                ]),
                'status' => $invoice->status,
            ]);
    }

    /**
     * Get the URL of the billing dashboard.
     *
     * @return string
     */
    protected function dashboardUrl()
    {
        if ($dashboardUrl = config('spark.dashboard_url')) {
            return $dashboardUrl;
        }

        return app('router')->has('dashboard') ? route('dashboard') : '/';
    }

    /**
     * Get the URL of the "terms of service" page.
     *
     * @return string
     */
    protected function termsUrl()
    {
        if ($termsUrl = config('spark.terms_url')) {
            return $termsUrl;
        }

        return app('router')->has('terms.show') ? route('terms.show') : null;
    }
}
