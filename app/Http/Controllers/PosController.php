<?php

namespace App\Http\Controllers;

use App\Actions\RecordSale;
use App\Http\Requests\RecordSaleRequest;
use App\Http\Requests\SaveRecordRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Rules\PasswordBytes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Image\ImageException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\FilesystemException;

class PosController extends Controller
{
    private function entity(string $kind): array
    {
        return match ($kind) {
            'products' => [Product::class, ['name', 'price', 'stock_quantity'], 'image'], 'customers' => [Customer::class, ['full_name', 'email', 'phone', 'password'], null], 'staff' => [User::class, ['username', 'full_name', 'password'], 'avatar'], default => abort(404)
        };
    }

    public function login(Request $r)
    {
        if ($r->isMethod('get')) {
            return view('login');
        }
        $data = $r->validate(['username' => 'required|string', 'password' => ['required', 'string', new PasswordBytes]]);
        $key = Str::lower($data['username']).'|'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['username' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        if (! Auth::attempt($data)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();

        return redirect()->intended(route('sales.create'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function index(Request $request, string $kind)
    {
        [$model,$fields,$image] = $this->entity($kind);
        $statuses = $kind === 'customers' ? 'active,disabled,deleted' : ($kind === 'products' ? 'active,archived' : 'active');
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:'.$statuses]);
        $status = $filters['status'] ?? 'active';
        $query = $model::query();
        if ($kind === 'customers') {
            $query->with('account');
            if ($status === 'deleted') {
                $query->onlyTrashed();
            } elseif ($status === 'disabled') {
                $query->whereHas('account', fn ($account) => $account->where('is_active', false));
            }
        }
        if ($kind === 'products' && $status === 'archived') {
            $query->onlyTrashed();
        }
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $columns = match ($kind) {
                'products' => ['name'],'customers' => ['full_name', 'email', 'phone'],default => ['full_name', 'username']
            };
            $query->where(function ($builder) use ($q, $columns) {
                foreach ($columns as $column) {
                    $builder->orWhere($column, 'like', '%'.$q.'%');
                }
            });
        }

        return view('records', compact('kind', 'fields', 'image', 'filters', 'status') + ['records' => $query->latest('id')->paginate(12)->withQueryString(), 'total' => $model::count()]);
    }

    public function form(string $kind, ?int $id = null)
    {
        [$model,$fields,$image] = $this->entity($kind);
        $record = $id ? $model::findOrFail($id) : new $model;

        return view('form', compact('kind', 'fields', 'image', 'record'));
    }

    public function save(SaveRecordRequest $r, string $kind, ?int $id = null)
    {
        [$model,$fields,$image] = $this->entity($kind);
        $record = $id ? $model::findOrFail($id) : new $model;
        $data = $r->validated();
        unset($data['version']);
        if ($kind === 'staff' && empty($data['password'])) {
            unset($data['password']);
        }
        $newPath = null;
        $oldPath = $image ? $record->$image : null;
        try {
            if ($image && $r->hasFile($image)) {
                $newPath = $r->image($image)->cover($kind === 'staff' ? 400 : 800, $kind === 'staff' ? 400 : 800)->toWebp()->storePublicly($kind, 'public');
                $data[$image] = $newPath;
            }
            DB::transaction(function () use ($record, $data, $kind, $id, $r) {
                if ($id && $kind === 'products') {
                    $locked = Product::lockForUpdate()->findOrFail($id);
                    if ($locked->version() !== $r->input('version')) {
                        throw ValidationException::withMessages(['stock_quantity' => 'This product changed. Reload this page before saving.']);
                    }$locked->fill($data)->save();
                } else {
                    $attributes = $data;
                    if ($kind === 'customers') {
                        unset($attributes['password']);
                    }
                    $record->fill($attributes)->save();
                    if ($kind === 'customers') {
                        $account = $record->account;
                        if ($account) {
                            $account->email = $data['email'];
                            if (! empty($data['password'])) {
                                $account->password = $data['password'];
                                $account->session_version++;
                            }$account->save();
                        } elseif (! empty($data['password'])) {
                            $record->account()->create(['email' => $data['email'], 'password' => $data['password']]);
                        }
                    }
                }
            });
        } catch (\Throwable $e) {
            if ($newPath) {
                rescue(fn () => Storage::disk('public')->delete($newPath));
            }
            if ($e instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages([$kind === 'staff' ? 'username' : 'email' => 'That login identifier is already in use.']);
            }
            if ($image && ($e instanceof ImageException || $e instanceof FilesystemException)) {
                report($e);
                throw ValidationException::withMessages([$image => 'The image could not be saved. Please try a smaller image or contact staff.']);
            }
            throw $e;
        }
        if ($newPath && $oldPath) {
            rescue(fn () => Storage::disk('public')->delete($oldPath));
        }

        return redirect()->route('records.index', ['kind' => $kind])->with('status', 'Record saved.');
    }

    public function destroy(Request $r, string $kind, int $id)
    {
        [$model] = $this->entity($kind);
        DB::transaction(function () use ($model, $kind, $id) {
            if ($kind === 'staff') {
                $staff = User::orderBy('id')->lockForUpdate()->get();
                if ($staff->count() <= 1) {
                    throw ValidationException::withMessages(['delete' => 'The last staff account cannot be deleted.']);
                }
            }
            $record = $model::lockForUpdate()->findOrFail($id);
            if ($kind === 'customers' && $record->account) {
                $record->account->update(['is_active' => false, 'session_version' => $record->account->session_version + 1]);
            }
            $record->delete();
        });
        if ($kind === 'staff' && $id === $r->user()->id) {
            return $this->logout($r);
        }

        return back()->with('status', $kind === 'products' ? 'Product archived.' : 'Record deleted.');
    }

    public function saleForm(Request $r)
    {
        $token = (string) Str::uuid();
        $tokens = $r->session()->get('sale_tokens', []);
        $tokens[$token] = true;
        $r->session()->put('sale_tokens', array_slice($tokens, -30, null, true));

        return view('sale', ['products' => Product::orderBy('name')->get(), 'customers' => Customer::orderBy('full_name')->get(), 'token' => $token, 'recentSales' => Sale::with(['product', 'customer'])->latest('id')->limit(4)->get()]);
    }

    public function recordSale(RecordSaleRequest $r, RecordSale $recordSale)
    {
        $data = $r->validated();
        $existing = Sale::where('submission_token', $data['submission_token'])->where('sold_by', $r->user()->id)->first();
        if ($existing) {
            return redirect()->route('sales.index')->with('status', 'This sale was already recorded.');
        }
        if (! $r->session()->get('sale_tokens.'.$data['submission_token'])) {
            throw ValidationException::withMessages(['quantity' => 'This sale form expired. Open Record Sale again.']);
        }
        $recordSale->execute($data, $r->user()->id);

        return redirect()->route('sales.index')->with('status', 'Sale recorded. Stock updated.')->with('receipt_id', Sale::where('submission_token', $data['submission_token'])->value('id'));
    }

    public function history(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''), 'product_id' => 'nullable|integer|exists:products,id', 'customer_id' => 'nullable|integer|exists:customers,id']);
        $query = Sale::with(['product', 'customer', 'staff']);
        if (filled($filters['q'] ?? null)) {
            $q = $filters['q'];
            $receiptId = preg_match('/^(?:LP-)?0*(\d+)$/i', $q, $matches) ? (int) $matches[1] : 0;
            $query->where(fn ($builder) => $builder->where('product_name', 'like', '%'.$q.'%')->orWhere('customer_name', 'like', '%'.$q.'%')->orWhere('staff_name', 'like', '%'.$q.'%')->orWhere('id', $receiptId)->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$q.'%'))->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', '%'.$q.'%')));
        }
        foreach (['product_id', 'customer_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        $summary = ['count' => (clone $query)->count(), 'units' => (clone $query)->sum('quantity'), 'total' => (clone $query)->sum('total_price')];

        return view('history', ['sales' => $query->latest('id')->paginate(15)->withQueryString(), 'filters' => $filters, 'summary' => $summary, 'products' => Product::withTrashed()->orderBy('name')->get(), 'customers' => Customer::withTrashed()->orderBy('full_name')->get()]);
    }
}
