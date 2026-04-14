<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head', ['title' => 'taskLyst'])
    </head>
    <body class="login-page-shell">
        <main class="mx-auto grid min-h-screen w-full max-w-6xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-2 lg:gap-8 lg:px-8">
            <section class="hero-brand-gradient-shell login-hero-panel">
                <div class="hero-brand-gradient-glass" aria-hidden="true"></div>
                <div class="relative z-10 space-y-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                        Student-first task management
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
                        Study smarter with AI-prioritized planning.
                    </h1>
                    <p class="max-w-xl text-sm leading-6 text-muted-foreground sm:text-base">
                        Tasklyst helps students organize classes, assignments, and deadlines in one workspace. Get LLM-powered
                        prioritization, practical scheduling suggestions, and focus-friendly task flow for busy school weeks.
                    </p>
                    <ul class="grid gap-2.5 text-sm text-foreground/90">
                        <li class="login-feature-pill">Prioritize deadlines based on urgency and workload.</li>
                        <li class="login-feature-pill">Build balanced study schedules around classes and events.</li>
                        <li class="login-feature-pill">Stay consistent with focused daily execution in your workspace.</li>
                    </ul>
                </div>
            </section>

            <section class="login-card-shell">
                <div class="login-card">
                    <x-app-logo
                        href="{{ route('login') }}"
                        logoSize="size-16"
                        iconSize="size-16"
                        class="login-brand"
                    />

                    <div class="space-y-2 text-center">
                        <h2 class="text-2xl font-semibold tracking-tight text-foreground">Welcome to Tasklyst</h2>
                        <p class="text-sm text-muted-foreground">
                            Sign in with Google to continue to your dashboard and workspace.
                        </p>
                    </div>

                    <a href="{{ route('login', ['redirect' => 1]) }}" class="login-google-cta">
                        <svg class="size-4" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                            <path
                                fill="#EA4335"
                                d="M12 10.2v3.9h5.5c-.2 1.2-.9 2.2-1.9 2.9l3.1 2.4c1.8-1.7 2.9-4.1 2.9-7 0-.7-.1-1.4-.2-2.1H12z"
                            />
                            <path
                                fill="#34A853"
                                d="M12 22c2.6 0 4.8-.9 6.4-2.5l-3.1-2.4c-.9.6-2 .9-3.3.9-2.5 0-4.6-1.7-5.4-4H3.4v2.5C5 19.8 8.2 22 12 22z"
                            />
                            <path
                                fill="#4A90E2"
                                d="M6.6 14c-.2-.6-.3-1.3-.3-2s.1-1.4.3-2V7.5H3.4A10 10 0 0 0 2 12c0 1.6.4 3.1 1.1 4.5L6.6 14z"
                            />
                            <path
                                fill="#FBBC05"
                                d="M12 6.1c1.4 0 2.7.5 3.7 1.4l2.8-2.8C16.8 3.1 14.6 2 12 2 8.2 2 5 4.2 3.4 7.5L6.6 10c.8-2.3 2.9-3.9 5.4-3.9z"
                            />
                        </svg>
                        <span>Continue with Google</span>
                    </a>
                </div>
            </section>
        </main>
    </body>
</html>
