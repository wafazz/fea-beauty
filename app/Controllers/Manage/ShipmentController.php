<?php
declare(strict_types=1);

namespace App\Controllers\Manage;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\ManualShipmentService;
use App\Services\NinjaVanSettings;
use App\Services\OrderService;
use App\Services\Shipping\JntExpressGateway;
use App\Services\Shipping\NinjaVanGateway;
use App\Services\Shipping\SpxExpressGateway;
use App\Services\ShipmentService;

/** NinjaVan shipments from the order page. create/cancel need orders.update, the waybill needs orders.view. */
final class ShipmentController extends ManageController
{
    public function create(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $in = $request->body;
        $r = NinjaVanGateway::create((int) $order['id'], $in, (int) Auth::user()['id']);
        if (!$r['ok']) {
            $this->backWithErrors($back, ($r['fields'] ?? []) + ['form' => $r['error']], array_intersect_key($in, array_flip(['weight', 'service_level', 'delivery_date', 'delivery_slot', 'pickup', 'pickup_date', 'pickup_slot', 'volume'])));
        }
        // Back to the Processing list: the parcel is booked, the order waits there until the AWB is printed.
        $this->success($this->base() . '/orders?status=processing', "Shipment created. Tracking number {$r['tracking']}.");
    }

    /** Push a Processing order to J&T Express (from the order list). Needs orders.update. */
    public function pushJnt(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . '/orders?status=processing';
        $r = JntExpressGateway::create((int) $order['id'], (int) Auth::user()['id']);
        $r['ok'] ? $this->success($back, "Pushed {$order['order_no']} to J&T Express. Tracking number {$r['tracking']}.") : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    /** Push a Processing order to SPX Express (from the order list). Needs orders.update. */
    public function pushSpx(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . '/orders?status=processing';
        $r = SpxExpressGateway::create((int) $order['id'], (int) Auth::user()['id']);
        $r['ok'] ? $this->success($back, "Pushed {$order['order_no']} to SPX Express. Tracking number {$r['tracking']}.") : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    /** Book a courier the seller arranged outside the system (tracking number, optional link and AWB). Needs orders.update. */
    public function manual(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $r = ManualShipmentService::create((int) $order['id'], $request->body, $_FILES['awb'] ?? null, (int) Auth::user()['id']);
        if (!$r['ok']) {
            $this->backWithErrors($back, ($r['fields'] ?? []) + ['form' => $r['error']], array_intersect_key($request->body, array_flip(['courier', 'tracking_number', 'tracking_url'])));
        }
        $this->success($this->base() . '/orders?status=processing', "Courier added. Tracking number {$r['tracking']}.");
    }

    /** Attach or replace the AWB of the active manual shipment. Needs orders.update. */
    public function uploadManualAwb(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $error = ManualShipmentService::attachAwb((int) $order['id'], $_FILES['awb'] ?? null, (int) Auth::user()['id']);
        $error === null ? $this->success($back, 'AWB uploaded.') : $this->backWithErrors($back, ['awb' => $error, 'form' => $error]);
    }

    /** The AWB file of the active manual shipment. Needs orders.view. */
    public function manualAwb(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $ship = ShipmentService::active((int) $order['id']) ?? Response::abort(404);
        if (($ship['source'] ?? 'api') !== 'manual' || empty($ship['awb_file'])) {
            Response::abort(404);
        }
        ManualShipmentService::sendAwb($ship);
    }

    public function cancel(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $back = $this->base() . "/orders/{$order['id']}#shipping";
        $active = ShipmentService::active((int) $order['id']);
        if (($active['source'] ?? 'api') === 'manual') {
            ManualShipmentService::cancel((int) $order['id'], (int) Auth::user()['id']) ? $this->success($back, 'Courier booking removed.') : $this->backWithErrors($back, ['form' => 'No active courier booking to cancel.']);
        }
        $carrier = $active['carrier'] ?? '';
        if ($carrier === 'jnt') {
            $this->backWithErrors($back, ['form' => 'J&T Express shipments are cancelled in the J&T portal for now.']);
        }
        if ($carrier === 'spx') {
            $r = SpxExpressGateway::cancel((int) $order['id'], (int) Auth::user()['id']);
            $r['ok'] ? $this->success($back, 'SPX Express shipment cancelled.') : $this->backWithErrors($back, ['form' => $r['error']]);
        }
        $r = NinjaVanGateway::cancel((int) $order['id'], (int) Auth::user()['id']);
        $r['ok'] ? $this->success($back, 'Shipment cancelled.') : $this->backWithErrors($back, ['form' => $r['error']]);
    }

    /** Our own printable AWB (layout of the courier label). Staff with orders.view may print it. */
    public function awb(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $ship = ShipmentService::active((int) $order['id']) ?? Response::abort(404);
        if (($ship['source'] ?? 'api') === 'manual') {
            Response::abort(404); // a manually added courier has its own AWB file: see manualAwb()
        }
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $this->view('manage/orders/awb', [
            'base' => $this->base(),
            'order' => $order,
            'ship' => $ship,
            'items' => NinjaVanSettings::showItemsOnAwb() ? OrderService::items((int) $order['id']) : null,
            'sender' => NinjaVanSettings::sender(),
            'autoPrint' => (string) $request->input('print', '') === '1',
        ], null);
    }

    /** Several labels on one print run: only Processing orders that have a tracking number. */
    public function bulk(Request $request): void
    {
        $ids = $this->ids($request);
        $orders = OrderService::findAmong($ids);
        $ships = ShipmentService::activeAmong($ids);
        // Manually added couriers carry their own AWB file, so they are not part of this print run.
        $ready = array_values(array_filter($ids, fn($id) => isset($orders[$id], $ships[$id]) && $orders[$id]['status'] === 'processing' && ($ships[$id]['source'] ?? 'api') !== 'manual'));
        if ($ready === []) {
            $this->backWithErrors($this->base() . '/orders?status=processing', ['form' => 'None of the selected orders is ready to print. Only Processing orders with a tracking number can be printed.']);
        }
        $items = NinjaVanSettings::showItemsOnAwb() ? OrderService::itemsAmong($ready) : [];
        $labels = array_map(fn($id) => [
            'order' => $orders[$id],
            'ship' => $ships[$id],
            'items' => NinjaVanSettings::showItemsOnAwb() ? ($items[$id] ?? []) : null,
        ], $ready);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        $this->view('manage/orders/awb_bulk', [
            'base' => $this->base(),
            'labels' => $labels,
            'sender' => NinjaVanSettings::sender(),
            'printedIds' => implode(',', $ready),
            'autoPrint' => (string) $request->input('print', '') === '1',
        ], null);
    }

    /** Several labels were sent to the printer (called by the bulk page). */
    public function awbPrintedAmong(Request $request): void
    {
        Response::json(['ok' => true, 'marked' => ShipmentService::markAwbPrintedAmong($this->ids($request))]);
    }

    /** The label was sent to the printer (called by the AWB page). */
    public function awbPrinted(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        Response::json(['ok' => ShipmentService::markAwbPrinted((int) $order['id'])]);
    }

    /** Order ids from "ids=1,2,3" (at most 50, digits only). @return int[] */
    private function ids(Request $request): array
    {
        return OrderService::idsFrom((string) $request->input('ids', ''));
    }

    public function waybill(Request $request, string $id): void
    {
        $order = OrderService::find((int) $id) ?? Response::abort(404);
        $s = ShipmentService::active((int) $order['id']) ?? Response::abort(404);
        if (($s['source'] ?? 'api') === 'manual') {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}#shipping", ['form' => 'This courier was added manually. Use View AWB to open its file.']);
        }
        if ($s['carrier'] === 'jnt') {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}#shipping", ['form' => 'Use Print AWB for J&T Express shipments.']);
        }
        if ($s['carrier'] === 'spx') {
            // SPX's label link expires after 30 minutes, so it is fetched now and sent straight on.
            $r = SpxExpressGateway::waybillUrl($s);
            // The link comes from SPX's response: only an https URL with no control characters may reach the Location header.
            if ($r['ok'] && (!str_starts_with($r['url'], 'https://') || preg_match('/[\x00-\x1F\x7F]/', $r['url']) || filter_var($r['url'], FILTER_VALIDATE_URL) === false)) {
                $r = ['ok' => false, 'error' => 'SPX returned a waybill link we could not open safely.'];
            }
            if (!$r['ok']) {
                $this->backWithErrors($this->base() . "/orders/{$order['id']}#shipping", ['form' => $r['error']]);
            }
            header('Cache-Control: private, no-store');
            header('Location: ' . $r['url'], true, 302);
            exit;
        }
        $r = NinjaVanGateway::waybill($s);
        if (!$r['ok']) {
            $this->backWithErrors($this->base() . "/orders/{$order['id']}#shipping", ['form' => $r['error']]);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="waybill-' . $s['tracking_number'] . '.pdf"');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($r['file']);
        exit;
    }
}
