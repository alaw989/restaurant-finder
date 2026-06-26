<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <nav class="border-b border-border bg-card/80 backdrop-blur-sm sticky top-0 z-50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex h-16 items-center justify-between">
                    <Link href="/" class="flex items-center gap-2 text-xl font-bold tracking-tight text-foreground">
                        <span class="text-2xl">🍽️</span>
                        iPop360
                    </Link>

                    <div class="flex items-center gap-4">
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/favorites"
                            class="text-sm text-muted-foreground hover:text-primary transition-colors"
                        >
                            Favorites
                        </Link>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/dashboard"
                            class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Dashboard
                        </Link>
                        <Link
                            v-else
                            href="/login"
                            class="text-sm text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Login
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <main class="flex-1">
            <slot />
        </main>

        <footer class="border-t border-border bg-muted/40 py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                    <div class="text-center sm:text-left">
                        <h3 class="text-lg font-semibold text-foreground">iPop360</h3>
                        <p class="text-sm text-muted-foreground">Discover great restaurants near you.</p>
                    </div>
                    <nav class="flex flex-wrap items-center justify-center gap-4 text-sm sm:justify-end">
                        <Link href="/" class="text-muted-foreground hover:text-foreground transition-colors">
                            Home
                        </Link>
                        <Link href="/restaurants" class="text-muted-foreground hover:text-foreground transition-colors">
                            Browse
                        </Link>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/favorites"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Favorites
                        </Link>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/logout"
                            method="post"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                            as="button"
                        >
                            Logout
                        </Link>
                        <Link
                            v-else
                            href="/login"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Login
                        </Link>
                    </nav>
                </div>
                <div class="mt-6 text-center text-xs text-muted-foreground">
                    <p>&copy; {{ new Date().getFullYear() }} iPop360. All rights reserved.</p>
                </div>
            </div>
        </footer>

    </div>
</template>
