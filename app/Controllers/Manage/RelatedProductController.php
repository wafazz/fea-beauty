<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\CrossSellService;

/**
 * Pin related products to a product (module cross_sell). Mounted in /admin and /staff with the product routes.
 * Each change is one small form post (add, remove, move), so the page works without JavaScript.
 */
final class RelatedProductController extends ManageController
{
    public function show(Request $request, string $id): void
    {
        $product = $this->product($id);
        $q = trim($request->str('q'));
        $this->view('manage/products/related', [
            'title' => 'Related products',
            'base' => $this->base(),
            'product' => $product,
            'pinned' => CrossSellService::pinned((int) $product['id']),
            'q' => $q,
            'results' => CrossSellService::search((int) $product['id'], $q),
            'max' => CrossSellService::MAX_PINS,
        ], 'portal');
    }

    public function add(Request $request, string $id): void
    {
        $product = $this->product($id);
        $pid = (int) $product['id'];
        $rid = (int) $request->str('related_id');
        if ($rid < 1) {
            $this->backWithErrors($this->base() . '/products/' . $pid . '/related', ['form' => 'Choose a product to pin.']);
        }
        $ids = $this->pinnedIds($pid);
        $ids[] = $rid;
        $this->save($pid, $ids, 'added', $request);
    }

    public function remove(Request $request, string $id): void
    {
        $product = $this->product($id);
        $pid = (int) $product['id'];
        $rid = (int) $request->str('related_id');
        $ids = array_values(array_filter($this->pinnedIds($pid), fn(int $i) => $i !== $rid));
        $this->save($pid, $ids, 'removed', $request);
    }

    /** dir = -1 moves the product up one place, 1 moves it down. */
    public function move(Request $request, string $id): void
    {
        $product = $this->product($id);
        $pid = (int) $product['id'];
        $rid = (int) $request->str('related_id');
        $dir = $request->str('dir') === '-1' ? -1 : 1;
        $ids = $this->pinnedIds($pid);
        $at = array_search($rid, $ids, true);
        if ($at !== false && isset($ids[$at + $dir])) {
            [$ids[$at], $ids[$at + $dir]] = [$ids[$at + $dir], $ids[$at]];
        }
        $this->save($pid, $ids, 'reordered', $request);
    }

    /** The pinned product ids in their current order. */
    private function pinnedIds(int $pid): array
    {
        return array_map(fn(array $r) => (int) $r['id'], CrossSellService::pinned($pid));
    }

    private function save(int $pid, array $ids, string $what, Request $request): void
    {
        $back = $this->base() . '/products/' . $pid . '/related';
        $errors = CrossSellService::savePins($pid, $ids);
        if ($errors !== []) {
            $this->backWithErrors($back, ['form' => $errors[0]]);
        }
        AuditService::log((int) Auth::user()['id'], 'product.related', 'product ' . $pid . ' related list ' . $what);
        $this->success($back, match ($what) {
            'added' => 'Product pinned. It now shows on this product page and in the cart.',
            'removed' => 'Product removed from the pinned list.',
            default => 'Order saved.',
        });
    }
}
