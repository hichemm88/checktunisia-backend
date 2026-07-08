<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Lecture publique du CMS : pages publiées, menus, médias. */
class PublicCmsController extends Controller
{
    public function page(string $slug): JsonResponse
    {
        $page = Page::where('slug', $slug)->where('status', 'published')->firstOrFail();

        return response()->json(['data' => [
            'slug'       => $page->slug,
            'content'    => $page->content,
            'meta'       => $page->meta,
            'updated_at' => $page->updated_at,
        ]])->header('Cache-Control', 'public, max-age=60');
    }

    public function menus(): JsonResponse
    {
        $items = MenuItem::with('page:id,slug,status')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            // Une entrée pointant vers une page dépubliée/supprimée ne doit pas sortir.
            ->filter(fn(MenuItem $i) => $i->external_url || ($i->page && $i->page->isPublished()))
            ->map(fn(MenuItem $i) => [
                'id'       => $i->id,
                'location' => $i->location,
                'label'    => $i->label,
                'slug'     => $i->page?->slug,
                'url'      => $i->external_url,
            ]);

        return response()->json(['data' => [
            'navbar' => $items->where('location', 'navbar')->values(),
            'footer' => $items->where('location', 'footer')->values(),
        ]])->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * Sitemap dynamique (qayed.tn/sitemap.xml via rewrite Vercel) :
     * routes publiques statiques + pages CMS publiées, avec alternates
     * hreflang FR/EN/AR.
     */
    public function sitemap(): Response
    {
        $base = 'https://qayed.tn';
        $langs = ['fr', 'en', 'ar'];

        $urls = [];
        $push = function (string $frPath, array $altPaths, string $lastmod = null) use (&$urls, $base, $langs) {
            $alternates = '';
            foreach ($langs as $l) {
                $alternates .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"{$l}\" href=\"{$base}{$altPaths[$l]}\"/>";
            }
            $alternates .= "\n    <xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"{$base}{$frPath}\"/>";
            $lastmodTag = $lastmod ? "\n    <lastmod>{$lastmod}</lastmod>" : '';
            $urls[] = "  <url>\n    <loc>{$base}{$frPath}</loc>{$lastmodTag}{$alternates}\n  </url>";
        };

        $push('/', ['fr' => '/', 'en' => '/en', 'ar' => '/ar']);
        foreach (Page::where('status', 'published')->where('slug', '!=', 'home')->get() as $page) {
            $push(
                "/fr/{$page->slug}",
                ['fr' => "/fr/{$page->slug}", 'en' => "/en/{$page->slug}", 'ar' => "/ar/{$page->slug}"],
                $page->updated_at->toAtomString(),
            );
        }
        // Routes applicatives publiques (hors CMS)
        foreach (['/register', '/login'] as $path) {
            $urls[] = "  <url>\n    <loc>{$base}{$path}</loc>\n  </url>";
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:xhtml=\"http://www.w3.org/1999/xhtml\">\n"
            . implode("\n", $urls)
            . "\n</urlset>\n";

        return response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function media(string $id): Response
    {
        $media = Media::findOrFail($id);

        // L'URL contient l'UUID (immuable) : cache long + immutable côté CDN/navigateur.
        return response(
            is_resource($media->data) ? stream_get_contents($media->data) : $media->data,
            200,
            [
                'Content-Type'  => $media->mime,
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }
}
