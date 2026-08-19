<?php

namespace App\Providers;

use App\Listeners\RecordAuthEvents;
use App\Listeners\SendNewLoginAlert;
use App\Models\Environment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SharedSecret;
use App\Models\Variable;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->bindProjectScopedRouteModels();

        Event::listen(Login::class, [RecordAuthEvents::class, 'recordLogin']);
        Event::listen(Failed::class, [RecordAuthEvents::class, 'recordFailure']);
        // A "new sign-in" alert to the account, on every successful login.
        Event::listen(Login::class, SendNewLoginAlert::class);
    }

    /**
     * Resolve the organization → project → environment/variable chain
     * explicitly, each one inside the last.
     *
     * Laravel's `scopeBindings()` only reaches a child through its immediate
     * parent, and a URL like `.../variables/{variable}/environments/{environment}`
     * has two siblings instead. Left implicit, `{environment}` would resolve by
     * slug across the whole database - and environment slugs are only unique
     * per project, so `prod` could silently resolve to a DIFFERENT project's
     * production environment. Binding the chain closes that: nothing in a URL
     * can name something outside the project it is nested in.
     *
     * Order matters. Explicit binders run over the route's parameters in URL
     * order, so by the time `environment` is resolved, `project` is already a
     * model rather than a slug.
     */
    protected function bindProjectScopedRouteModels(): void
    {
        Route::bind('organization', fn (string $value): Organization => Organization::query()
            ->where('slug', $value)
            ->firstOrFail());

        Route::bind('project', function (string $value, RoutingRoute $route): Project {
            $organization = $route->parameter('organization');

            abort_unless($organization instanceof Organization, 404);

            return $organization->projects()->where('slug', $value)->firstOrFail();
        });

        Route::bind('environment', function (string $value, RoutingRoute $route): Environment {
            return $this->routeProject($route)->environments()->where('slug', $value)->firstOrFail();
        });

        Route::bind('variable', function (string $value, RoutingRoute $route): Variable {
            return $this->routeProject($route)->variables()->whereKey($value)->firstOrFail();
        });

        // Shared vault items hang off the organization, not a project - bound
        // here for the same reason: an id in a URL must not reach across one.
        Route::bind('shared', function (string $value, RoutingRoute $route): SharedSecret {
            $organization = $route->parameter('organization');

            abort_unless($organization instanceof Organization, 404);

            return $organization->sharedSecrets()->whereKey($value)->firstOrFail();
        });
    }

    protected function routeProject(RoutingRoute $route): Project
    {
        $project = $route->parameter('project');

        abort_unless($project instanceof Project, 404);

        return $project;
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
