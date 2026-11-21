<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_pages_get_a_nonce_based_policy_that_matches_the_inline_script(): void
    {
        $response = $this->get('/ar')->assertOk();
        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);

        $this->assertStringContainsString('nonce="'.$nonce[1].'"', (string) $response->getContent());
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $policy);
    }

    public function test_every_request_gets_a_fresh_nonce(): void
    {
        $first = $this->get('/ar')->headers->get('Content-Security-Policy');
        $second = $this->get('/ar')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_admin_and_auth_pages_are_covered_too(): void
    {
        $this->get('/admin/login')->assertHeader('Content-Security-Policy');
        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_json_responses_do_not_carry_the_policy(): void
    {
        $this->getJson('/ar/search/suggest?q=ab')->assertOk()->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_report_only_mode_does_not_block(): void
    {
        config(['store.csp.report_only' => true]);

        $this->get('/ar')
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_the_policy_can_be_turned_off(): void
    {
        config(['store.csp.enabled' => false]);

        $this->get('/ar')->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_production_error_pages_keep_the_policy_but_local_debug_pages_do_not(): void
    {
        $this->app['router']->get('/boom', fn () => throw new \RuntimeException('boom'))->middleware('web');

        config(['app.debug' => false]);
        $this->get('/boom')->assertStatus(500)->assertHeader('Content-Security-Policy');

        config(['app.debug' => true]);
        $this->get('/boom')->assertStatus(500)->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_turnstile_is_allowed_only_when_it_is_configured(): void
    {
        config(['services.turnstile.site_key' => 'site', 'services.turnstile.secret_key' => 'secret']);

        $policy = (string) $this->get('/ar/cart')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("script-src 'self' 'nonce-", $policy);
        $this->assertMatchesRegularExpression('#script-src [^;]*https://challenges\.cloudflare\.com#', $policy);
        $this->assertMatchesRegularExpression('#frame-src [^;]*https://challenges\.cloudflare\.com#', $policy);
    }

    public function test_violation_reports_are_accepted_without_a_csrf_token_and_logged_briefly(): void
    {
        Log::spy();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri' => 'https://store.test/ar',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/x.js',
                'original-policy' => str_repeat('x', 2000),
            ],
        ]))->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'CSP violation'
            && $context['blocked-uri'] === 'https://evil.example/x.js'
            && ! isset($context['original-policy']));
    }

    public function test_oversized_or_broken_reports_are_ignored(): void
    {
        Log::spy();

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], str_repeat('a', 9000))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{"csp-report":{"blocked-uri":["x"]}}')->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json')->assertNoContent();

        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_report_endpoint_is_rate_limited(): void
    {
        foreach (range(1, 20) as $attempt) {
            $this->call('POST', '/csp-report', [], [], [], [], '{}')->assertNoContent();
        }

        $this->call('POST', '/csp-report', [], [], [], [], '{}')->assertTooManyRequests();
    }
}
