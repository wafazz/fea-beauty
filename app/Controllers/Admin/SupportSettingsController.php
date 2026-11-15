<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Request;
use App\Services\SupportTaxonomy;

/** Support categories and labels (admins). */
final class SupportSettingsController extends Controller
{
    private const PAGE = '/admin/support-settings';

    public function index(Request $request): void
    {
        $this->view('admin/support-settings', [
            'title' => 'Support categories and labels',
            'categories' => SupportTaxonomy::categories(),
            'labels' => SupportTaxonomy::labels(),
        ], 'portal');
    }

    public function saveCategory(Request $request, ?string $id = null): void
    {
        $error = SupportTaxonomy::saveCategory($id === null ? null : (int) $id, $request->str('name'), $request->str('position') ?: '0', $request->str('active') === '1');
        if ($error !== null) {
            $this->backWithErrors(self::PAGE, ['category' => $error], $request->body);
        }
        $this->success(self::PAGE, 'Category saved.');
    }

    public function saveLabel(Request $request, ?string $id = null): void
    {
        $error = SupportTaxonomy::saveLabel($id === null ? null : (int) $id, $request->str('name'), $request->str('colour') ?: '#64748b', $request->str('active') === '1');
        if ($error !== null) {
            $this->backWithErrors(self::PAGE, ['label' => $error], $request->body);
        }
        $this->success(self::PAGE, 'Label saved.');
    }
}
