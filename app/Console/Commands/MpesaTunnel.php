<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Mpesa\DarajaClient;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Development only: an ngrok tunnel so Safaricom's sandbox can reach the M-Pesa
 * webhooks on this laptop (docs/billing.md). Runs as the "tunnel" pane of
 * `composer run dev`.
 *
 * The tunnel's public URL is cached (DarajaClient::TUNNEL_URL_CACHE_KEY) and
 * used whenever MPESA_CALLBACK_BASE_URL is empty, so nothing is pasted into
 * .env on each start; in sandbox the Paybill URLs are re-registered too.
 *
 * It never exits on its own: `composer run dev` runs with --kill-others, so a
 * missing ngrok or a bad authtoken would otherwise stop the server and queues.
 * It says what is wrong and waits instead.
 */
class MpesaTunnel extends Command
{
    protected $signature = 'mpesa:tunnel {--once : Start, report and return instead of staying up (tests)}';

    protected $description = 'Open an ngrok tunnel for local M-Pesa callbacks (development)';

    /**
     * How long the cached URL outlives its heartbeat. Short enough that a dead
     * tunnel stops being used, long enough to survive a slow loop.
     */
    private const URL_TTL_SECONDS = 150;

    private const HEARTBEAT_SECONDS = 30;

    private ?InvokedProcess $ngrok = null;

    private ?string $url = null;

    public function handle(DarajaClient $daraja): int
    {
        if (! app()->environment('local')) {
            $this->warn('mpesa:tunnel is for local development only; production sets MPESA_CALLBACK_BASE_URL.');

            return $this->idle();
        }

        if (! config('services.mpesa.ngrok.enabled')) {
            $this->line('ngrok tunnel off (NGROK_ENABLED=false).');

            return $this->idle();
        }

        if (filled(config('services.mpesa.callback_base_url'))) {
            $this->line('MPESA_CALLBACK_BASE_URL is set, so M-Pesa calls back there. No tunnel started.');

            return $this->idle();
        }

        $this->trap([SIGINT, SIGTERM], function (): void {
            $this->shutdown();
            exit(0);
        });

        $url = $this->start();

        if ($url === null) {
            $this->shutdown();

            return $this->idle();
        }

        $this->url = $url;
        $this->heartbeat();

        $this->info("Tunnel: {$url} → ".config('services.mpesa.ngrok.upstream'));
        $this->reportCallbacks($daraja, $url);

        return $this->option('once') ? self::SUCCESS : $this->watch();
    }

    /** Starts ngrok and waits for its public https URL. */
    private function start(): ?string
    {
        $command = [
            (string) config('services.mpesa.ngrok.bin'),
            'http',
            (string) config('services.mpesa.ngrok.upstream'),
            '--log', 'stdout',
            '--log-format', 'logfmt',
        ];

        if (filled(config('services.mpesa.ngrok.domain'))) {
            $command[] = '--url=https://'.preg_replace('#^https?://#', '', (string) config('services.mpesa.ngrok.domain'));
        }

        try {
            $this->ngrok = Process::forever()->start($command);
        } catch (Throwable $e) {
            $this->error('Could not start ngrok: '.$e->getMessage());
            $this->line('Install it (https://ngrok.com/download) and run `ngrok config add-authtoken …`, or set NGROK_ENABLED=false.');

            return null;
        }

        $log = '';

        for ($attempt = 0; $attempt < 40; $attempt++) {
            $log .= $this->ngrok->latestOutput().$this->ngrok->latestErrorOutput();

            if (preg_match('/msg="started tunnel".*?\burl=(https:\/\/\S+)/', $log, $match)) {
                return rtrim($match[1], '/');
            }

            if (($fromApi = $this->urlFromLocalApi()) !== null) {
                return $fromApi;
            }

            if (! $this->ngrok->running()) {
                $this->error('ngrok stopped: '.$this->reason($log));

                return null;
            }

            usleep($this->option('once') ? 10_000 : 500_000);
        }

        $this->error('ngrok did not report a public URL within 20 seconds: '.$this->reason($log));

        return null;
    }

