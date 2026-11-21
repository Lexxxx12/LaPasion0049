@extends('layout',['title'=>($record->exists?'Edit ':'Add ').($kind==='staff'?'staff member':Str::singular($kind))])

@section('actions')<a class="button secondary" href="{{ route('records.index', ['kind'=>$kind]) }}"><x-icon name="back" :size="17"/> Back to {{ $kind }}</a>@endsection
@section('content')
<form method="post" enctype="multipart/form-data" action="{{ $record->exists ? route('records.update', ['kind'=>$kind, 'id'=>$record->id]) : route('records.store', ['kind'=>$kind]) }}" data-submit>
@csrf @if($record->exists) @method('put')<input type="hidden" name="version" value="{{ \App\Support\OldInput::value('version',($kind==='products'?$record->version():'')) }}">@endif
<div class="form-layout {{ $image?'':'form-layout--single' }}"><section class="panel"><div class="section-heading"><span class="section-icon"><x-icon :name="$kind==='staff'?'staff':$kind"/></span><div><h2>{{ $kind==='products'?'Product details':($kind==='customers'?'Customer details':'Staff details') }}</h2></div></div><div class="panel-body form-grid">
@foreach($fields as $field)
<div @class(['field','field-wide'=>in_array($field,['name','full_name','password'])])>
<label for="{{ $field }}">{{ ucfirst(str_replace('_',' ',$field)) }} @if($field==='phone')<span class="optional">Optional</span>@endif @if($field==='password'&&($record->exists||$kind==='customers'))<span class="optional">Optional</span>@endif</label>
@if($field==='password')<div class="password-field">@endif
<input id="{{ $field }}" name="{{ $field }}" type="{{ match($field){'password'=>'password','email'=>'email','phone'=>'tel','price','stock_quantity'=>'number',default=>'text'} }}" @if(in_array($field,['price','stock_quantity'])) min="0" inputmode="{{ $field==='price'?'decimal':'numeric' }}" step="{{ $field==='price'?'0.01':'1' }}" @endif value="{{ $field==='password'?'':\App\Support\OldInput::value($field,$record->$field) }}" @if(!in_array($field,['phone','password'])||($field==='password'&&!$record->exists&&$kind!=='customers')) required @endif @if($field==='password') autocomplete="new-password" @endif @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
@if($field==='password')<button type="button" data-password-toggle="password" aria-label="Show password"><x-icon name="eye" :size="19"/></button></div><p class="field-help">{{ $kind==='customers'?'Set a password to enable online login. Leave blank to preserve existing access.':($record->exists?'Leave blank to keep the current password.':'Use at least 8 characters.') }}</p>@endif
@error($field)<p class="field-error" id="{{ $field }}-error">{{ $message }}</p>@enderror
</div>
@if($field==='password')<div class="field field-wide"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password"></div>@endif
@endforeach
</div></section>
<aside class="form-side">
@if($image)
<section class="panel upload-panel"><div class="panel-title"><h2>{{ $kind==='products'?'Product image':'Staff avatar' }}</h2><p>{{ $kind==='products'?'Used in the product list and sales counter.':'Optional profile image.' }}</p></div>
<div class="image-preview-wrap"><img id="upload-preview" class="upload-preview {{ $kind==='staff'?'round':'' }}" @if($record->$image) src="{{ Storage::disk('public')->url($record->$image) }}" @else hidden @endif alt="{{ $kind==='products'?'Product image preview':'Staff avatar preview' }}"><div id="upload-placeholder" class="upload-placeholder" @if($record->$image) hidden @endif>@if($kind==='staff')<x-avatar class="avatar-preview" :name="$record->full_name ?? 'Staff'"/>@else<x-icon name="products" :size="48"/>@endif</div></div>
<label class="upload-zone" for="{{ $image }}"><x-icon name="upload" :size="21"/><strong>Choose {{ $image==='image'?'an image':'an avatar' }}</strong><span>JPEG, PNG or WebP, up to 4 MB</span><input id="{{ $image }}" type="file" name="{{ $image }}" accept="image/jpeg,image/png,image/webp" data-image-preview></label><p id="upload-filename" class="field-help" aria-live="polite">{{ $record->$image?'Upload a new file to replace the current image.':'Optional. You can add an image later.' }}</p></section>
@endif
</aside></div><div class="form-footer"><div class="button-row"><a class="button secondary" href="{{ route('records.index', ['kind'=>$kind]) }}">Cancel</a><button type="submit" class="button primary"><x-icon name="check" :size="17"/> Save {{ $kind==='staff'?'staff member':Str::singular($kind) }}</button></div></div>
</form>
@endsection
