<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'MICRO' }} | MICRO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-[#f5f7f8] text-[#172221] antialiased">
    <div class="min-h-screen lg:flex">
        <aside class="sidebar-shell w-full shrink-0 border-b border-[#dce5e2] bg-[#173b36] text-white lg:min-h-screen lg:w-72 lg:border-r lg:border-b-0">
            <div class="flex items-center justify-between px-6 py-6 lg:block">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-3">
                    <span class="brand-mark">M</span>
                    <span><strong class="block text-lg tracking-[0.18em]">MICRO</strong><small class="text-[#b7d6c8]">gestion intégrée</small></span>
                </a>
                <button type="button" class="menu-button lg:hidden" aria-label="Ouvrir le menu">☰</button>
            </div>
            <nav class="hidden space-y-7 px-4 pb-8 lg:block">
                <div>
                    <p class="nav-label">Pilotage</p>
                    <a class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}" href="{{ route('dashboard') }}"><span>⌂</span> Tableau de bord</a>
                </div>
                <div>
                    <p class="nav-label">Relation client</p>
                    @if(auth()->user()->hasPermission('customer.view'))<a class="nav-link {{ request()->routeIs('customers.*') ? 'nav-link-active' : '' }}" href="{{ route('customers.index') }}"><span>◉</span> Clients</a>@endif
                    @if(auth()->user()->hasPermission('leads.manage'))<a class="nav-link {{ request()->routeIs('leads.*') ? 'nav-link-active' : '' }}" href="{{ route('leads.index') }}"><span>◇</span> Prospects</a>@endif
                    @if(auth()->user()->hasPermission('quotes.manage'))<a class="nav-link" href="{{ route('quotes.index') }}"><span>◇</span> Devis</a>@endif
                    @if(auth()->user()->hasPermission('payment.view'))<a class="nav-link" href="{{ route('payments.index') }}"><span>↗</span> Encaissements</a>@endif
                    @if (auth()->user()->hasPermission('cash.view'))
                        <a class="nav-link {{ request()->routeIs('cash-registers.*') ? 'nav-link-active' : '' }}" href="{{ route('cash-registers.index') }}"><span>▣</span> Caisses</a>
                    @endif
                    @if (auth()->user()->hasPermission('cash.view'))<a class="nav-link {{ request()->routeIs('cash.my-session') ? 'nav-link-active' : '' }}" href="{{ route('cash.my-session') }}"><span>▣</span> Ma caisse</a>@endif
                    @if(auth()->user()->hasPermission('invoice.view'))<a class="nav-link" href="{{ route('receivables.index') }}"><span>◷</span> Créances</a>@endif
                </div>
                @if(auth()->user()->hasPermission('expense.view') || auth()->user()->hasPermission('bank.view') || auth()->user()->hasPermission('financial.journal.view') || auth()->user()->hasPermission('financial.report.view'))
                <div>
                    <p class="nav-label">Finances</p>
                    @if(auth()->user()->hasPermission('expense.view'))<a class="nav-link {{ request()->routeIs('expenses.*') ? 'nav-link-active' : '' }}" href="{{ route('expenses.index') }}"><span>↘</span> Dépenses</a>@endif
                    @if(auth()->user()->hasPermission('bank.view'))<a class="nav-link {{ request()->routeIs('banks.*') ? 'nav-link-active' : '' }}" href="{{ route('banks.index') }}"><span>▤</span> Banques</a>@endif
                    @if(auth()->user()->hasPermission('financial.journal.view'))<a class="nav-link {{ request()->routeIs('financial.journal') ? 'nav-link-active' : '' }}" href="{{ route('financial.journal') }}"><span>≡</span> Journal financier</a>@endif
                    @if(auth()->user()->hasPermission('financial.report.view'))<a class="nav-link {{ request()->routeIs('financial.reports') ? 'nav-link-active' : '' }}" href="{{ route('financial.reports') }}"><span>⌁</span> Rapports financiers</a>@endif
                </div>
                @endif
                <div>
                    <p class="nav-label">Opérations</p>
                    @if(auth()->user()->hasPermission('invoice.view'))<a class="nav-link" href="{{ route('invoices.index') }}"><span>▤</span> Facturation</a>@endif
                    @if(auth()->user()->hasPermission('products.manage'))<a class="nav-link" href="{{ route('products.index') }}"><span>□</span> Produits & services</a>@endif
                    @if(auth()->user()->hasPermission('purchases.view'))<a class="nav-link {{ request()->routeIs('purchases.*') ? 'nav-link-active' : '' }}" href="{{ route('purchases.index') }}"><span>▤</span> Achats</a>@endif
                    @if(auth()->user()->hasPermission('suppliers.view'))<a class="nav-link {{ request()->routeIs('suppliers.*') ? 'nav-link-active' : '' }}" href="{{ route('suppliers.index') }}"><span>♧</span> Fournisseurs</a>@endif
                    @if(auth()->user()->hasPermission('stock.view'))<a class="nav-link {{ request()->routeIs('stock.*') ? 'nav-link-active' : '' }}" href="{{ route('stock.index') }}"><span>▣</span> Stock</a>@endif
                    @if(auth()->user()->hasPermission('reports.view'))<a class="nav-link" href="{{ route('page', 'reports') }}"><span>⌁</span> Rapports</a>@endif
                </div>
                @if (auth()->user()->isSuperAdmin() || auth()->user()->isCompanyAdmin())
                <div>
                    <p class="nav-label">Administration</p>
                    <a class="nav-link {{ request()->routeIs('admin.dashboard') ? 'nav-link-active' : '' }}" href="{{ route('admin.dashboard') }}"><span>⚙</span> Administration</a>
                    @if (auth()->user()->isSuperAdmin())
                        <a class="nav-link" href="{{ route('admin.companies.index') }}"><span>▦</span> Entreprises</a>
                        <a class="nav-link" href="{{ route('admin.users.index') }}"><span>◉</span> Utilisateurs</a>
                    @else
                        <a class="nav-link" href="{{ route('users.index') }}"><span>◉</span> Utilisateurs</a>
                    @endif
                </div>
                @endif
                <div>
                    <p class="nav-label">Préférences</p>
                    <a class="nav-link" href="{{ route('page', 'settings') }}"><span>⚙</span> Paramètres</a>
                </div>
            </nav>
        </aside>

        <main class="min-w-0 flex-1">
            <header class="topbar flex items-center justify-between gap-4 border-b border-[#dce5e2] bg-white px-5 py-4 lg:px-10">
                <div>
                    <p class="eyebrow">{{ $eyebrow ?? 'Hestam Éducation' }}</p>
                    <h1 class="page-title">{{ $heading ?? 'Tableau de bord' }}</h1>
                </div>
                <div class="flex items-center gap-3">
                    <button class="icon-button" title="Notifications" aria-label="Notifications">♧</button>
                    <div class="user-chip"><span class="avatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</span><span class="hidden text-sm font-semibold sm:block">{{ auth()->user()->name }}</span><form method="post" action="{{ route('logout') }}">@csrf<button class="button-secondary">Se déconnecter</button></form></div>
                </div>
            </header>
            <div class="mx-auto max-w-[1500px] px-5 py-7 lg:px-10 lg:py-10">
                @if (session('success'))<div class="flash-success">{{ session('success') }}</div>@endif
                @yield('content')
            </div>
        </main>
    </div>
</body>

</html>