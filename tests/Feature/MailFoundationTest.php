<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Messaging\EmailLogger;
use App\Domain\Messaging\Models\EmailLog;
use App\Domain\People\Models\Role;
use App\Domain\People\Models\User;
use App\Domain\Tenancy\Models\School;
use App\Notifications\Account\PasswordChanged;
use App\Notifications\Account\StaffInvitation;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The shared email plumbing every people-facing email goes through
 * (App\Notifications\EducaMail): sender and branding, queueing, the delivery
 * log, retention, and the SMTP check command.
 */
class MailFoundationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReferenceData();
        $this->school = School::factory()->create(['name' => 'Hilltop Primary', 'slug' => 'hilltop', 'email' => 'office@hilltop.test']);
        $this->user = User::factory()->withRole(Role::EXAMINER)->create(['school_id' => $this->school->id, 'name' => 'Nia Wambui', 'username' => 'nwambui', 'email' => 'nia@example.test']);
    }

    /** @return list<Email> */
    private function sentMessages(): array
    {
        return array_map(fn ($sent) => $sent->getOriginalMessage(), iterator_to_array(app('mailer')->getSymfonyTransport()->messages()));
    }

    private function invitation(): StaffInvitation
    {
        return new StaffInvitation('https://portal.example.test/invite/'.str_repeat('x', 64), 'Admin One', [Role::EXAMINER], now()->addWeek());
    }

    public function test_mail_goes_out_under_the_schools_name_with_replies_to_the_school(): void
    {
        $this->user->notify($this->invitation());

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);
        $message = $messages[0];

        $this->assertEquals([new Address(config('mail.from.address'), 'Hilltop Primary via Educa')], $message->getFrom());
        $this->assertEquals([new Address('office@hilltop.test', 'Hilltop Primary')], $message->getReplyTo());
        $this->assertSame("You're invited to Hilltop Primary on Educa", $message->getSubject());

        $html = (string) $message->getHtmlBody();
        $this->assertStringContainsString('Hilltop Primary', $html);
        $this->assertStringContainsString('Set up your account', $html);
        $this->assertStringContainsString('https://portal.example.test/invite/', $html);
        $this->assertStringContainsString('Sent by Educa on behalf of Hilltop Primary', $html);
        $this->assertStringContainsString('#1e3a8a', $html);   // the Educa theme, inlined
        $this->assertStringContainsString('Set up your account', (string) $message->getTextBody());
    }

    public function test_a_platform_email_without_a_school_uses_the_platform_sender(): void
    {
        $platform = User::factory()->platformAdmin()->create(['email' => 'root@example.test']);

        $platform->notify(new PasswordChanged(PasswordChanged::SELF, null, now()));

        $message = $this->sentMessages()[0];
        $this->assertEquals([new Address(config('mail.from.address'), config('mail.from.name'))], $message->getFrom());
        $this->assertSame([], $message->getReplyTo());
        $this->assertStringContainsString('contact Educa support', (string) $message->getTextBody());
    }

    public function test_a_sent_email_is_logged_without_its_link(): void
    {
        $this->user->notify($this->invitation());

        $log = EmailLog::query()->sole();
        $this->assertSame('staff_invitation', $log->type);
        $this->assertSame(EmailLog::SENT, $log->status);
        $this->assertSame('nia@example.test', $log->recipient);
        $this->assertSame($this->school->id, $log->school_id);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertStringNotContainsString(str_repeat('x', 64), json_encode($log->toArray()));
        $this->assertStringNotContainsString('https://', json_encode($log->toArray()));
    }

    public function test_nothing_is_sent_when_the_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function (): void {
                $this->user->notify($this->invitation());
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(0, EmailLog::query()->count());
    }

    public function test_a_queued_email_that_gives_up_is_logged_as_failed(): void
    {
        foreach ([false, true] as $encrypted) {
            $command = new SendQueuedNotifications(collect([$this->user]), $this->invitation(), ['mail']);
            $serialized = serialize($command);

            $job = Mockery::mock(Job::class);
            $job->shouldReceive('payload')->andReturn(['data' => ['command' => $encrypted ? app(Encrypter::class)->encrypt($serialized) : $serialized]]);

            app(EmailLogger::class)->jobFailed(new JobFailed('database-long', $job, new RuntimeException('Connection refused')));
        }

        $this->assertSame(2, EmailLog::query()->where('status', EmailLog::FAILED)->where('error', 'Connection refused')->where('type', 'staff_invitation')->count());
    }

    public function test_the_log_is_pruned_after_the_retention_period(): void
    {
        EmailLog::query()->create(['type' => 'x', 'status' => 'sent', 'created_at' => now()->subDays(181)]);
        EmailLog::query()->create(['type' => 'x', 'status' => 'sent', 'created_at' => now()->subDays(10)]);

        $this->artisan('mail:prune-log')->assertSuccessful();

        $this->assertSame(1, EmailLog::query()->count());
    }

    public function test_the_mail_test_command_sends_one_message(): void
    {
        $this->artisan('mail:test', ['address' => 'ops@example.test'])
            ->expectsOutputToContain('Sent to ops@example.test')
            ->assertSuccessful();

        $this->assertSame('ops@example.test', $this->sentMessages()[0]->getTo()[0]->getAddress());

        $this->artisan('mail:test', ['address' => 'not-an-address'])->assertFailed();
    }
}
