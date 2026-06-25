<?php
/**
 * inventory.php — Inventory domain helpers.
 *
 * Master spec: docs/goal. Categories, sub-categories, units, locations,
 * conditions and statuses are FIXED here so the UI dropdowns + the
 * server-side validator both consult one source of truth.
 *
 * Schema: sql/migrate_023_inventory.sql (base) + 031 (master-spec
 * additions: sub_category, purchase_date, condition, assigned_to,
 * last_stock_check, status; sku becomes the unique Item ID).
 */
declare(strict_types=1);

/** Master category → sub-category map. Keep keys/labels in sync with the goal. */
function inventory_category_map(): array
{
    return [
        'Uniform'             => ['Playgroup', 'Nursery', 'LKG', 'UKG'],
        'School Bag'          => ['Playgroup', 'Nursery', 'LKG', 'UKG'],
        'Textbook'            => ['Playgroup', 'Nursery', 'LKG', 'UKG'],
        'Montessori Material' => ['Practical Life', 'Sensorial', 'Language', 'Math', 'Culture'],
        'Toys'                => ['Indoor', 'Outdoor', 'STEM'],
        'Books'               => ['Story Books', 'Teacher Resources'],
        'Furniture'           => ['Chairs', 'Tables', 'Shelves'],
        'Art & Craft'         => ['Paints', 'Crayons', 'Paper'],
        'Stationery'          => ['Pens', 'Markers', 'Files'],
        'Cleaning Supplies'   => ['Cleaning', 'Hygiene'],
        'Electronics'         => ['Laptop', 'Printer', 'Speaker'],
    ];
}

function inventory_categories(): array { return array_keys(inventory_category_map()); }
function inventory_subcats_for(string $cat): array { return inventory_category_map()[$cat] ?? []; }

function inventory_units():     array { return ['Nos', 'Sets', 'Packs', 'Boxes', 'Liters']; }
function inventory_locations(): array { return ['Classroom', 'Office', 'Store Room', 'Daycare', 'Theme Room']; }

/** Condition codes → labels (stored as the code; rendered as the label). */
function inventory_conditions(): array
{
    return [
        'new'           => 'New',
        'good'          => 'Good',
        'repair_needed' => 'Repair Needed',
        'damaged'       => 'Damaged',
    ];
}
function inventory_condition_label(string $code): string
{
    return inventory_conditions()[$code] ?? ucfirst($code);
}

/** Status codes → labels. 'active' is "in service"; everything else retires the row. */
function inventory_statuses(): array
{
    return [
        'active'   => 'Active',
        'issued'   => 'Issued',
        'lost'     => 'Lost',
        'damaged'  => 'Damaged',
        'disposed' => 'Disposed',
    ];
}
function inventory_status_label(string $code): string
{
    return inventory_statuses()[$code] ?? ucfirst($code);
}

/** Statuses that mean the item is retired (out of active service). */
function inventory_retired_statuses(): array { return ['lost', 'damaged', 'disposed']; }

/** Backwards-compat shim — older callers used this for the category label. */
function inventory_category_label(string $codeOrLabel): string
{
    // Legacy code → master label mapping for any row not caught by migration 031.
    $legacy = [
        'montessori' => 'Montessori Material', 'stationery' => 'Stationery',
        'art'        => 'Art & Craft',         'books'      => 'Books',
        'toys'       => 'Toys',                'cleaning'   => 'Cleaning Supplies',
        'furniture'  => 'Furniture',           'electronics'=> 'Electronics',
    ];
    return $legacy[$codeOrLabel] ?? $codeOrLabel;
}

/**
 * Stock-movement helpers retained from the original module — the UI calls
 * them when admin records a manual in/out/adjust on a row. Schema lives
 * in inventory_movements (sql/migrate_023_inventory.sql).
 */
