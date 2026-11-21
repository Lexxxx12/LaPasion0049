<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_form_keeps_submitted_details_after_validation_fails(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/products/create')->post('/products', [
            'name' => 'Travel notebook',
            'price' => '125.50',
            'stock_quantity' => '-1',
        ])->assertRedirect('/products/create')->assertSessionHasErrors('stock_quantity');

        $this->get('/products/create')->assertOk()
            ->assertSee('value="Travel notebook"', false)
            ->assertSee('value="125.50"', false)
            ->assertSee('value="-1"', false);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_registration_retains_contact_details_but_never_passwords(): void
    {
        $this->from('/customer/register')->post('/customer/register', [
            'full_name' => 'Sofia Mercado',
            'email' => 'sofia@example.com',
            'phone' => '09123456789',
            'password' => 'SecretPass123',
            'password_confirmation' => 'mismatch',
        ])->assertRedirect('/customer/register')->assertSessionHasErrors('password');

        $this->get('/customer/register')->assertOk()
            ->assertSee('value="Sofia Mercado"', false)
            ->assertSee('value="sofia@example.com"', false)
            ->assertSee('value="09123456789"', false)
            ->assertDontSee('SecretPass123')
            ->assertDontSee('mismatch');
        $this->assertDatabaseCount('customer_accounts', 0);
    }

    public function test_staff_without_an_avatar_sees_the_default_profile_picture(): void
    {
        $this->actingAs(User::factory()->create(['full_name' => 'Mariana Reyes', 'avatar' => null]));

        $this->get('/staff')->assertOk()->assertSee('Mariana Reyes: no profile picture');
    }

    public function test_uploaded_staff_avatar_is_used_in_navigation(): void
    {
        $this->actingAs(User::factory()->create(['full_name' => 'Mariana Reyes', 'avatar' => 'staff/profile.webp']));

        $this->get('/sales/create')->assertOk()
            ->assertSee('/storage/staff/profile.webp', false)
            ->assertSee('Mariana Reyes profile picture')
            ->assertDontSee('Mariana Reyes: no profile picture');
    }

    public function test_sale_validation_renders_a_field_error_without_recording_a_sale(): void
    {
        $staff = User::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 5]);
        $this->actingAs($staff)->get(route('sales.create'))->assertOk();

        $this->from(route('sales.create'))->post(route('sales.store'), [
            'product_id' => $product->id,
            'quantity' => 0,
            'submission_token' => fake()->uuid(),
        ])->assertRedirect(route('sales.create'))->assertSessionHasErrors('quantity');

        $this->get(route('sales.create'))->assertOk();
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 5]);
    }
}
