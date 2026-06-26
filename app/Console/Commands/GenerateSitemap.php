<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Cuisine;
use App\Models\Restaurant;
use Illuminate\Support\Facades\DB;

class GenerateSitemap extends Command
{
    protected $signature = 'seo:sitemap';
    protected $description = 'Generate sitemap.xml for SEO';

    public function handle(): int
    {
        $sitemapPath = public_path('sitemap.xml');
        $baseUrl = config('app.url') ?? 'https://ipop360.vp-associates.com';

        $xml = $this->generateSitemapXml($baseUrl);

        file_put_contents($sitemapPath, $xml);

        $this->info("Sitemap generated at: {$sitemapPath}");

        return Command::SUCCESS;
    }

    protected function generateSitemapXml(string $baseUrl): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Static pages
        $staticPages = [
            ['url' => '', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['url' => '/restaurants', 'changefreq' => 'daily', 'priority' => '0.9'],
            ['url' => '/login', 'changefreq' => 'monthly', 'priority' => '0.3'],
            ['url' => '/register', 'changefreq' => 'monthly', 'priority' => '0.3'],
            ['url' => '/favorites', 'changefreq' => 'weekly', 'priority' => '0.6'],
        ];

        foreach ($staticPages as $page) {
            $xml .= $this->urlNode($baseUrl . $page['url'], $page['changefreq'], $page['priority']);
        }

        // Cuisine category pages
        $cuisines = DB::table('cuisines')->select('slug')->get();
        foreach ($cuisines as $cuisine) {
            $url = $baseUrl . '/cuisine/' . $cuisine->slug;
            $xml .= $this->urlNode($url, 'weekly', '0.8');
        }

        // Persisted restaurant pages (limited to avoid huge sitemap)
        $restaurants = Restaurant::select('slug', 'updated_at')
            ->where('is_active', true)
            ->orderBy('popularity_score', 'desc')
            ->limit(5000)
            ->get();

        foreach ($restaurants as $restaurant) {
            $url = $baseUrl . '/restaurants/' . $restaurant->slug;
            $changefreq = 'weekly';
            $priority = '0.7';
            $xml .= $this->urlNode($url, $changefreq, $priority, $restaurant->updated_at);
        }

        $xml .= '</urlset>';

        return $xml;
    }

    protected function urlNode(
        string $url,
        string $changefreq,
        string $priority,
        ?string $lastmod = null
    ): string {
        $node = '  <url>' . "\n";
        $node .= '    <loc>' . htmlspecialchars($url) . '</loc>' . "\n";
        $node .= '    <changefreq>' . $changefreq . '</changefreq>' . "\n";
        $node .= '    <priority>' . $priority . '</priority>' . "\n";

        if ($lastmod) {
            $node .= '    <lastmod>' . $lastmod->toAtomString() . '</lastmod>' . "\n";
        }

        $node .= '  </url>' . "\n";

        return $node;
    }
}