function inventory_reasons(string $kind): array
{
    if ($kind === 'in') {
        return ['purchase' => 'Purchase', 'donation' => 'Donation', 'return' => 'Returned', 'correction' => 'Correction', 'other' => 'Other'];
    }
    if ($kind === 'out') {
        return ['consumed' => 'Consumed / used', 'damaged' => 'Damaged', 'lost' => 'Lost', 'expired' => 'Expired', 'issued' => 'Issued to class', 'other' => 'Other'];
    }
    return ['stocktake' => 'Stock-take correction', 'other' => 'Other'];
}
function inventory_reason_label(string $kind, string $code): string
{
    return inventory_reasons($kind)[$code] ?? $code;
}

/**
 * Apply a stock movement and keep inventory_items.quantity in sync.
 *   kind = 'in'      → quantity += qty
 *   kind = 'out'     → quantity -= qty   (clamped at 0)
 *   kind = 'adjust'  → quantity  = qty   (absolute)
 */
function inventory_move(int $itemId, string $kind, float $qty, ?string $reason, ?string $note, int $byUserId): float
{
    if (!in_array($kind, ['in', 'out', 'adjust'], true)) {
        throw new InvalidArgumentException('bad movement kind');
    }
    if ($kind !== 'adjust' && $qty <= 0) {
        throw new InvalidArgumentException('quantity must be positive');
    }
    if ($qty < 0) $qty = 0;

    $pdo = db();
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) $pdo->beginTransaction();
    try {
        $cur = $pdo->prepare("SELECT quantity FROM inventory_items WHERE id = :id FOR UPDATE");
        $cur->execute([':id' => $itemId]);
        $row = $cur->fetch();
        if (!$row) throw new RuntimeException('item not found');
        $balance = (float)$row['quantity'];

        if ($kind === 'in')      $balance += $qty;
        elseif ($kind === 'out') $balance = max(0, $balance - $qty);
        else                     $balance = $qty;

        $pdo->prepare("UPDATE inventory_items SET quantity = :q WHERE id = :id")
            ->execute([':q' => $balance, ':id' => $itemId]);

        $pdo->prepare("
            INSERT INTO inventory_movements
                (item_id, kind, quantity, balance_after, reason, note, moved_by)
            VALUES (:i, :k, :q, :b, :r, :n, :by)
        ")->execute([
            ':i' => $itemId, ':k' => $kind, ':q' => $qty, ':b' => $balance,
            ':r' => $reason ?: null, ':n' => $note ?: null, ':by' => $byUserId,
        ]);

        if ($ownTxn) $pdo->commit();
        return $balance;
    } catch (Throwable $e) {
        if ($ownTxn) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Quick stats for the index header + home tile.
 *   items     active items (status='active')
 *   value     total ₹ on hand   (sum quantity * unit_cost) on active items
 *   low       items where quantity <= reorder_level and reorder_level > 0
 *   due       items not verified in 90+ days (NULL counts as due)
 *   attention items in poor condition (repair_needed/damaged)
 */
function inventory_stats(): array
{
    try {
        $r = db()->query("
            SELECT
                SUM(status = 'active')                                                AS items,
                COALESCE(SUM(CASE WHEN status='active' THEN quantity * COALESCE(unit_cost,0) ELSE 0 END), 0) AS value,
                SUM(status='active' AND reorder_level > 0 AND quantity <= reorder_level) AS low,
                SUM(status='active' AND (last_stock_check IS NULL OR last_stock_check < DATE_SUB(CURDATE(), INTERVAL 90 DAY))) AS due,
                SUM(status='active' AND `condition` IN ('repair_needed','damaged'))   AS attention
            FROM inventory_items
        ")->fetch();
        return [
            'items'     => (int)($r['items']     ?? 0),
            'value'     => (float)($r['value']   ?? 0),
            'low'       => (int)($r['low']       ?? 0),
            'due'       => (int)($r['due']       ?? 0),
            'attention' => (int)($r['attention'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['items' => 0, 'value' => 0.0, 'low' => 0, 'due' => 0, 'attention' => 0];
    }
}

function inventory_money(float $v): string { return "\u{20B9}" . number_format($v, 2); }

/** Pretty quantity: drop trailing .00 (so 5.00 → "5", 2.50 → "2.5"). */
function inventory_qty(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}
