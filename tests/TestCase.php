<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // TrustHosts stores its list on Symfony's Request statically. A test that
        // switches to the production environment would otherwise leave it set
        // for every test after it.
        SymfonyRequest::setTrustedHosts([]);
    }

    /**
     * Signs in as if the password was just typed, so sensitive admin actions
     * (see ConfirmRecentPassword) work. Use actingAsWithoutConfirmation() to
     * test the confirmation step itself.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        return parent::actingAs($user, $guard)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function actingAsWithoutConfirmation(Authenticatable $user): static
    {
        return parent::actingAs($user);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
