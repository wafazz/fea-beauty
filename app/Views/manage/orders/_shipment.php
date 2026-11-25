<?php
/** Expects: $order, $base, $canUpdate, $shipment (active|null), $shipEvents, $shipBlock (string|null), $shipHistory */
use App\Services\Shipping\NinjaVanGateway as NV;
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$f = fn(string $k, string $d = '') => old($k, $d);
?>
<section id="shipping">
    <h2>Shipping · NinjaVan</h2>
    <?php if ($shipment && ($shipment['source'] ?? 'api') === 'manual'): ?>
        <p><strong><?= e($shipment['tracking_number']) ?></strong>
            <span class="badge st-processing">Added manually</span>
            <small class="muted"> · <?= e(\App\Services\ManualShipmentService::courierName($shipment['carrier'])) ?></small></p>
        <p>
            <?php if (!empty($shipment['tracking_url'])): ?>
                <a class="btn secondary" href="<?= e($shipment['tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Open tracking page</a>
            <?php endif; ?>
            <?php if (!empty($shipment['awb_file'])): ?>
                <a class="btn secondary" href="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/manual-awb')) ?>" target="_blank" rel="noopener">View AWB (<?= e($shipment['awb_original_name'] ?? 'file') ?>)</a>
            <?php endif; ?>
        </p>
        <?php if ($canUpdate): ?>
            <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/manual-awb')) ?>" class="card-form" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <label><?= empty($shipment['awb_file']) ? 'Upload AWB' : 'Replace AWB' ?> (PDF, JPG or PNG, max 5 MB)
                    <input type="file" name="awb" accept="application/pdf,image/jpeg,image/png" required><?= field_error('awb') ?></label>
                <button class="btn secondary">Upload AWB</button>
            </form>
            <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/cancel')) ?>" class="inline" data-swal-confirm="Remove this courier booking?" data-swal-text="Nothing is sent to the courier. Use this if the booking was wrong." data-swal-button="Yes, remove" data-swal-danger="1">
                <?= csrf_field() ?><button class="btn secondary">Remove courier</button></form>
        <?php endif; ?>
    <?php elseif ($shipment && ($shipment['carrier'] ?? '') === 'spx'): ?>
        <p><strong><?= e($shipment['tracking_number']) ?></strong>
            <span class="badge st-processing"><?= e($shipment['status']) ?></span>
            <small class="muted"> · SPX Express · <?= e($shipment['weight_kg']) ?> kg
                <?= $shipment['pickup_required'] ? ' · SPX collects from your pickup address' : ' · drop-off by you' ?>
                <?= $shipment['cod_amount'] !== null ? ' · collect ' . e(money($shipment['cod_amount'])) . ' (COD)' : '' ?></small></p>
        <p>
            <a class="btn secondary" href="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/waybill')) ?>" target="_blank" rel="noopener">Download SPX waybill (PDF)</a>
            <?php if ($canUpdate): ?>
                <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/cancel')) ?>" class="inline" data-swal-confirm="Cancel this SPX Express shipment?" data-swal-text="Only parcels still waiting for pickup can be cancelled." data-swal-button="Yes, cancel shipment">
                    <?= csrf_field() ?><button class="btn secondary">Cancel shipment</button></form>
            <?php endif; ?>
        </p>
        <p><small class="muted">Live tracking updates for SPX are not wired yet. Check the status on SPX's tracking page using the tracking number above.</small></p>
    <?php elseif ($shipment && ($shipment['carrier'] ?? '') === 'jnt'): ?>
        <p><strong><?= e($shipment['tracking_number']) ?></strong>
            <span class="badge st-processing"><?= e($shipment['status']) ?></span>
            <small class="muted"> · J&amp;T Express · <?= e($shipment['weight_kg']) ?> kg
                <?= $shipment['cod_amount'] !== null ? ' · collect ' . e(money($shipment['cod_amount'])) . ' (COD)' : '' ?></small></p>
        <p><small class="muted">Print the AWB from the order list. Tracking and cancellation for J&amp;T are in the J&amp;T portal for now.</small></p>
    <?php elseif ($shipment): ?>
        <p><strong><?= e($shipment['tracking_number']) ?></strong>
            <span class="badge st-processing"><?= e($shipment['status']) ?></span>
            <small class="muted"> · <?= e($shipment['service_level']) ?> · <?= e($shipment['weight_kg']) ?> kg
                · deliver <?= e($shipment['delivery_date']) ?> (<?= e(NV::SLOTS[$shipment['delivery_timeslot']] ?? $shipment['delivery_timeslot']) ?>)
                <?= $shipment['pickup_required'] ? ' · pickup ' . e($shipment['pickup_date']) . ' (' . e(NV::SLOTS[$shipment['pickup_timeslot']] ?? '') . ')' : ' · drop-off by you' ?>
                <?= $shipment['cod_amount'] !== null ? ' · collect ' . e(money($shipment['cod_amount'])) . ' (COD)' : '' ?></small></p>
        <p>
            <a class="btn secondary" href="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/waybill')) ?>" target="_blank" rel="noopener">Download waybill (PDF)</a>
            <?php if ($canUpdate): ?>
                <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/cancel')) ?>" class="inline" data-swal-confirm="Cancel this NinjaVan shipment?" data-swal-text="Only parcels still Pending Pickup can be cancelled." data-swal-button="Yes, cancel shipment" data-swal-danger="1">
                    <?= csrf_field() ?><button class="btn secondary">Cancel shipment</button></form>
            <?php endif; ?>
        </p>
        <?php if ($shipEvents !== []): ?>
            <table>
                <thead><tr><th>When</th><th>Status</th><th>Note</th></tr></thead>
                <tbody><?php foreach ($shipEvents as $ev): ?>
                    <tr><td><?= e($ev['occurred_at']) ?></td><td><?= e($ev['status']) ?></td><td><?= e($ev['comments'] ?? '') ?></td></tr>
                <?php endforeach; ?></tbody>
            </table>
        <?php else: ?>
            <p class="muted">No tracking updates yet. They appear here once NinjaVan's webhook is registered (see the NinjaVan settings page).</p>
        <?php endif; ?>
    <?php elseif (!$canUpdate): ?>
        <p class="muted">No shipment yet.</p>
    <?php elseif ($shipBlock !== null): ?>
        <p class="muted"><?= e($shipBlock) ?></p>
    <?php else: ?>
        <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment')) ?>" class="card-form wide" novalidate
              data-swal-confirm="Push this order to NinjaVan?" data-swal-text="This books a real parcel with NinjaVan. Check the weight and delivery date first." data-swal-button="Yes, create shipment">
            <?= csrf_field() ?>
            <div class="grid2">
                <label>Parcel weight (kg) <input name="weight" inputmode="decimal" value="<?= $f('weight', '1') ?>"><?= field_error('weight') ?></label>
                <label>Service level
                    <select name="service_level"><?php foreach (NV::LEVELS as $k => $label): ?><option value="<?= e($k) ?>" <?= $f('service_level', 'Standard') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error('service_level') ?></label>
                <label>Delivery from <input type="date" name="delivery_date" value="<?= $f('delivery_date', $tomorrow) ?>" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+14 days'))) ?>"><?= field_error('delivery_date') ?></label>
                <label>Delivery window
                    <select name="delivery_slot"><?php foreach (NV::SLOTS as $k => $label): ?><option value="<?= e($k) ?>" <?= $f('delivery_slot', '09:00-18:00') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error('delivery_slot') ?></label>
            </div>
            <label class="check"><input type="checkbox" name="pickup" value="1" id="pk" data-toggle-box="pkbox" <?= $f('pickup', '1') === '1' ? 'checked' : '' ?>> Ask NinjaVan to collect the parcel from my pickup address</label>
            <div id="pkbox" class="grid2" <?= $f('pickup', '1') === '1' ? '' : 'hidden' ?>>
                <label>Pickup date <input type="date" name="pickup_date" value="<?= $f('pickup_date', date('Y-m-d')) ?>" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+7 days'))) ?>"><?= field_error('pickup_date') ?></label>
                <label>Pickup window
                    <select name="pickup_slot"><?php foreach (NV::SLOTS as $k => $label): ?><option value="<?= e($k) ?>" <?= $f('pickup_slot', '09:00-18:00') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= field_error('pickup_slot') ?></label>
                <label>Approximate volume
                    <select name="volume"><?php foreach (NV::VOLUMES as $vol): ?><option <?= $f('volume', 'Less than 3 Parcels') === $vol ? 'selected' : '' ?>><?= e($vol) ?></option><?php endforeach; ?></select><?= field_error('volume') ?></label>
            </div>
            <?php if ($order['payment_method'] === 'cod' && $order['payment_status'] === 'unpaid'): ?>
                <p class="muted">Cash on delivery: the courier will collect <?= e(money($order['total'])) ?>.</p>
            <?php endif; ?>
            <button class="btn">Create NinjaVan shipment</button>
            <small class="muted">The customer's address and the items on this order are sent to NinjaVan. The pickup address comes from the NinjaVan settings.</small>
        </form>
    <?php endif; ?>
    <?php if (!$shipment && $canUpdate && $order['status'] === 'processing'): ?>
        <form method="post" action="<?= e(url($base . '/orders/' . (int) $order['id'] . '/shipment/manual')) ?>" class="card-form wide" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <h3>Add courier manually</h3>
            <p class="muted">Already booked the parcel with a courier yourself? Enter its tracking number here. Nothing is sent to the courier.</p>
            <div class="grid2">
                <label>Courier
                    <select name="courier">
                        <option value="">Choose a courier</option>
                        <?php foreach (\App\Services\ManualShipmentService::COURIERS as $k => $label): ?>
                            <option value="<?= e($k) ?>" <?= $f('courier') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select><?= field_error('courier') ?></label>
                <label>Tracking number <input name="tracking_number" value="<?= $f('tracking_number') ?>" maxlength="60" autocomplete="off" spellcheck="false"><?= field_error('tracking_number') ?></label>
                <label>Tracking link (optional) <input type="url" name="tracking_url" value="<?= $f('tracking_url') ?>" maxlength="500" placeholder="https://" autocomplete="off"><?= field_error('tracking_url') ?></label>
                <label>AWB file (optional, PDF, JPG or PNG, max 5 MB) <input type="file" name="awb" accept="application/pdf,image/jpeg,image/png"><?= field_error('awb') ?></label>
            </div>
            <button class="btn">Save courier</button>
        </form>
    <?php endif; ?>
    <?php $old = array_filter($shipHistory, fn($h) => !$h['active']); if ($old !== []): ?>
        <p class="muted"><small>Earlier (cancelled): <?= e(implode(', ', array_column($old, 'tracking_number'))) ?></small></p>
    <?php endif; ?>
</section>
