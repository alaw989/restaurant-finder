<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';

export interface SeoData {
    title: string;
    description: string;
    canonical: string;
    ogTitle: string;
    ogDescription: string;
    ogType: string;
    ogUrl: string;
    ogSiteName: string;
    ogImage: string;
    ogImageAlt: string;
    twitterCard: string;
    twitterTitle: string;
    twitterDescription: string;
    twitterImage: string;
    noindex?: boolean;
}

const props = defineProps<{
    seoData: ComputedRef<SeoData> | SeoData;
}>();

// Unwrap ComputedRef if needed
const data = computed(() => {
    return typeof props.seoData === 'function' || 'value' in props.seoData
        ? (props.seoData as ComputedRef<SeoData>).value
        : props.seoData;
});
</script>

<template>
    <Head>
        <title>{{ data.title }}</title>
        <meta v-if="data.noindex" name="robots" content="noindex, nofollow" />
        <meta name="description" :content="data.description" />
        <link rel="canonical" :href="data.canonical" />
        <meta property="og:title" :content="data.ogTitle" />
        <meta property="og:description" :content="data.ogDescription" />
        <meta property="og:type" :content="data.ogType" />
        <meta property="og:url" :content="data.ogUrl" />
        <meta property="og:site_name" :content="data.ogSiteName" />
        <meta property="og:image" :content="data.ogImage" />
        <meta property="og:image:alt" :content="data.ogImageAlt" />
        <meta name="twitter:card" :content="data.twitterCard" />
        <meta name="twitter:title" :content="data.twitterTitle" />
        <meta name="twitter:description" :content="data.twitterDescription" />
        <meta name="twitter:image" :content="data.twitterImage" />
    </Head>
</template>
