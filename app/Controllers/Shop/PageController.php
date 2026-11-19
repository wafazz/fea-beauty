<?php
declare(strict_types=1);

namespace App\Controllers\Shop;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CmsService;
use App\Services\RichText;

/** A content page (FAQ, policy, ...). Only published pages whose publish time has passed; 404 otherwise. */
final class PageController extends Controller
{
    public function show(Request $request, string $slug): void
    {
        $page = CmsService::publishedPage($slug) ?? Response::abort(404);
        $body = RichText::clean((string) $page['body']);
        $this->view('shop/page', [
            'title' => (string) ($page['seo_title'] ?: $page['title']),
            'metaDescription' => (string) ($page['seo_description'] ?: mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? ''), 0, 155)),
            'page' => $page,
            'body' => $body,
        ]);
    }
}
