<?php

namespace Spark;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use RuntimeException;
use Spark\Contracts\Actions\CalculatesVatRate;
use Spark\Contracts\Actions\CreatesSubscriptions;
use Spark\Contracts\Actions\PaysInvoices;
use Spark\Contracts\Actions\UpdatesSubscriptions;
use Spark\Listeners\SetupDefaultPaymentMethod;

class SparkServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        if (! $this->app->configurationIsCached()) {
            $this->mergeConfigFrom(__DIR__.'/../config/spark.php', 'spark');
        }

        $this->app->singleton('spark.manager', SparkManager::class);
        $this->app->singleton(FrontendState::class);

        $this->app->singleton(CalculatesVatRate::class, Actions\CalculateVatRate::class);
        $this->app->singleton(CreatesSubscriptions::class, Actions\CreateSubscription::class);
        $this->app->singleton(PaysInvoices::class, Actions\PayInvoice::class);
        $this->app->singleton(UpdatesSubscriptions::class, Actions\UpdateSubscription::class);

        $this->registerCommands();
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if (is_array(config('spark.billables')) && count(config('spark.billables')) > 1) {
            throw new RuntimeException('The Stripe edition of Spark only supports a single billable type.');
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spark');

        $this->configureRoutes();
        $this->configureTranslations();
        $this->configurePublishing();
        $this->configureListeners();
    }

    /**
     * Configure the routes offered by the application.
     *
     * @return void
     */
    protected function configureRoutes()
    {
        Route::group([], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');
        });
    }

    /**
     * Configure Spark translations.
     *
     * @return void
     */
    protected function configureTranslations()
    {
        $this->loadJsonTranslationsFrom(lang_path('spark'));
    }

    /**
     * Configure publishing for the package.
     *
     * @return void
     */
    protected function configurePublishing()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/spark.php' => config_path('spark.php'),
        ], 'spark-config');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/spark'),
        ], 'spark-views');

        $this->publishes([
            __DIR__.'/../stubs/en.json' => lang_path('spark/en.json'),
        ], 'spark-lang');

        $this->publishes([
            __DIR__.'/../stubs/SparkServiceProvider.php' => app_path('Providers/SparkServiceProvider.php'),
        ], 'spark-provider');

        $publishesMigrationsMethod = method_exists($this, 'publishesMigrations')
            ? 'publishesMigrations'
            : 'publishes';

        $this->{$publishesMigrationsMethod}([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'spark-migrations');
    }

    /**
     * Configure the Spark event listeners.
     *
     * @return void
     */
    protected function configureListeners()
    {
        $this->app['events']->listen(WebhookHandled::class, SetupDefaultPaymentMethod::class);
    }

    /**
     * Register the Spark Artisan commands.
     *
     * @return void
     */
    protected function registerCommands()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\InstallCommand::class,
            ]);
        }
    }
}
