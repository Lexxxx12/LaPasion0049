<?php

namespace Tests\Feature;

use App\Actions\RecordSale;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompletionFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function sale(?CustomerAccount $account = null, ?Product $product = null, ?User $staff = null): Sale
    {
        $product ??= Product::factory()->create();
        $staff ??= User::factory()->create();
        app(RecordSale::class)->execute(['product_id' => $product->id, 'customer_id' => $account?->customer_id, 'quantity' => 2, 'submission_token' => (string) Str::uuid()], $staff->id);

        return Sale::latest('id')->first();
    }

    public function test_customer_history_only_contains_their_purchases(): void
    {
        $customer = CustomerAccount::factory()->create();
        $other = CustomerAccount::factory()->create();
        $own = $this->sale($customer);
        $others = $this->sale($other);
        $walkin = $this->sale();
        $this->actingAs($customer, 'customer')->get('/customer/purchases')->assertOk()->assertSee($own->receiptNumber())->assertDontSee($others->receiptNumber())->assertDontSee($walkin->receiptNumber())->assertViewHas('sales', fn ($sales) => $sales->total() === 1);
    }

    public function test_receipts_enforce_ownership_and_support_printing(): void
    {
        $customer = CustomerAccount::factory()->create();
        $own = $this->sale($customer);
        $other = $this->sale(CustomerAccount::factory()->create());
        $this->actingAs($customer, 'customer')->get('/customer/receipts/'.$own->id)->assertOk()->assertSee('Print receipt')->assertSee('39.98')->assertSee($own->receiptNumber());
        $this->get('/customer/receipts/'.$other->id)->assertNotFound();
        $this->get('/receipts/'.$own->id)->assertRedirect('/login');
        $this->actingAs(User::factory()->create(), 'web')->get('/receipts/'.$own->id)->assertOk()->assertSee($own->receiptNumber());
    }

    public function test_receipts_keep_original_names_and_prices_after_records_change(): void
    {
        $customer = CustomerAccount::factory()->create();
        $product = Product::factory()->create(['name' => 'Original collection', 'price' => '19.99']);
        $sale = $this->sale($customer, $product);
        $product->update(['name' => 'Renamed collection', 'price' => '999.00']);
        $customer->customer->update(['full_name' => 'Updated customer']);
        $this->actingAs($customer, 'customer')->get('/customer/receipts/'.$sale->id)->assertOk()->assertSee('Original collection')->assertSee('19.99')->assertSee('39.98')->assertDontSee('Renamed collection');
        $this->assertSame('Original collection', $sale->fresh()->recordedProduct());
    }

    public function test_profile_update_does_not_accept_email_account_id_or_permissions(): void
    {
        $account = CustomerAccount::factory()->create();
        $original = $account->email;
        $this->actingAs($account, 'customer')->put('/customer/account', ['full_name' => 'New name', 'phone' => '12345', 'email' => 'attacker@example.com', 'is_active' => false, 'customer_id' => 999])->assertRedirect('/customer/account');
        $this->assertDatabaseHas('customers', ['id' => $account->customer_id, 'full_name' => 'New name', 'phone' => '12345', 'email' => $original]);
        $this->assertSame($original, $account->fresh()->email);
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_invalid_profile_leaves_data_unchanged(): void
    {
        $account = CustomerAccount::factory()->create();
        $name = $account->customer->full_name;
        $this->actingAs($account, 'customer')->put('/customer/account', ['full_name' => '', 'phone' => str_repeat('1', 21)])->assertSessionHasErrors(['full_name', 'phone']);
        $this->assertSame($name, $account->customer->fresh()->full_name);
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer');
        $this->put('/customer/password', ['current_password' => 'wrong', 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertSessionHasErrors('current_password');
        $this->put('/customer/password', ['current_password' => 'password123', 'password' => 'newpassword123', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password123', $account->fresh()->password));
    }

    public function test_password_change_keeps_current_session_and_revokes_old_sessions(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer')->withSession(['customer_auth_version' => 1]);
        $this->put('/customer/password', ['current_password' => 'password123', 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertRedirect('/customer/account')->assertSessionHas('customer_auth_version', 2);
        $this->assertTrue(Hash::check('newpassword123', $account->fresh()->password));
        $this->get('/customer/account')->assertOk();
        $this->withSession(['customer_auth_version' => 1])->get('/customer/account')->assertRedirect('/customer/login');
    }

    public function test_staff_can_pause_and_restore_login_without_losing_purchases(): void
    {
        $account = CustomerAccount::factory()->create();
        $sale = $this->sale($account);
        $this->actingAs(User::factory()->create(), 'web');
        $this->post('/customers/'.$account->customer_id.'/access', ['is_active' => 0])->assertRedirect();
        $this->assertFalse($account->fresh()->is_active);
        $this->post('/customer/login', ['email' => $account->email, 'password' => 'password123'])->assertSessionHasErrors('email');
        $this->post('/customers/'.$account->customer_id.'/access', ['is_active' => 1])->assertRedirect();
        $this->assertTrue($account->fresh()->is_active);
        $this->post('/customer/login', ['email' => $account->email, 'password' => 'password123'])->assertRedirect('/customer/account');
        $this->get('/customer/purchases')->assertOk()->assertSee($sale->receiptNumber());
    }

    public function test_old_customer_sessions_do_not_resume_after_reactivation(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs(User::factory()->create(), 'web');
        $this->post('/customers/'.$account->customer_id.'/access', ['is_active' => 0]);
        $this->post('/customers/'.$account->customer_id.'/access', ['is_active' => 1]);
        $this->actingAs($account, 'customer')->withSession(['customer_auth_version' => 1])->get('/customer/account')->assertRedirect('/customer/login');
    }

    public function test_staff_can_restore_a_deleted_customer_and_their_purchases(): void
    {
        $account = CustomerAccount::factory()->create();
        $sale = $this->sale($account);
        $this->actingAs(User::factory()->create(), 'web');
        $this->delete('/customers/'.$account->customer_id)->assertRedirect();
        $this->get('/customers?status=deleted')->assertOk()->assertSee($account->email);
        $this->post('/customers/'.$account->customer_id.'/restore')->assertRedirect('/customers');
        $this->assertNotSoftDeleted('customers', ['id' => $account->customer_id]);
        $this->assertTrue($account->fresh()->is_active);
        $this->post('/customer/login', ['email' => $account->email, 'password' => 'password123'])->assertRedirect('/customer/account');
        $this->get('/customer/purchases')->assertOk()->assertSee($sale->receiptNumber());
    }

    public function test_customer_cannot_change_account_status_or_restore_records(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer');
        $this->post('/customers/'.$account->customer_id.'/access', ['is_active' => 0])->assertRedirect('/login');
        $this->post('/customers/'.$account->customer_id.'/restore')->assertRedirect('/login');
        $this->assertTrue($account->fresh()->is_active);
    }

    public function test_account_status_without_login_returns_a_clear_error(): void
    {
        $customer = Customer::create(['full_name' => 'Walk in', 'email' => 'walkin@example.com']);
        $this->actingAs(User::factory()->create(), 'web');
        $this->post('/customers/'.$customer->id.'/access', ['is_active' => 0])->assertSessionHasErrors('account');
    }

    public function test_management_searches_and_pagination_preserve_filters(): void
    {
        Product::factory()->count(14)->create(['name' => 'Findable collection']);
        Product::factory()->create(['name' => 'Hidden collection']);
        $this->actingAs(User::factory()->create(['full_name' => 'Searchable staff']), 'web');
        $this->get('/products?q=Findable')->assertOk()->assertSee('Findable collection')->assertDontSee('Hidden collection')->assertSee('q=Findable', false)->assertViewHas('records', fn ($records) => $records->total() === 14);
        $this->get('/products?q=Findable&page=2')->assertOk()->assertViewHas('records', fn ($records) => $records->count() === 2);
        $account = CustomerAccount::factory()->create();
        $this->get('/customers?q='.urlencode($account->email))->assertOk()->assertSee($account->email);
        $this->get('/staff?q=Searchable')->assertOk()->assertSee('Searchable staff');
        $this->get('/products?q[]=invalid')->assertSessionHasErrors('q');
    }

    public function test_sales_filters_combine_product_customer_and_dates(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
        $account = CustomerAccount::factory()->create();
        $product = Product::factory()->create(['name' => 'Filter collection']);
        $wanted = $this->sale($account, $product);
        $this->sale();
        $this->actingAs(User::factory()->create(), 'web')->get('/sales?product_id='.$product->id.'&customer_id='.$account->customer_id.'&from=2026-10-10&to=2026-10-10')->assertOk()->assertViewHas('sales', fn ($sales) => $sales->total() === 1)->assertSee($wanted->receiptNumber());
        $this->get('/sales?to=2026-10-11')->assertOk();
        $this->get('/sales?from=2026-10-11&to=2026-10-10')->assertSessionHasErrors('to');
        $this->get('/sales?from=not-a-date')->assertSessionHasErrors('from');
    }

    public function test_purchase_search_cannot_escape_customer_scope(): void
    {
        $customer = CustomerAccount::factory()->create();
        $other = CustomerAccount::factory()->create();
        $own = $this->sale($customer, Product::factory()->create(['name' => 'Own product']));
        $foreign = $this->sale($other, Product::factory()->create(['name' => 'Secret product']));
        $this->actingAs($customer, 'customer')->get('/customer/purchases?q=Secret')->assertOk()->assertDontSee($foreign->receiptNumber())->assertViewHas('sales', fn ($sales) => $sales->total() === 0);
        $this->get('/customer/purchases?to=2099-01-01&q='.$own->receiptNumber())->assertOk()->assertSee($own->receiptNumber());
        $this->get('/customer/purchases?from=2026-10-11&to=2026-10-10')->assertSessionHasErrors('to');
    }

    public function test_archived_product_receipts_and_history_remain_available(): void
    {
        $customer = CustomerAccount::factory()->create();
        $product = Product::factory()->create(['name' => 'Archived collection']);
        $sale = $this->sale($customer, $product);
        $product->delete();
        $this->actingAs($customer, 'customer')->get('/customer/receipts/'.$sale->id)->assertOk()->assertSee('Archived collection');
        $this->get('/customer/purchases')->assertOk()->assertSee($sale->receiptNumber());
    }

    public function test_search_accepts_zero_as_a_real_search_term(): void
    {
        Product::factory()->create(['name' => 'Edition 0']);
        Product::factory()->create(['name' => 'Edition A']);
        $this->actingAs(User::factory()->create(), 'web')->get('/products?q=0')->assertOk()->assertViewHas('records', fn ($records) => $records->total() === 1)->assertSee('Edition 0')->assertDontSee('Edition A');
    }

    public function test_password_change_rejects_invalid_current_password_bytes(): void
    {
        $account = CustomerAccount::factory()->create();
        $this->actingAs($account, 'customer')->put('/customer/password', ['current_password' => "bad\0password", 'password' => 'newpassword123', 'password_confirmation' => 'newpassword123'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('password123', $account->fresh()->password));
    }
}
