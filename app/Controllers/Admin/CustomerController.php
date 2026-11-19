<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\CustomerSegmentService as S;

/** Customers and segments (module customer_segments). Admin only; routes guarded by the module. */
final class CustomerController extends Controller
{
    public function index(Request $request): void
    {
        $segments = S::all();
        $selected = $request->str('segment') !== '' ? S::find((int) $request->str('segment')) : null;
        $this->view('admin/customers', [
            'title' => 'Customers',
            'segments' => $segments,
            'selected' => $selected,
            // Up to 200 customers: the selected segment's members, or every customer.
            'members' => S::members($selected['rules'] ?? [], 200),
            'tags' => fn(int $id): array => S::tags($id),
        ], 'portal');
    }

    public function storeSegment(Request $request): void
    {
        $this->saveSegment(null, $request);
    }

    public function updateSegment(Request $request, string $id): void
    {
        S::find((int) $id) ?? Response::abort(404);
        $this->saveSegment((int) $id, $request);
    }

    public function deleteSegment(Request $request, string $id): void
    {
        S::delete((int) $id, (int) Auth::user()['id']);
        $this->success('/admin/customers', 'Segment deleted.');
    }

    public function exportSegment(Request $request, string $id): void
    {
        $csv = S::exportCsv((int) $id, (int) Auth::user()['id']) ?? Response::abort(404);
        header('Cache-Control: no-store');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="segment-' . (int) $id . '.csv"');
        echo $csv;
        exit;
    }

    public function saveTags(Request $request, string $id): void
    {
        $errors = S::setTags((int) $id, $request->str('tags'), (int) Auth::user()['id']);
        $back = '/admin/customers' . ($request->str('segment') !== '' ? '?segment=' . (int) $request->str('segment') : '');
        if ($errors !== []) {
            $this->backWithErrors($back, $errors);
        }
        $this->success($back, 'Tags saved.');
    }

    private function saveSegment(?int $id, Request $request): void
    {
        $in = [
            'min_orders' => trim($request->str('min_orders')),
            'min_spent' => trim($request->str('min_spent')),
            'within_days' => trim($request->str('within_days')),
            'inactive_days' => trim($request->str('inactive_days')),
            'tag' => trim($request->str('tag')),
        ];
        $errors = S::save($id, $request->str('name'), $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/customers', $errors, $in + ['name' => $request->str('name')]);
        }
        $this->success('/admin/customers', $id === null ? 'Segment saved.' : 'Segment updated.');
    }
}
