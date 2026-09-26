<?php

namespace App\Providers;

use App\Actions\RobotsPolicy;
use App\Crawl\PublicAddressGuard;
use App\Exceptions\RobotsForbidden;
use Carbon\CarbonImmutable;
use Composer\CaBundle\CaBundle;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // A UTC timestamp in the display timezone (app.display_timezone).
        CarbonImmutable::macro('display', function (): string {
            /** @var CarbonImmutable $this */
            return $this->setTimezone((string) config('app.display_timezone'))->format('Y-m-d H:i');
        });

        // Verify TLS against composer/ca-bundle (the local PHP has no CA store).
        Http::globalOptions(['verify' => CaBundle::getBundledCaBundlePath()]);

        // Every outgoing request and redirect: http(s) to public addresses only, the size capped.
        Http::globalMiddleware(new PublicAddressGuard);

        // Enforce robots.txt and its Crawl-delay on every outgoing request.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $uri = $request->getUri();
            $exempt = $uri->getPath() === '/robots.txt' || in_array(strtolower($uri->getHost()), (array) config('crawler.robots_exempt_hosts'), true);

            // robots.txt itself and the API hosts are exempt.
            if ($exempt) {
                return $request;
            }

            $robots = app(RobotsPolicy::class);

            // Refuse a disallowed URL.
            if (! $robots->allows((string) $uri)) {
                throw new RobotsForbidden(__('robots.txt does not allow fetching :url', ['url' => (string) $uri]));
            }

            $robots->waitBefore((string) $uri);

            return $request;
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
