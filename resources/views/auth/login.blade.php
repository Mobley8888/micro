<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion | MICRO</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[#f5f7f8] text-[#172221] antialiased">
    <main class="flex min-h-screen items-center justify-center px-5 py-12">
        <section class="w-full max-w-md rounded-3xl border border-[#dce5e2] bg-white p-8 shadow-xl shadow-[#173b36]/5 sm:p-10">
            <div class="mb-8 text-center">
                <span class="brand-mark mx-auto">M</span>
                <p class="mt-4 text-sm font-bold tracking-[0.24em] text-[#173b36]">MICRO</p>
                <h1 class="mt-2 text-2xl font-bold text-[#172221]">Gestion commerciale simplifiée</h1>
                <p class="mt-2 text-sm text-[#647370]">Connectez-vous à votre espace opérateur.</p>
            </div>
            <form method="post" action="{{ route('login.store') }}" class="space-y-5">
                @csrf
                <label class="block text-sm font-semibold" for="email">Email
                    <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required autofocus class="search-input mt-2 w-full" aria-describedby="email-error">
                    @error('email')<span id="email-error" class="mt-1 block text-sm font-medium text-red-700">{{ $message }}</span>@enderror
                </label>
                <label class="block text-sm font-semibold" for="password">Mot de passe
                    <input id="password" name="password" type="password" autocomplete="current-password" required class="search-input mt-2 w-full" aria-describedby="password-error">
                    @error('password')<span id="password-error" class="mt-1 block text-sm font-medium text-red-700">{{ $message }}</span>@enderror
                </label>
                <button type="submit" class="button-primary w-full justify-center">Se connecter</button>
            </form>
        </section>
    </main>
</body>
</html>
