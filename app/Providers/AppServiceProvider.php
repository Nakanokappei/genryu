<?php

namespace App\Providers;

use App\Actions\RobotsPolicy;
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

        // Timestamps are stored in UTC; the screens show them in the display
        // timezone (config app.display_timezone) through this one macro.
        CarbonImmutable::macro('display', function (): string {
            /** @var CarbonImmutable $this */
            return $this->setTimezone((string) config('app.display_timezone'))->format('Y-m-d H:i');
        });

        // Verify TLS against the Mozilla CA bundle shipped with composer/ca-bundle:
        // the local PHP build has no CA store configured and rejected sites
        // (fraunhofer.de, cnrs.fr) whose chains the system's curl accepts.
        Http::globalOptions(['verify' => CaBundle::getBundledCaBundlePath()]);

        // robots.txt is enforced here, on every outgoing request, so no
        // crawler code path can forget it. robots.txt itself and the API
        // hosts we call as a client are exempt.
        Http::globalRequestMiddleware(function (RequestInterface $request): RequestInterface {
            $uri = $request->getUri();
            $exempt = $uri->getPath() === '/robots.txt' || in_array(strtolower($uri->getHost()), (array) config('crawler.robots_exempt_hosts'), true);

            if (! $exempt && ! app(RobotsPolicy::class)->allows((string) $uri)) {
                throw new RobotsForbidden(__('robots.txt does not allow fetching :url', ['url' => (string) $uri]));
            }

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
