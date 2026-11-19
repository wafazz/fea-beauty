<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\WarehouseService;

/** Warehouses and stock per warehouse (module multi_warehouse). Admin only; routes guarded by the module. */
final class WarehouseController extends Controller
{
    public function index(Request $request): void
    {
        $warehouses = WarehouseService::all();
        $held = [];
        foreach (\App\Core\Database::query(
            'SELECT s.product_id, s.variant_id, s.warehouse_id, s.qty FROM warehouse_stock s JOIN warehouses w ON w.id = s.warehouse_id WHERE w.is_default = 0'
        )->fetchAll() as $r) {
            $held[(int) $r['product_id'] . ':' . (int) $r['variant_id']][(int) $r['warehouse_id']] = (int) $r['qty'];
        }
        // One row per product, or per variation, with its total and its count in each warehouse.
        $items = \App\Core\Database::query(
            "SELECT p.id AS product_id, 0 AS variant_id, p.name, p.sku, p.stock AS total, NULL AS label
               FROM products p WHERE p.deleted_at IS NULL AND p.status = 'active'
                AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.deleted_at IS NULL)
             UNION ALL
             SELECT p.id, v.id, p.name, v.sku, v.stock, NULL AS label
               FROM product_variants v JOIN products p ON p.id = v.product_id
              WHERE v.deleted_at IS NULL AND p.deleted_at IS NULL AND p.status = 'active'
             ORDER BY name, variant_id LIMIT 200"
        )->fetchAll();
        // Variation labels such as "Red / M", built the same way as VariantService::label, in one query.
        $labels = [];
        foreach (\App\Core\Database::query(
            'SELECT vov.variant_id, ov.value FROM variant_option_values vov
               JOIN product_option_values ov ON ov.id = vov.option_value_id
               JOIN product_options o ON o.id = ov.option_id
              ORDER BY o.position, o.id'
        )->fetchAll() as $r) {
            $labels[(int) $r['variant_id']][] = (string) $r['value'];
        }
        foreach ($items as &$it) {
            $vid = (int) $it['variant_id'];
            $it['label'] = $vid > 0 ? implode(' / ', $labels[$vid] ?? []) : null;
        }
        unset($it);
        $rows = [];
        foreach ($items as $it) {
            $key = (int) $it['product_id'] . ':' . (int) $it['variant_id'];
            $total = (int) $it['total'];
            $explicit = $held[$key] ?? [];
            $counts = [];
            foreach ($warehouses as $w) {
                $counts[(int) $w['id']] = (int) $w['is_default'] === 1 ? max(0, $total - array_sum($explicit)) : (int) ($explicit[(int) $w['id']] ?? 0);
            }
            $rows[] = ['product_id' => (int) $it['product_id'], 'variant_id' => (int) $it['variant_id'], 'name' => (string) $it['name'], 'sku' => (string) ($it['sku'] ?? ''), 'label' => (string) ($it['label'] ?? ''), 'total' => $total, 'counts' => $counts];
        }
        $this->view('admin/warehouses', [
            'title' => 'Warehouses',
            'warehouses' => $warehouses,
            'rows' => $rows,
            'states' => config('states'),
            'scripts' => ['warehouses.js'],
            'vendor' => ['select2'],
        ], 'portal');
    }

    public function store(Request $request): void
    {
        $in = $this->input($request);
        $errors = WarehouseService::save(null, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/warehouses', $errors, $in);
        }
        $this->success('/admin/warehouses', 'Warehouse added.');
    }

    public function update(Request $request, string $id): void
    {
        $in = $this->input($request);
        $errors = WarehouseService::save((int) $id, $in, (int) Auth::user()['id']);
        if ($errors !== []) {
            $this->backWithErrors('/admin/warehouses', $errors, $in);
        }
        $this->success('/admin/warehouses', 'Warehouse saved.');
    }

    /** Moves stock between two warehouses. The item is "product:variant" (variant 0 when there are no variations). */
    public function transfer(Request $request): void
    {
        [$pid, $vid] = array_map('intval', array_pad(explode(':', $request->str('item')), 2, '0'));
        $error = WarehouseService::transfer((int) $request->str('from'), (int) $request->str('to'), $pid, $vid, (int) $request->str('qty'), (int) Auth::user()['id']);
        if ($error !== null) {
            $this->backWithErrors('/admin/warehouses', ['form' => $error]);
        }
        $this->success('/admin/warehouses', 'Stock moved.');
    }

    private function input(Request $request): array
    {
        return [
            'name' => trim($request->str('name')),
            'state' => trim($request->str('state')),
            'postcode' => trim($request->str('postcode')),
            'active' => $request->str('active') === '1' ? '1' : '0',
            'sort_order' => trim($request->str('sort_order', '0')),
        ];
    }
}
