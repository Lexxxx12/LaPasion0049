<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAccountTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $extra = []): array
    {
        return array_replace(['full_name' => 'Customer One', 'email' => 'customer@example.com', 'phone' => '09123456789', 'password' => 'password123', 'password_confirmation' => 'password123'], $extra);
    }

    public function test_public_pages_and_account_redirect(): void
    {
        $this->get('/customer/register')->assertOk();
        $this->get('/customer/login')->assertOk();
        $this->get('/customer/account')->assertRedirect('/customer/login');
    }

    public function test_registration_creates_customer_and_logs_in_without_staff_privileges(): void
    {
        $this->post('/customer/register', $this->registration(['email' => 'CUSTOMER@EXAMPLE.COM', 'sold_by' => 1, 'is_staff' => true]))->assertRedirect('/customer/account');
        $account = CustomerAccount::first();
        $this->assertAuthenticatedAs($account, 'customer');
        $this->assertGuest('web');
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'email' => 'customer@example.com']);
        $this->assertTrue(Hash::check('password123', $account->password));
        $this->assertArrayNotHasKey('password', $account->toArray());
        $this->get('/customer/account')->assertOk()->assertSee('Customer One');
    }

    public function test_customer_cannot_read_or_write_any_staff_endpoint(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer');
        foreach (['/products', '/customers', '/staff', '/sales', '/sales/create', '/staff/create', '/products/1/edit'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        foreach (['/products', '/customers', '/staff', '/sales'] as $path) {
            $this->post($path, [])->assertRedirect('/login');
        }
        $this->put('/customers/'.$account->customer_id, ['full_name' => 'Hacked'])->assertRedirect('/login');
        $this->delete('/customers/'.$account->customer_id)->assertRedirect('/login');
        $this->assertNotSoftDeleted($account->customer);
    }

    public function test_staff_cannot_use_customer_portal_without_customer_login(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->get('/customer/account')->assertRedirect('/customer/login');
    }

    public function test_duplicate_registration_does_not_create_extra_records(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'customer@example.com']);
        $this->post('/customer/register', $this->registration())->assertSessionHasErrors('email');
        $this->assertDatabaseCount('customer_accounts', 1);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_registration_cannot_claim_an_existing_customer(): void
    {
        Customer::create(['full_name' => 'Existing customer', 'email' => 'customer@example.com']);
        $this->post('/customer/register', $this->registration())->assertSessionHasErrors('email');
        $this->assertDatabaseCount('customer_accounts', 0);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_invalid_registration_cannot_create_partial_records(): void
    {
        $this->post('/customer/register', $this->registration(['email' => 'invalid', 'password_confirmation' => 'different']))->assertSessionHasErrors(['email', 'password']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('customer_accounts', 0);
    }

    public function test_login_failure_success_and_logout(): void
    {
        $account = CustomerAccount::factory()->create(['email' => 'customer@example.com']);
        $this->post('/customer/login', ['email' => 'customer@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest('customer');
        $this->post('/customer/login', ['email' => 'CUSTOMER@EXAMPLE.COM', 'password' => 'password123'])->assertRedirect('/customer/account');
        $this->assertAuthenticatedAs($account, 'customer');
        $this->post('/customer/logout')->assertRedirect('/customer/login');
        $this->assertGuest('customer');
        $this->get('/customer/account')->assertRedirect('/customer/login');
    }

    public function test_customer_login_throttles_repeated_failures(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/customer/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/customer/login', ['email' => 'unknown@example.com', 'password' => 'wrong'])->assertSessionHasErrors(['email' => 'Too many attempts. Try again in 60 seconds.']);
    }

    public function test_staff_can_edit_customer_and_change_login_email_and_password(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');
        $this->put('/customers/'.$account->customer_id, ['full_name' => 'Managed name', 'email' => 'updated@example.com', 'phone' => '555', 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertRedirect('/customers');
        $this->assertSame('updated@example.com', $account->fresh()->email);
        $this->assertTrue(Hash::check('newpassword123', $account->fresh()->password));
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'full_name' => 'Managed name']);
        Auth::guard('web')->logout();
        $this->post('/customer/login', ['email' => 'updated@example.com', 'password' => 'newpassword123'])->assertRedirect('/customer/account');
        $this->get('/customer/account')->assertOk()->assertSee('Managed name');
    }

    public function test_staff_can_enable_existing_customer_account_without_changing_other_records(): void
    {
        $customer = Customer::create(['full_name' => 'Existing', 'email' => 'existing@example.com']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->put('/customers/'.$customer->id, ['full_name' => 'Existing', 'email' => 'existing@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect('/customers');
        $this->assertDatabaseHas('customer_accounts', ['customer_id' => $customer->id, 'email' => 'existing@example.com']);
        $this->get('/customers')->assertOk()->assertSee('Registered');
    }

    public function test_deleting_customer_revokes_current_session_and_future_logins(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer')->get('/customer/account')->assertOk();
        $this->actingAs(User::factory()->create(), 'web')->delete('/customers/'.$account->customer_id)->assertRedirect();
        $this->get('/customer/account')->assertRedirect('/customer/login');
        $this->assertGuest('customer');
        $this->post('/customer/login', ['email' => $account->email, 'password' => 'password123'])->assertSessionHasErrors('email');
    }

    public function test_staff_cannot_assign_another_accounts_email(): void
    {
        $first = CustomerAccount::factory()->create();
        $second = CustomerAccount::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');
        $this->put('/customers/'.$first->customer_id, ['full_name' => 'Changed', 'email' => $second->email])->assertSessionHasErrors('email');
        $this->assertSame($first->email, $first->fresh()->email);
    }

    public function test_account_page_escapes_customer_name(): void
    {
        $account = CustomerAccount::factory()->create();
        $account->customer->update(['full_name' => '<script>alert(1)</script>']);
        $this->actingAs($account, 'customer')->get('/customer/account')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unhashable_passwords_are_validation_errors(): void
    {
        foreach ([str_repeat('x', 73), str_repeat("\u{00E9}", 40), "password\0bad"] as $password) {
            $this->post('/customer/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))->assertSessionHasErrors('password');
        }
        $this->assertDatabaseCount('customer_accounts', 0);
    }

    public function test_staff_edit_with_blank_password_preserves_customer_login(): void
    {
        $account = CustomerAccount::factory()->create();
        $hash = $account->password;
        $this->actingAs(User::factory()->create(), 'web');
        $this->put('/customers/'.$account->customer_id, ['full_name' => 'Updated', 'email' => $account->email, 'password' => ''])->assertRedirect('/customers');
        $this->assertSame($hash, $account->fresh()->password);
    }

    public function test_customer_auth_redirects_do_not_loop_and_do_not_log_out_staff(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer');
        $this->get('/customer/login')->assertRedirect('/customer/account');
        $this->get('/customer/register')->assertRedirect('/customer/account');
        $this->post('/customer/logout')->assertRedirect('/customer/login');
        $this->assertAuthenticated('web');
        $this->assertGuest('customer');
    }

    public function test_array_emails_are_validation_errors_instead_of_server_errors(): void
    {
        $this->post('/customer/register', $this->registration(['email' => ['bad']]))->assertSessionHasErrors('email');
        $this->post('/customer/login', ['email' => ['bad'], 'password' => 'password123'])->assertSessionHasErrors('email');
        $this->actingAs(User::factory()->create(), 'web')->post('/customers', ['full_name' => 'Malformed', 'email' => ['bad']])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('customer_accounts', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_staff_setup_command_rejects_unhashable_password(): void
    {
        $this->artisan('pos:create-staff')->expectsQuestion('Username', 'setupuser')->expectsQuestion('Full name', 'Setup User')->expectsQuestion('Password (at least 8 characters)', str_repeat('x', 73))->expectsOutput('The password must be at most 72 bytes and cannot contain null characters.')->assertExitCode(1);
        $this->assertDatabaseMissing('users', ['username' => 'setupuser']);
    }
}