    /** ngrok's own API on 127.0.0.1:4040, in case the log line format changes. */
    private function urlFromLocalApi(): ?string
    {
        try {
            $tunnels = Http::timeout(1)->get('http://127.0.0.1:4040/api/tunnels')->json('tunnels') ?? [];
        } catch (Throwable) {
            return null;
        }

        foreach ($tunnels as $tunnel) {
            if (is_string($tunnel['public_url'] ?? null) && str_starts_with($tunnel['public_url'], 'https://')) {
                return rtrim($tunnel['public_url'], '/');
            }
        }

        return null;
    }

    private function reportCallbacks(DarajaClient $daraja, string $url): void
    {
        if (blank(config('services.mpesa.webhook_secret'))) {
            $this->warn('MPESA_WEBHOOK_SECRET is empty: every M-Pesa callback will be refused (404). Set it in .env.');

            return;
        }

        $masked = preg_replace('#/webhooks/pay/[^/]+/#', '/webhooks/pay/<secret>/', $daraja->webhookUrl('stk'));
        $this->line("M-Pesa STK callback: {$masked}");

        if (! $daraja->configured()) {
            $this->warn('Daraja keys are not all set (MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE, MPESA_PASSKEY): payments stay off.');

            return;
        }

        if (config('services.mpesa.env') !== 'sandbox') {
            $this->line('Not sandbox: Paybill URLs are not re-registered automatically. Run `php artisan mpesa:register-urls` deliberately.');

            return;
        }

        try {
            $response = $daraja->registerC2bUrls();
            $response->successful()
                ? $this->info('Sandbox Paybill URLs registered with Safaricom.')
                : $this->warn('Safaricom did not register the Paybill URLs: '.($response->json('errorMessage') ?? $response->body()));
        } catch (Throwable $e) {
            $this->warn('Could not register the Paybill URLs: '.$e->getMessage());
        }
    }

    /** Stays up while ngrok runs, echoing its warnings and keeping the cached URL alive. */
    private function watch(): int
    {
        $lastHeartbeat = time();

        while ($this->ngrok?->running()) {
            foreach (preg_split('/\R/', $this->ngrok->latestOutput().$this->ngrok->latestErrorOutput()) ?: [] as $line) {
                if (preg_match('/lvl=(eror|crit|warn)/', $line)) {
                    $this->warn($line);
                }
            }

            if (time() - $lastHeartbeat >= self::HEARTBEAT_SECONDS) {
                $this->heartbeat();
                $lastHeartbeat = time();
            }

            sleep(1);
        }

        $this->error('ngrok stopped. Restart `composer run dev` to open the tunnel again.');

        return $this->idle();
    }

    /**
     * Re-stamps the cached URL with a short life, so it fades away on its own
     * once no tunnel is running.
     *
     * The URL is not deleted on shutdown: with a reserved ngrok domain two
     * tunnels share the same URL, and one stopping used to cut the other off.
     */
    private function heartbeat(): void
    {
        if ($this->url !== null) {
            Cache::put(DarajaClient::TUNNEL_URL_CACHE_KEY, $this->url, self::URL_TTL_SECONDS);
        }
    }

    /** Waits without exiting, so `concurrently --kill-others` leaves the rest of the dev stack alone. */
    private function idle(): int
    {
        if ($this->option('once')) {
            return self::SUCCESS;
        }

        while (true) {
            sleep(3600);
        }
    }

    private function shutdown(): void
    {
        if ($this->ngrok?->running()) {
            $this->ngrok->signal(SIGTERM);
        }
    }

    private function reason(string $log): string
    {
        if (preg_match('/lvl=(?:eror|crit) .*?\berr="?([^"\n]+)/', $log, $match)) {
            return $match[1];
        }

        $last = trim((string) collect(preg_split('/\R/', trim($log)) ?: [])->last());

        return $last !== '' ? $last : 'no output (is the authtoken set? `ngrok config check`)';
    }
}
