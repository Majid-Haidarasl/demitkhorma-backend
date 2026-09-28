<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $base = rtrim((string) config('services.frontend.url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $xml = Cache::remember('sitemap.xml.v1.'.md5($base), 3600, function () use ($base) {
            $parts = [];
            $parts[] = '<?xml version="1.0" encoding="UTF-8"?>';
            $parts[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            $static = [
                ['loc' => $base.'/', 'changefreq' => 'daily', 'priority' => '1.0'],
                ['loc' => $base.'/shop', 'changefreq' => 'daily', 'priority' => '0.9'],
                ['loc' => $base.'/about', 'changefreq' => 'monthly', 'priority' => '0.6'],
                ['loc' => $base.'/faq', 'changefreq' => 'monthly', 'priority' => '0.5'],
                ['loc' => $base.'/buying-guide', 'changefreq' => 'monthly', 'priority' => '0.5'],
                ['loc' => $base.'/contact', 'changefreq' => 'monthly', 'priority' => '0.5'],
                ['loc' => $base.'/terms', 'changefreq' => 'yearly', 'priority' => '0.3'],
                ['loc' => $base.'/privacy', 'changefreq' => 'yearly', 'priority' => '0.3'],
            ];

            foreach ($static as $url) {
                $parts[] = $this->urlNode($url);
            }

            Category::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->select(['slug', 'updated_at'])
                ->cursor()
                ->each(function (Category $category) use (&$parts, $base) {
                    $parts[] = $this->urlNode([
                        'loc' => $base.'/category/'.$category->slug,
                        'lastmod' => optional($category->updated_at)->toAtomString(),
                        'changefreq' => 'weekly',
                        'priority' => '0.7',
                    ]);
                });

            Product::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->select(['slug', 'updated_at'])
                ->cursor()
                ->each(function (Product $product) use (&$parts, $base) {
                    $parts[] = $this->urlNode([
                        'loc' => $base.'/product/'.$product->slug,
                        'lastmod' => optional($product->updated_at)->toAtomString(),
                        'changefreq' => 'weekly',
                        'priority' => '0.8',
                    ]);
                });

            $parts[] = '</urlset>';

            return implode("\n", $parts);
        });

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function urlNode(array $url): string
    {
        $xml = "  <url>\n";
        $xml .= '    <loc>'.e($url['loc'])."</loc>\n";
        if (! empty($url['lastmod'])) {
            $xml .= '    <lastmod>'.e($url['lastmod'])."</lastmod>\n";
        }
        if (! empty($url['changefreq'])) {
            $xml .= '    <changefreq>'.e($url['changefreq'])."</changefreq>\n";
        }
        if (! empty($url['priority'])) {
            $xml .= '    <priority>'.e($url['priority'])."</priority>\n";
        }
        $xml .= '  </url>';

        return $xml;
    }
}
