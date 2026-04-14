@php
    $statusCode = (int) ($statusCode ?? 500);
    $heading = $heading ?? __('Something went wrong');
    $message = $message ?? __('We could not complete your request right now.');
    $label = $label ?? __('Unexpected Error');
    $primaryHref = auth()->check() ? route('dashboard') : route('login');
    $primaryLabel = auth()->check() ? __('Go to Dashboard') : __('Go to Login');
    $pageTitle = $pageTitle ?? "taskLyst · {$statusCode}";
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head', ['title' => $pageTitle])
    </head>
    <body class="login-page-shell">
        <main class="mx-auto flex min-h-screen w-full max-w-7xl items-start justify-center px-4 py-8 sm:px-6 lg:px-8">
            <section class="hero-brand-gradient-shell login-hero-panel w-full max-w-5xl">
                <div class="hero-brand-gradient-glass" aria-hidden="true"></div>
                <div class="relative z-10 mx-auto flex w-full max-w-3xl flex-col items-start gap-6 text-left">
                    <x-app-logo
                        href="{{ route('login') }}"
                        logoSize="size-16"
                        iconSize="size-16"
                        class="login-brand"
                    />

                    <div class="space-y-2">
                        <div class="flex items-center gap-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                                {{ $label }}
                            </p>
                            <span class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground" aria-hidden="true">·</span>
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                                {{ __('Error :code', ['code' => $statusCode]) }}
                            </p>
                        </div>
                        <h1 class="text-5xl font-semibold tracking-tight text-foreground sm:text-6xl">
                            {{ $heading }}
                        </h1>
                        <p class="max-w-2xl text-sm leading-6 text-muted-foreground sm:text-base">
                            {{ $message }}
                        </p>
                    </div>

                    <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-center">
                        <a href="{{ $primaryHref }}" class="login-google-cta sm:w-auto sm:min-w-44">
                            <span>{{ $primaryLabel }}</span>
                        </a>

                        <button type="button" class="error-back-button sm:w-auto sm:min-w-36" onclick="window.history.back()">
                            {{ __('Go Back') }}
                        </button>
                    </div>
                </div>
            </section>
        </main>
    </body>
</html>
