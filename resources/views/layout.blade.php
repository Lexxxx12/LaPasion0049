@php
 $isCustomer=request()->is('customer/*');
 $authPage=$authPage??false;
 $staff=auth('web')->user();
 $member=auth('customer')->user();
 $navigation=$isCustomer?[
 ['url'=>route('customer.shop'),'label'=>'Shop','icon'=>'products','active'=>request()->routeIs('customer.shop')],
 ['url'=>route('customer.cart'),'label'=>'Cart','icon'=>'bag','active'=>request()->routeIs('customer.cart')],
 ['url'=>route('customer.orders.index'),'label'=>'My orders','icon'=>'receipt','active'=>request()->routeIs('customer.orders.*')],
 ['url'=>route('customer.purchases'),'label'=>'My purchases','icon'=>'bag','active'=>request()->routeIs('customer.purchases','customer.receipts.*')],
 ['url'=>route('customer.account'),'label'=>'My account','icon'=>'user','active'=>request()->routeIs('customer.account')]
 ]:[
 ['url'=>route('orders.index'),'label'=>'Customer orders','icon'=>'bag','active'=>request()->routeIs('orders.*')],
 ['url'=>route('sales.create'),'label'=>'Sales counter','icon'=>'counter','active'=>request()->is('sales/create')],
 ['url'=>route('records.index', ['kind'=>'products']),'label'=>'Products','icon'=>'products','active'=>request()->is('products*')],
 ['url'=>route('records.index', ['kind'=>'customers']),'label'=>'Customers','icon'=>'customers','active'=>request()->is('customers*')],
 ['url'=>route('records.index', ['kind'=>'staff']),'label'=>'Staff','icon'=>'staff','active'=>request()->is('staff*')],
 ['url'=>route('sales.index'),'label'=>'Sales history','icon'=>'receipt','active'=>request()->is('sales')||request()->is('receipts/*')]
 ];
