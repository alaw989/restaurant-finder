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

    </div>
</template>
