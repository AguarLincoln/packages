<?php

namespace Spark\Actions;

use Illuminate\Support\Carbon;
use Spark\Contracts\Actions\CreatesSubscriptions;
use Spark\Features;
use Spark\GuessesBillableTypes;
use Spark\Spark;
use Stripe\Subscription;
use Throwable;

class CreateSubscription implements CreatesSubscriptions
{
    use GuessesBillableTypes;

    /**
     * {@inheritDoc}
     */
    public function create($billable, $plan, array $options = [])
    {
        $billableId = $billable->getKey();
        $billableType = $billable->sparkConfiguration('type');
        $sessionOptions = [];

        $planObject = Spark::plans($billableType)
            ->where('id', $plan)
            ->first();

        $this->purgeOldSubscriptions($billable);

        $builder = $billable->newSubscription('default', $plan);

        $this->configureTrial($billable, $planObject, $builder);

        if (Spark::chargesPerSeat($billableType)) {
            $builder->quantity(Spark::seatCount($billableType, $billable));
        }

        if (is_callable($sessionOptionsCallback = Spark::getCheckoutSessionOptions($billableType))) {
            $sessionOptions = $sessionOptionsCallback($billable, $planObject);
        }

        if (! isset($sessionOptions['discounts'])) {
            $builder = $builder->allowPromotionCodes();
        }

        return $builder->checkout(array_merge(array_filter([
            'success_url' => route('spark.portal', [$billableType, $billableId]).'?checkout=subscription_started',
            'cancel_url' => route('spark.portal', [$billableType, $billableId]).'?checkout=cancelled',
            'consent_collection' => array_filter([
                'terms_of_service' => Features::enforcesAcceptingTerms() ? 'required' : null,
            ]),
        ]), $sessionOptions));
    }

    /**
     * Cancel and delete any old subscriptions except ones that were already cancelled.
     *
     * @param  \Spark\Billable  $billable
     * @return void
     */
    protected function purgeOldSubscriptions($billable)
    {
        $billable->subscriptions()->where('stripe_status', '!=', Subscription::STATUS_CANCELED)
            ->each(function ($subscription) {
                try {
                    $status = $subscription->stripe_status;

                    $subscription->noProrate();

                    $subscription->cancelNow();

                    if ($status === Subscription::STATUS_INCOMPLETE_EXPIRED) {
                        $subscription->items()->delete();
                        $subscription->delete();
                    }
                } catch (Throwable $e) {
                    //
                }
            });
    }

    /**
     * Configure the trial period.
     *
     * @param  \Spark\Billable  $billable
     * @param  \Spark\Plan  $plan
     * @param  \Laravel\Cashier\SubscriptionBuilder  $builder
     * @return void
     */
    protected function configureTrial($billable, $plan, $builder)
    {
        $skipTrialIfSubscribedBefore = config('spark.skip_trial_if_subscribed_before');

        if (is_null($skipTrialIfSubscribedBefore) || ! $subscription = $billable->subscription()) {
            if ($plan->trialDays) {
                $builder->trialUntil(Carbon::now()->addDays($plan->trialDays)->addHour());
            }

            return;
        }

        if (now()->diffInDays($subscription->ends_at) < $skipTrialIfSubscribedBefore) {
            $builder->skipTrial();

            return;
        }

        if ($plan->trialDays) {
            $builder->trialUntil(Carbon::now()->addDays($plan->trialDays)->addHour());
        }
    }
}