@endphp
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f4f6f5"><title>{{ $title ?? 'La pasión' }} | La pasión</title>
<link rel="icon" href="/brand.svg" type="image/svg+xml"><link rel="preload" href="/fonts/instrument-regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/pos.css?v=7">
</head>
<body class="{{ $authPage?'auth-page':'workspace' }} {{ $isCustomer?'customer-workspace':'' }}">
<a class="skip-link" href="#main">Skip to content</a>
@if(!$authPage)
<aside class="sidebar">
<a class="brand" href="{{ $isCustomer?route('customer.shop'):route('sales.create') }}"><img class="brand-logo" src="/brand/specaura.svg" alt="" width="48" height="34"><span class="wordmark">La pasión<span class="brand-caption">{{ $isCustomer?'Customer account':'Point of sale' }}</span></span></a>
<div class="nav-section-label">{{ $isCustomer?'Account':'Workspace' }}</div>
<nav class="desktop-nav" aria-label="Main navigation">
@foreach($navigation as $item)
<a href="{{ $item['url'] }}" @class(['nav-link','is-active'=>$item['active']]) @if($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']"/><span>{{ $item['label'] }}</span>@if($item['active'])<span class="nav-active-dot"></span>@endif</a>
@endforeach
</nav>
<div class="sidebar-bottom"><div class="sidebar-account"><x-avatar class="avatar-dark" :src="!$isCustomer && $staff?->avatar ? Storage::disk('public')->url($staff->avatar) : null" :name="$isCustomer ? ($member?->customer?->full_name ?? 'Customer') : ($staff?->full_name ?? 'Staff')"/><div><strong>{{ $isCustomer?($member?->customer?->full_name??'Customer'):($staff?->full_name??'Staff') }}</strong><small>{{ $isCustomer?'Customer account':'Staff account' }}</small></div><form method="post" action="{{ $isCustomer?route('customer.logout'):route('logout') }}">@csrf<button class="icon-button logout-button" aria-label="{{ $isCustomer?'Customer log out':'Log out' }}" title="Log out"><x-icon name="logout"/></button></form></div></div>
</aside>
<div class="app-shell">
<header class="topbar"><div class="breadcrumb"><span>Point of sale</span><x-icon name="chevron" :size="12"/><strong>{{ $isCustomer?'Customer space':'Workspace' }}</strong></div><a class="mobile-brand" href="{{ $isCustomer?route('customer.shop'):route('sales.create') }}">La pasión<span>.</span></a><div class="topbar-end"><time datetime="{{ now()->toDateString() }}">{{ now()->format('l, d F Y') }}</time><x-avatar class="avatar-small" :src="!$isCustomer && $staff?->avatar ? Storage::disk('public')->url($staff->avatar) : null" :name="$isCustomer ? ($member?->customer?->full_name ?? 'Customer') : ($staff?->full_name ?? 'Staff')"/><form class="mobile-logout" method="post" action="{{ $isCustomer?route('customer.logout'):route('logout') }}">@csrf<button class="icon-button" aria-label="{{ $isCustomer?'Customer log out':'Log out' }}"><x-icon name="logout"/></button></form></div></header>
<main id="main" class="main-content">
<div class="page-heading"><div><h1>{{ $title ?? 'La pasión' }}</h1>@hasSection('subtitle')<p>@yield('subtitle')</p>@endif</div><div class="page-heading-actions">@yield('actions')</div></div>
@else
<main id="main" class="auth-main">
<section class="auth-visual">
<a href="/" class="auth-brand"><img src="/brand/specaura.svg" alt="" width="50" height="32"><span>La pasión</span></a>
<div class="auth-visual-body">
<div class="logo-stage" data-glass-surface>
<img class="logo-static" src="/brand/specaura.svg" alt="Specaura logo" width="400" height="235">
<div id="liquid-logo" class="liquid-logo" aria-hidden="true" data-image="/brand/specaura-metal-mask.svg"></div>
<button type="button" id="logo-motion" class="logo-motion" aria-label="Pause logo animation" hidden><span aria-hidden="true">II</span></button>
</div>

</div>

</section>
<section class="auth-form-area">
<div class="auth-topline"><a class="auth-mobile-brand" href="/"><img src="/brand/specaura.svg" alt="" width="36" height="24"><span>La pasión</span></a><a class="auth-access-switch" href="{{ $isCustomer?route('login'):route('customer.login') }}">{{ $isCustomer?'Staff login':'Customer login' }}</a></div>
<div class="auth-form-container">

@endif
@if(session('status'))<div class="notice notice-success" role="status"><x-icon name="check"/><span>{{ session('status') }}</span>@if(session('receipt_id'))<a href="{{ route('receipts.show',session('receipt_id')) }}">View receipt</a>@endif</div>@endif
@if($errors->any())<div class="notice notice-error" role="alert"><x-icon name="alert"/><div><strong>Check the following details</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>@endif
@yield('content')
@if($authPage)</div></section></main>
@else
</main></div>
<nav data-glass-surface class="mobile-nav" aria-label="Mobile navigation">@foreach($navigation as $item)<a href="{{ $item['url'] }}" @class(['is-active'=>$item['active']]) @if($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']"/><span>{{ match($item['label']) { 'Customer orders','My orders'=>'Orders', 'Sales counter'=>'Counter', 'Sales history'=>'Sales', 'My purchases'=>'Purchases', 'My account'=>'Account', default=>$item['label'] } }}</span></a>@endforeach</nav>
@endif
<dialog id="confirmation" class="confirm-dialog" aria-labelledby="confirmation-title" aria-describedby="confirmation-message"><form method="dialog"><div class="confirm-symbol"><x-icon name="alert" :size="24"/></div><h2 id="confirmation-title">Confirm this action</h2><p id="confirmation-message"></p><div class="button-row"><button value="cancel" class="button secondary">Cancel</button><button value="confirm" class="button danger-solid" id="confirm-action">Confirm</button></div></form></dialog>
<script src="/pos.js?v=5" defer></script>
@vite('resources/js/app.js')
@stack('scripts')
</body></html>
