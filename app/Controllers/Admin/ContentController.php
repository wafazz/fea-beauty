<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CmsService;
use App\Services\UploadService;

/** Storefront content (module cms_pages): content pages and homepage banners. Admin only; routes guarded by the module. */
final class ContentController extends Controller
{
    public function index(Request $request): void
    {
        $this->view('admin/content', [
            'title' => 'Storefront content',
            'pages' => CmsService::pages(),
            'banners' => CmsService::banners(),
        ], 'portal');
    }

    public function storePage(Request $request): void
    {
        $this->savePage(null, $request);
    }

    public function updatePage(Request $request, string $id): void
    {
        CmsService::page((int) $id) ?? Response::abort(404);
        $this->savePage((int) $id, $request);
    }

    public function deletePage(Request $request, string $id): void
    {
        CmsService::deletePage((int) $id, (int) Auth::user()['id']);
        $this->success('/admin/content', 'Page deleted.');
    }

    public function storeBanner(Request $request): void
    {
        $this->saveBanner(null, $request);
    }

    public function updateBanner(Request $request, string $id): void
    {
        CmsService::banner((int) $id) ?? Response::abort(404);
        $this->saveBanner((int) $id, $request);
    }

    public function deleteBanner(Request $request, string $id): void
    {
        CmsService::deleteBanner((int) $id, (int) Auth::user()['id']);
        $this->success('/admin/content', 'Banner deleted.');
    }

    private function savePage(?int $id, Request $request): void
    {
        $in = [
            'slug' => strtolower(trim($request->str('slug'))),
            'title' => trim($request->str('title')),
            'body' => $request->str('body'),
            'status' => $request->str('status'),
            'seo_title' => trim($request->str('seo_title')),
            'seo_description' => trim($request->str('seo_description')),
        ];
        $errors = CmsService::savePage($id, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/content', $errors, $in);
        }
        $this->success('/admin/content', $id === null ? 'Page added.' : 'Page saved.');
    }

    private function saveBanner(?int $id, Request $request): void
    {
        $in = [
            'title' => trim($request->str('title')),
            'link' => trim($request->str('link')),
            'starts_at' => trim($request->str('starts_at')),
            'ends_at' => trim($request->str('ends_at')),
            'sort_order' => trim($request->str('sort_order', '0')),
            'active' => $request->str('active') === '1' ? '1' : '0',
        ];
        $files = UploadService::normalise($_FILES['image'] ?? null);
        $errors = CmsService::saveBanner($id, $in, $files[0] ?? null, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/content', $errors, $in);
        }
        $this->success('/admin/content', $id === null ? 'Banner added.' : 'Banner saved.');
    }
}
