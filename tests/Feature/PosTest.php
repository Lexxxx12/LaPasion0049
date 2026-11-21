<?php

namespace Tests\Feature;

use App\Actions\RecordSale;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

class PosTest extends TestCase
{
    use RefreshDatabase;

    private function staff()
    {
        return User::create(['username' => 'staff'.Str::random(8), 'full_name' => 'Test Staff', 'password' => 'password123']);
    }

    private function product()
    {
        return Product::create(['name' => 'Coffee', 'price' => '19.99', 'stock_quantity' => 10]);
    }

    private function submit($p, $quantity = 3, $extra = [])
    {
        $token = (string) Str::uuid();

        return $this->withSession(['sale_tokens' => [$token => true]])->post('/sales', array_replace(['product_id' => $p->id, 'quantity' => $quantity, 'submission_token' => $token], $extra));
    }

    public function test_management_requires_login(): void
    {
        foreach (['/products', '/customers', '/staff', '/sales', '/sales/create', '/staff/create'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }$this->post('/products', [])->assertRedirect('/login');
    }

    public function test_login_and_logout(): void
    {
        $u = $this->staff();
        $this->post('/login', ['username' => $u->username, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->post('/login', ['username' => $u->username, 'password' => 'password123'])->assertRedirect('/sales/create');
        $this->assertAuthenticatedAs($u);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_all_pages_render(): void
    {
        $this->actingAs($this->staff());
        foreach (['/products', '/products/create', '/customers', '/customers/create', '/staff', '/staff/create', '/sales', '/sales/create'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_sale_deducts_stock_and_ignores_forged_total(): void
    {
        $u = $this->staff();
        $this->actingAs($u);
        $p = $this->product();
        $this->submit($p, 3, ['total_price' => '0.01', 'sold_by' => 999])->assertRedirect('/sales');
        $this->assertDatabaseHas('sales', ['total_price' => '59.97', 'sold_by' => $u->id, 'quantity' => 3]);
        $this->assertEquals(7, $p->fresh()->stock_quantity);
    }

    public function test_stock_boundary_and_invalid_quantity(): void
    {
        $this->actingAs($this->staff());
        $p = $this->product();
        foreach ([11, 0, -1, '1.5'] as $q) {
            $this->submit($p, $q)->assertSessionHasErrors();
        }$this->assertDatabaseCount('sales', 0);
        $this->assertEquals(10, $p->fresh()->stock_quantity);
        $this->submit($p, 10)->assertRedirect('/sales');
        $this->assertEquals(0, $p->fresh()->stock_quantity);
    }

    public function test_duplicate_submission_is_recorded_once(): void
    {
        $this->actingAs($this->staff());
        $p = $this->product();
        $t = (string) Str::uuid();
        $data = ['product_id' => $p->id, 'quantity' => 2, 'submission_token' => $t];
        $this->withSession(['sale_tokens' => [$t => true]])->post('/sales', $data)->assertRedirect('/sales');
        $this->post('/sales', $data)->assertRedirect('/sales');
        $this->assertDatabaseCount('sales', 1);
        $this->assertEquals(8, $p->fresh()->stock_quantity);
    }

    public function test_customer_crud_and_history_preservation(): void
    {
        $this->actingAs($this->staff());
        $this->post('/customers', ['full_name' => 'Customer', 'email' => 'a@example.com'])->assertRedirect('/customers');
        $c = Customer::first();
        $this->put('/customers/'.$c->id, ['full_name' => 'Updated', 'email' => 'b@example.com', 'phone' => '09123'])->assertRedirect('/customers');
        $this->submit($this->product(), 1, ['customer_id' => $c->id])->assertRedirect('/sales');
        $this->delete('/customers/'.$c->id)->assertRedirect();
        $this->assertSoftDeleted($c);
        $this->get('/sales')->assertOk()->assertSee('Updated');
    }

    public function test_staff_password_edit_and_delete(): void
    {
        $u = $this->staff();
        $this->actingAs($u);
        $this->post('/staff', ['username' => 'cashier', 'full_name' => 'Cashier', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertRedirect('/staff');
        $other = User::where('username', 'cashier')->first();
        $this->assertTrue(Hash::check('password123', $other->password));
        $this->put('/staff/'.$other->id, ['username' => 'cashier', 'full_name' => 'Edited', 'password' => ''])->assertRedirect('/staff');
        $this->assertTrue(Hash::check('password123', $other->fresh()->password));
        $this->delete('/staff/'.$other->id)->assertRedirect();
        $this->post('/logout');
        $this->post('/login', ['username' => 'cashier', 'password' => 'password123'])->assertSessionHasErrors();
    }

    public function test_last_staff_is_protected(): void
    {
        $u = $this->staff();
        $this->actingAs($u)->delete('/staff/'.$u->id)->assertSessionHasErrors('delete');
        $this->assertNotSoftDeleted($u);
    }

    public function test_product_edit_and_archive(): void
    {
        $this->actingAs($this->staff());
        $this->post('/products', ['name' => 'Coffee', 'price' => '19.99', 'stock_quantity' => 10])->assertRedirect('/products');
        $p = Product::first();
        $this->get('/products/'.$p->id.'/edit')->assertOk();
        $this->put('/products/'.$p->id, ['name' => 'Tea', 'price' => '20.00', 'stock_quantity' => 9, 'version' => $p->version()])->assertRedirect('/products');
        $this->delete('/products/'.$p->id)->assertRedirect();
        $this->submit($p)->assertSessionHasErrors('product_id');
    }

    public function test_invalid_customer_cannot_change_stock(): void
    {
        $this->actingAs($this->staff());
        $p = $this->product();
        $this->submit($p, 1, ['customer_id' => 999])->assertSessionHasErrors('customer_id');
        $this->assertEquals(10, $p->fresh()->stock_quantity);
    }

    public function test_image_upload_and_replacement(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff());
        $this->post('/products', ['name' => 'Photo', 'price' => 10, 'stock_quantity' => 2, 'image' => UploadedFile::fake()->image('photo.jpg', 40, 40)])->assertRedirect('/products');
        $p = Product::first();
        Storage::disk('public')->assertExists($p->image);
        $old = $p->image;
        $this->put('/products/'.$p->id, ['name' => 'Photo', 'price' => 10, 'stock_quantity' => 2, 'version' => $p->version(), 'image' => UploadedFile::fake()->image('next.png')])->assertRedirect('/products');
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($p->fresh()->image);
    }

    public function test_stale_product_edit_cannot_overwrite_sale_stock(): void
    {
        $this->actingAs($this->staff());
        $p = $this->product();
        $version = $p->version();
        $this->submit($p, 3)->assertRedirect('/sales');
        $this->put('/products/'.$p->id, ['name' => 'Coffee', 'price' => '19.99', 'stock_quantity' => 10, 'version' => $version])->assertSessionHasErrors('stock_quantity');
        $this->assertEquals(7, $p->fresh()->stock_quantity);
    }

    public function test_failure_after_sale_insert_rolls_back_everything(): void
    {
        $u = $this->staff();
        $p = $this->product();
        Product::updating(function () {
            throw new \RuntimeException('Simulated storage failure');
        });
        try {
            app(RecordSale::class)->execute(['product_id' => $p->id, 'quantity' => 2, 'submission_token' => (string) Str::uuid()], $u->id);
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertEquals('Simulated storage failure', $e->getMessage());
        } finally {
            Product::flushEventListeners();
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertEquals(10, $p->fresh()->stock_quantity);
    }

    public function test_avatar_upload_and_invalid_file(): void
    {
        Storage::fake('public');
        $this->actingAs($this->staff());
        $data = ['username' => 'avataruser', 'full_name' => 'Avatar User', 'password' => 'password123', 'password_confirmation' => 'password123', 'avatar' => UploadedFile::fake()->image('avatar.png')];
        $this->post('/staff', $data)->assertRedirect('/staff');
        $u = User::where('username', 'avataruser')->first();
        Storage::disk('public')->assertExists($u->avatar);
        $this->post('/products', ['name' => 'Bad image', 'price' => 1, 'stock_quantity' => 1, 'image' => UploadedFile::fake()->create('script.php', 1, 'text/plain')])->assertSessionHasErrors('image');
    }

    public function test_sale_action_rejects_deleted_staff_without_changing_stock(): void
    {
        $staff = $this->staff();
        $product = $this->product();
        $staff->delete();
        try {
            app(RecordSale::class)->execute(['product_id' => $product->id, 'quantity' => 1, 'submission_token' => (string) Str::uuid()], $staff->id);
            $this->fail('Expected rejection');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_action_does_not_repeat_sale_or_trust_supplied_totals(): void
    {
        $staff = $this->staff();
        $product = $this->product();
        $data = ['product_id' => $product->id, 'quantity' => 2, 'submission_token' => (string) Str::uuid(), 'total_price' => '0.01', 'sold_by' => 999];
        app(RecordSale::class)->execute($data, $staff->id);
        app(RecordSale::class)->execute($data, $staff->id);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', ['sold_by' => $staff->id, 'total_price' => '39.98']);
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_staff_passwords_reject_bcrypt_invalid_values(): void
    {
        $this->actingAs($this->staff());
        $password = str_repeat('x', 73);
        $this->post('/staff', ['username' => 'longpassword', 'full_name' => 'Long password', 'password' => $password, 'password_confirmation' => $password])->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'longpassword']);
    }

    public function test_failed_image_storage_preserves_existing_product_and_image(): void
    {
        $disk = Storage::fake('public');
        $disk->put('products/original.webp', 'existing');
        $this->actingAs($this->staff());
        $product = $this->product();
        $product->update(['image' => 'products/original.webp']);
        Storage::shouldReceive('disk')->with('public')->andThrow(UnableToWriteFile::atLocation('products/new.webp'));
        $this->put('/products/'.$product->id, ['name' => 'Changed', 'price' => 10, 'stock_quantity' => 2, 'version' => $product->fresh()->version(), 'image' => UploadedFile::fake()->image('new.png')])->assertSessionHasErrors('image');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'name' => 'Coffee', 'image' => 'products/original.webp', 'stock_quantity' => 10]);
        $disk->assertExists('products/original.webp');
    }
}
