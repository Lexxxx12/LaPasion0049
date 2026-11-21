@extends('layout',['title'=>ucfirst($kind)])
@section('subtitle'){{ match($kind){'products'=>'Manage product prices, images, and stock.','customers'=>'Manage customer details and login access.','staff'=>'Manage staff accounts and workspace access.'} }}@endsection
@section('actions')<a class="button primary" href="{{ route('records.create', ['kind'=>$kind]) }}"><x-icon name="plus" :size="18"/> Add {{ $kind==='staff'?'staff member':Str::singular($kind) }}</a>@endsection
@section('content')
<section class="table-card">
<div class="table-toolbar"><div class="table-title"><h2>{{ $kind==='products'?'Products':($kind==='customers'?'Customers':'Staff') }}</h2><span class="count-badge">{{ $total }}</span></div><form method="get" class="search-field"><x-icon name="search" :size="18"/><input name="q" value="{{ $filters['q']??'' }}" aria-label="Search {{ $kind }}" placeholder="Search {{ $kind }}..."><input type="hidden" name="status" value="{{ $status }}"><button class="icon-button" aria-label="Search"><x-icon name="arrow" :size="17"/></button></form></div>
@if($kind!=='staff')<nav class="tabs" aria-label="Record status">
@foreach($kind==='customers'?['active'=>'All customers','disabled'=>'Login paused','deleted'=>'Deleted']:['active'=>'Active products','archived'=>'Archived'] as $key=>$label)
<a href="/{{ $kind }}?{{ http_build_query(array_filter(['status'=>$key,'q'=>$filters['q']??''])) }}" @class(['is-active'=>$status===$key]) @if($status===$key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>@endif
@if($records->count())
<p class="table-scroll-hint">Swipe across the table to see all details and actions. <x-icon name="arrow" :size="13"/></p><div class="table-scroll"><table>
<thead><tr><th>{{ $kind==='products'?'Product':($kind==='staff'?'Staff member':'Customer') }}</th>
@if($kind==='products')<th>Price</th><th>Inventory</th><th>Status</th>@elseif($kind==='customers')<th>Phone</th><th>Online account</th><th>Joined</th>@else<th>Username</th><th>Added</th>@endif<th class="align-right">Actions</th></tr></thead>
<tbody>@foreach($records as $record)
<tr>
<td><div class="identity-cell">
@if($image && $record->$image)<img class="record-image {{ $kind==='staff'?'round':'' }}" src="{{ Storage::disk('public')->url($record->$image) }}" alt="{{ $record->name??$record->full_name }}" loading="lazy">
@elseif($kind==='products')<span class="record-image image-placeholder"><x-icon name="products" :size="22"/></span>
@else<x-avatar :name="$record->full_name"/>@endif
<div><strong>{{ $record->name??$record->full_name }}</strong><small>{{ $kind==='customers'?$record->email:($kind==='staff'?'Staff member':'Product #'.str_pad($record->id,4,'0',STR_PAD_LEFT)) }}</small></div></div></td>
@if($kind==='products')
<td class="numeric price-cell">{{ number_format($record->price,2) }}</td><td><span class="numeric">{{ number_format($record->stock_quantity) }}</span> <span class="muted">units</span></td><td><span @class(['badge','badge-muted'=>$record->trashed(),'badge-success'=>!$record->trashed()&&$record->stock_quantity>0,'badge-warning'=>!$record->trashed()&&$record->stock_quantity===0])><span></span>{{ $record->trashed()?'Archived':($record->stock_quantity>0?'In stock':'Out of stock') }}</span></td>
@elseif($kind==='customers')
<td>{{ $record->phone??'Not provided' }}</td><td><span @class(['badge','badge-success'=>$record->account?->is_active&&!$record->trashed(),'badge-muted'=>!$record->account||$record->trashed(),'badge-warning'=>$record->account&&!$record->account->is_active&&!$record->trashed()])><span></span>{{ $record->trashed()?'Deleted':($record->account?($record->account->is_active?'Registered':'Login paused'):'No login') }}</span></td><td class="muted">{{ $record->created_at->format('M d, Y') }}</td>
@else<td><span class="username">{{ $record->username }}</span></td><td class="muted">{{ $record->created_at->format('M d, Y') }}</td>@endif
<td><div class="row-actions">
@if($record->trashed())
@if($kind==='customers')<form method="post" action="{{ route('customers.restore',$record->id) }}">@csrf<button class="button secondary small">Restore customer</button></form>@else<span class="muted">Archived</span>@endif
@else
<a class="button ghost small" href="{{ route('records.edit', ['kind'=>$kind, 'id'=>$record->id]) }}"><x-icon name="edit" :size="15"/> Edit</a>
<details class="action-menu"><summary aria-label="More actions for {{ $record->name??$record->full_name }}">&bull;&bull;&bull;</summary><div class="action-menu-content">
@if($kind==='customers'&&$record->account)<form method="post" action="{{ route('customers.access',$record->id) }}" @if($record->account->is_active) data-confirm="Pause this customer's login? Their details and purchase history will be kept." @endif>@csrf<input type="hidden" name="is_active" value="{{ $record->account->is_active?'0':'1' }}"><button>{{ $record->account->is_active?'Deactivate login':'Restore login' }}</button></form>@endif
<form method="post" action="{{ route('records.destroy', ['kind'=>$kind, 'id'=>$record->id]) }}" data-confirm="{{ $kind==='products'?'Archive this product? It will no longer be available at the sales counter.':'Delete this record? Existing sales will be preserved.' }}">@csrf @method('delete')<button class="text-danger">{{ $kind==='products'?'Archive product':'Delete '.($kind==='staff'?'staff member':'customer') }}</button></form>
</div></details>
@endif
</div></td></tr>
@endforeach</tbody></table></div>
@else
<div class="empty-state"><span class="empty-icon"><x-icon :name="$kind==='staff'?'staff':$kind" :size="32"/></span><h3>{{ filled($filters['q']??null)?'No matching '.$kind:($status==='deleted'?'No deleted customers':($status==='disabled'?'No paused accounts':($status==='archived'?'No archived products':'No '.$kind.' added'))) }}</h3><p>{{ filled($filters['q']??null)?'Try another name or clear your search.':match($kind){'products'=>'Add a product with a price and stock to start selling.','customers'=>'Customer registrations appear here. You can also add a customer yourself.',default=>'Add a staff member to give them access to the workspace.'} }}</p>
@if(filled($filters['q']??null))<a class="button secondary" href="{{ route('records.index', ['kind'=>$kind]) }}">Clear search</a>@elseif($status==='active')<a class="button secondary" href="{{ route('records.create', ['kind'=>$kind]) }}"><x-icon name="plus" :size="17"/> Add {{ $kind==='staff'?'staff member':Str::singular($kind) }}</a>@endif</div>
@endif
<x-pagination :paginator="$records"/>
</section>
@endsection
