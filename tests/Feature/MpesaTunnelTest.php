<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Billing\Mpesa\DarajaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** `composer run dev` opens an ngrok tunnel so the M-Pesa sandbox can call back to a laptop. */
class MpesaTunnelTest extends TestCase
{
    use RefreshDatabase;

    private const STARTED = 't=2026-09-17T16:20:19+0300 lvl=info msg="started tunnel" obj=tunnels name=command_line addr=http://127.0.0.1:8000 url=https://erupt-example.ngrok-free.dev';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(DarajaClient::TUNNEL_URL_CACHE_KEY);
        Cache::forget('mpesa:access-token');

        config(['services.mpesa' => [
            'env' => 'sandbox',
            'consumer_key' => 'key',
            'consumer_secret' => 'secret',
            'shortcode' => '174379',
            'passkey' => 'passkey',
            'callback_base_url' => '',
            'webhook_secret' => 'local-secret',
            'allowed_ips' => [],
            'ngrok' => ['enabled' => true, 'bin' => 'ngrok', 'domain' => null, 'upstream' => '127.0.0.1:8000'],
        ]]);

        Http::fake([
            '127.0.0.1:4040/*' => Http::response(['tunnels' => []]),
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl' => Http::response(['ResponseDescription' => 'success']),
        ]);
    }

    private function local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
    }

    private function fakeNgrok(string $output = self::STARTED): void
    {
        Process::fake(['*' => Process::describe()->output($output)->iterations(3)]);
    }

    public function test_the_tunnel_url_is_cached_used_for_callbacks_and_registered_in_sandbox(): void
    {
        $this->local();
        $this->fakeNgrok();

        $this->artisan('mpesa:tunnel', ['--once' => true])
            ->expectsOutputToContain('Tunnel: https://erupt-example.ngrok-free.dev → 127.0.0.1:8000')
            ->expectsOutputToContain('M-Pesa STK callback: https://erupt-example.ngrok-free.dev/api/webhooks/pay/<secret>/stk')
            ->expectsOutputToContain('Sandbox Paybill URLs registered with Safaricom.')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => $process->command === ['ngrok', 'http', '127.0.0.1:8000', '--log', 'stdout', '--log-format', 'logfmt']);

        $daraja = app(DarajaClient::class);
        $this->assertSame('https://erupt-example.ngrok-free.dev', $daraja->callbackBaseUrl());
        $this->assertTrue($daraja->configured());

        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/registerurl')
            && $request['ConfirmationURL'] === 'https://erupt-example.ngrok-free.dev/api/webhooks/pay/local-secret/c2b/confirmation');
    }

    /**
     * With a reserved domain two tunnels share one URL, so stopping one used to
     * cut the other off. The URL now lives on a heartbeat instead.
     */
    public function test_the_cached_url_expires_on_its_own_rather_than_being_deleted_on_shutdown(): void
    {
        $this->local();
        $this->fakeNgrok();

        $this->artisan('mpesa:tunnel', ['--once' => true])->assertSuccessful();

        $this->assertSame('https://erupt-example.ngrok-free.dev', Cache::get(DarajaClient::TUNNEL_URL_CACHE_KEY));

        $this->travel(3)->minutes();
        $this->assertNull(Cache::get(DarajaClient::TUNNEL_URL_CACHE_KEY));
    }

    public function test_a_reserved_domain_is_passed_to_ngrok(): void
    {
        $this->local();
        config(['services.mpesa.ngrok.domain' => 'educa-dev.ngrok-free.dev']);
        $this->fakeNgrok(str_replace('erupt-example', 'educa-dev', self::STARTED));

        $this->artisan('mpesa:tunnel', ['--once' => true])->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process) => in_array('--url=https://educa-dev.ngrok-free.dev', $process->command, true));
    }

    public function test_production_keys_are_never_re_registered_automatically(): void
    {
        $this->local();
        config(['services.mpesa.env' => 'production']);
        $this->fakeNgrok();

        $this->artisan('mpesa:tunnel', ['--once' => true])
            ->expectsOutputToContain('Paybill URLs are not re-registered automatically')
            ->assertSuccessful();

        Http::assertNotSent(fn (HttpRequest $request) => str_contains($request->url(), 'registerurl'));
    }

    public function test_nothing_starts_outside_local_when_switched_off_or_when_a_base_url_is_configured(): void
    {
        Process::fake();

        $this->artisan('mpesa:tunnel', ['--once' => true])->expectsOutputToContain('local development only')->assertSuccessful();

        $this->local();
        config(['services.mpesa.ngrok.enabled' => false]);
        $this->artisan('mpesa:tunnel', ['--once' => true])->expectsOutputToContain('NGROK_ENABLED=false')->assertSuccessful();

        config(['services.mpesa.ngrok.enabled' => true, 'services.mpesa.callback_base_url' => 'https://api.educa.test']);
        $this->artisan('mpesa:tunnel', ['--once' => true])->expectsOutputToContain('No tunnel started')->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_ngrok_failing_is_reported_without_failing_the_dev_stack(): void
    {
        $this->local();
        Process::fake(['*' => Process::describe()->output('t=2026-09-17T16:20:19+0300 lvl=eror msg="session closing" err="authentication failed: Your authtoken is invalid"')->exitCode(1)]);

        $this->artisan('mpesa:tunnel', ['--once' => true])
            ->expectsOutputToContain('authentication failed: Your authtoken is invalid')
            ->assertSuccessful();

        $this->assertNull(Cache::get(DarajaClient::TUNNEL_URL_CACHE_KEY));
    }

    public function test_a_callback_url_is_refused_outright_when_there_is_no_public_base(): void
    {
        $this->expectExceptionMessage('No public callback URL');

        app(DarajaClient::class)->webhookUrl('stk');   // testing environment, no tunnel
    }

    public function test_the_tunnel_url_is_ignored_outside_local_and_a_configured_base_url_wins(): void
    {
        Cache::forever(DarajaClient::TUNNEL_URL_CACHE_KEY, 'https://stale.ngrok-free.dev');
        $daraja = app(DarajaClient::class);

        $this->assertNull($daraja->callbackBaseUrl());   // testing environment, not local
        $this->assertFalse($daraja->configured());

        $this->local();
        $this->assertSame('https://stale.ngrok-free.dev', $daraja->callbackBaseUrl());

        config(['services.mpesa.callback_base_url' => 'https://api.educa.co.ke/']);
        $this->assertSame('https://api.educa.co.ke', $daraja->callbackBaseUrl());
    }
}
