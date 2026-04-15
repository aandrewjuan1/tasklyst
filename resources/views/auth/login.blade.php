<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        @include('partials.head', ['title' => 'Sign in · taskLyst'])
    </head>
    <body class="login-page-shell">
        <main
            class="mx-auto grid min-h-screen w-full max-w-6xl content-center gap-8 px-4 py-10 sm:px-6 lg:grid-cols-2 lg:gap-12 lg:px-8 lg:py-14"
        >
            <section
                class="hero-brand-gradient-shell login-hero-panel order-2 min-h-0 lg:order-1"
                aria-labelledby="login-marketing-heading"
            >
                <div class="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl" aria-hidden="true">
                    <div
                        class="absolute inset-0 bg-linear-to-r from-brand-blue/15 via-brand-purple/10 to-brand-green/15"
                    ></div>
                    <div
                        class="absolute -right-4 -top-4 flex size-48 items-center justify-center rounded-full bg-brand-blue/15 blur-2xl"
                    ></div>
                </div>
                <div class="hero-brand-gradient-glass" aria-hidden="true"></div>
                <div class="relative z-10 flex flex-col gap-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                        Built for students
                    </p>
                    <h1
                        id="login-marketing-heading"
                        class="text-balance text-3xl font-semibold tracking-tight text-foreground sm:text-4xl"
                    >
                        Your week, organized around what matters next.
                    </h1>
                    <p class="max-w-xl text-pretty text-sm leading-relaxed text-muted-foreground sm:text-base">
                        Keep classes, assignments, and deadlines in one workspace—so you spend less time juggling tabs and
                        more time studying.
                    </p>
                    <ul class="grid gap-2.5 text-sm text-foreground/90">
                        <li class="login-feature-pill">See what to tackle first by urgency and workload.</li>
                        <li class="login-feature-pill">Shape study time around your classes and calendar.</li>
                        <li class="login-feature-pill">Stay on track with a calmer daily flow.</li>
                    </ul>
                </div>
            </section>

            <section class="login-card-shell order-1 lg:order-2">
                <div class="login-card">
                    <div class="mx-auto">
                        <x-app-logo
                            href="{{ route('login') }}"
                            logoSize="size-16"
                            iconSize="size-16"
                            class="login-brand"
                        />
                    </div>

                    <div class="w-full space-y-1.5">
                        <h2 class="text-2xl font-semibold tracking-tight text-foreground">Sign in</h2>
                        <p class="text-sm leading-relaxed text-muted-foreground">
                            Use your Google account to open your dashboard and workspace.
                        </p>
                    </div>

                    <div class="w-full space-y-3">
                        <a
                            href="{{ route('login', ['redirect' => 1]) }}"
                            class="login-google-cta"
                            aria-label="Sign in with Google — continue to your Tasklyst account"
                        >
                            <svg class="size-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
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
                        <p class="text-center text-xs leading-snug text-muted-foreground sm:text-left">
                            You will leave this page briefly to sign in with Google.
                        </p>
                    </div>
                </div>
            </section>
        </main>
    </body>
</html>
