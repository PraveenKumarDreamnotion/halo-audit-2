<?php
use HaloAudit\Wp\Gate;
use HaloAudit\Wp\Installer;
use HaloAudit\Wp\RateLimiter;
use HaloAudit\Wp\ReportStore;

class Halo_Gate_Test extends WP_UnitTestCase
{
    private Gate $gate;
    private ReportStore $store;
    private string $code = '';
    private string $key;

    public function set_up(): void
    {
        parent::set_up();
        $this->store = new ReportStore();
        $this->gate = new Gate($this->store);
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return true;
        }, 10, 3);
        $this->key = $this->store->create([
            'engine_version' => '2.0.0', 'url' => 'https://example.com/', 'keyword' => 'k', 'created_at' => time(),
            'is_education' => false, 'speed_done' => false, 'checks' => [], 'summary' => ['groups' => [], 'overall' => 50, 'grade' => 'C'],
        ]);
    }

    public function test_rate_limiter_counts_and_blocks(): void
    {
        $this->assertTrue(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertFalse(RateLimiter::allow('t', 'a', 2, 60));
        $this->assertTrue(RateLimiter::allow('t', 'b', 2, 60));
    }

    public function test_code_is_six_digits_and_stored_only_as_a_hash(): void
    {
        global $wpdb;
        $this->assertTrue($this->gate->send('asha@example.com', '203.0.113.5'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->code);
        $row = $wpdb->get_row('SELECT * FROM ' . Installer::otpTable(), ARRAY_A);
        $this->assertNotSame($this->code, $row['code_hash']);
        $this->assertStringNotContainsString($this->code, $row['code_hash']);
    }

    public function test_failed_delivery_is_recorded_without_the_code_or_the_address(): void
    {
        remove_all_filters('halo_audit_send_code');
        add_filter('halo_audit_send_code', function ($sent, $email, $code) {
            $this->code = $code;
            return false;
        }, 10, 3);

        $result = $this->gate->send('asha@example.com', '203.0.113.5');
        $this->assertSame('halo_mail', $result->get_error_code());

        $stored = get_option(Gate::MAIL_FAILURE_OPTION);
        $this->assertIsInt($stored);
        $this->assertGreaterThan(time() - 60, $stored);
        $raw = (string) maybe_serialize($stored);
        $this->assertStringNotContainsString($this->code, $raw);
        $this->assertStringNotContainsString('asha', $raw);
    }

    public function test_a_later_delivery_clears_the_failure_record(): void
    {
        update_option(Gate::MAIL_FAILURE_OPTION, time() - 3600, false);
        $this->assertTrue($this->gate->send('asha@example.com', '203.0.113.5'));
        $this->assertFalse(get_option(Gate::MAIL_FAILURE_OPTION));
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->assertSame('halo_email', $this->gate->send('not-an-email', '203.0.113.5')->get_error_code());
    }

    public function test_fourth_code_for_one_email_in_an_hour_is_refused(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->assertTrue($this->gate->send('asha@example.com', '203.0.113.' . $i));
        }
        $error = $this->gate->send('asha@example.com', '203.0.113.9');
        $this->assertSame('halo_rate', $error->get_error_code());
        $this->assertSame(429, $error->get_error_data()['status']);
    }

    public function test_eleventh_code_from_one_ip_is_refused(): void
    {
        foreach (range(1, 10) as $i) {
            $this->assertTrue($this->gate->send("user$i@example.com", '203.0.113.5'));
        }
        $this->assertSame('halo_rate', $this->gate->send('user11@example.com', '203.0.113.5')->get_error_code());
    }

    public function test_right_code_saves_the_lead_and_returns_a_token(): void
    {
        $this->gate->send('Asha@Example.com', '203.0.113.5');
        $token = $this->gate->verify('asha@example.com', $this->code, 'Asha Rao', $this->key);

        $this->assertIsString($token);
        $this->assertSame(['name' => 'Asha Rao', 'email' => 'asha@example.com'], $this->gate->parse($token));
        $row = $this->store->get($this->key);
        $this->assertSame('asha@example.com', $row['email']);
        $this->assertNotNull($row['lead_verified_at']);
    }

    public function test_a_code_works_once(): void
    {
        $this->gate->send('asha@example.com', '203.0.113.5');
        $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key);
        $this->assertWPError($this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key));
    }

    public function test_five_wrong_tries_void_the_code(): void
    {
        $this->gate->send('asha@example.com', '203.0.113.5');
        foreach (range(1, 5) as $i) {
            $this->assertSame('halo_code', $this->gate->verify('asha@example.com', '000000', 'Asha', $this->key)->get_error_code());
        }
        $this->assertSame('halo_code_void', $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key)->get_error_code());
        $this->assertNull($this->store->get($this->key)['email']);
    }

    public function test_expired_code_is_refused(): void
    {
        global $wpdb;
        $this->gate->send('asha@example.com', '203.0.113.5');
        $wpdb->query('UPDATE ' . Installer::otpTable() . " SET expires_at = '2000-01-01 00:00:00'");
        $this->assertSame('halo_code', $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key)->get_error_code());
    }

    public function test_tampered_or_expired_tokens_do_not_parse(): void
    {
        $good = $this->gate->token('Asha', 'asha@example.com', time() + 60);
        $this->assertNotNull($this->gate->parse($good));
        $this->assertNull($this->gate->parse($good . 'x'));
        $this->assertNull($this->gate->parse(str_replace('.', '.A', $good)));
        $this->assertNull($this->gate->parse($this->gate->token('Asha', 'asha@example.com', time() - 1)));
        $this->assertNull($this->gate->parse('garbage'));
    }

    public function test_current_reads_the_cookie(): void
    {
        $this->assertNull($this->gate->current());
        $_COOKIE[Gate::COOKIE] = $this->gate->token('Asha', 'asha@example.com', time() + 60);
        $this->assertSame('asha@example.com', $this->gate->current()['email']);
        unset($_COOKIE[Gate::COOKIE]);
    }

    public function test_the_fifth_try_can_still_be_the_right_code(): void
    {
        $this->gate->send('asha@example.com', '203.0.113.5');
        foreach (range(1, 4) as $i) {
            $this->assertSame('halo_code', $this->gate->verify('asha@example.com', '000000', 'Asha', $this->key)->get_error_code());
        }
        $this->assertIsString($this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key));
    }

    /**
     * Runs $then once, right after this request has read the code row and before its next
     * statement on the code table: the window in which parallel requests interleave.
     */
    private function afterCodeRowIsRead(callable $then, string $nextStatement = ''): void
    {
        $table = Installer::otpTable();
        $state = 'waiting';
        add_filter('query', static function ($sql) use (&$state, $table, $then, $nextStatement) {
            if ($state === 'waiting' && stripos($sql, "SELECT * FROM $table") === 0) {
                $state = 'armed';
            } elseif ($state === 'armed' && stripos($sql, $table) !== false && ($nextStatement === '' || stripos($sql, $nextStatement) === 0)) {
                $state = 'done';
                $then();
            }
            return $sql;
        });
    }

    public function test_attempts_used_by_parallel_requests_count_against_this_one(): void
    {
        global $wpdb;
        $this->gate->send('asha@example.com', '203.0.113.5');
        // Five other requests spend every try while this one is between reading the row and acting on it.
        $this->afterCodeRowIsRead(static function () use ($wpdb) {
            $wpdb->query('UPDATE ' . Installer::otpTable() . ' SET attempts = ' . Gate::MAX_ATTEMPTS);
        });

        $result = $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key);
        $this->assertWPError($result);
        $this->assertSame('halo_code_void', $result->get_error_code());
        $this->assertNull($this->store->get($this->key)['email']);
    }

    public function test_two_parallel_right_codes_unlock_only_once(): void
    {
        global $wpdb;
        $this->gate->send('asha@example.com', '203.0.113.5');
        // Another request with the same right code uses the code up just before this one does.
        $this->afterCodeRowIsRead(static function () use ($wpdb) {
            $wpdb->query('DELETE FROM ' . Installer::otpTable());
        }, 'DELETE');

        $result = $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key);
        $this->assertWPError($result);
        $this->assertSame('halo_code', $result->get_error_code());
        $this->assertNull($this->store->get($this->key)['email']);
    }

    public function test_many_wrong_tries_at_once_never_pass_five_claims(): void
    {
        global $wpdb;
        $this->gate->send('asha@example.com', '203.0.113.5');
        $table = Installer::otpTable();
        $id = (int) $wpdb->get_var("SELECT id FROM $table");
        // The claim statement the gate uses, run 20 times as 20 parallel requests would.
        $won = 0;
        foreach (range(1, 20) as $i) {
            $won += (int) $wpdb->query($wpdb->prepare("UPDATE $table SET attempts = attempts + 1 WHERE id = %d AND attempts < %d", $id, Gate::MAX_ATTEMPTS));
        }
        $this->assertSame(Gate::MAX_ATTEMPTS, $won);
        $this->assertSame(Gate::MAX_ATTEMPTS, (int) $wpdb->get_var("SELECT attempts FROM $table"));
        $this->assertSame('halo_code_void', $this->gate->verify('asha@example.com', $this->code, 'Asha', $this->key)->get_error_code());
    }
}
