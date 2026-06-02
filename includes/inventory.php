<?php
/**
 * inventory.php — Inventory domain helpers.
 *
 * Categories, units, movement kinds + reasons, and the transactional
 * stock-movement writer. Schema: sql/migrate_023_inventory.sql.
 */
declare(strict_types=1);

function inventory_categories(): array
{
    return [
        'montessori' => 'Montessori materials',
        'stationery' => 'Stationery',
        'art'        => 'Art & craft',
        'books'      => 'Books',
        'toys'       => 'Toys & play',
        'cleaning'   => 'Cleaning',
        'food'       => 'Food & snacks',
        'furniture'  => 'Furniture',
        'first_aid'  => 'First aid',
        'electronics'=> 'Electronics',
        'other'      => 'Other',
    ];
}

function inventory_category_label(string $code): string
{
    return inventory_categories()[$code] ?? ucfirst($code);
}

function inventory_units(): array
{
    return ['pcs', 'box', 'pack', 'set', 'kg', 'g', 'litre', 'ml', 'dozen', 'ream', 'roll', 'bottle'];
}

/** Reasons per movement kind, shown in the stock-movement form. */
function inventory_reasons(string $kind): array
{
    if ($kind === 'in') {
        return ['purchase' => 'Purchase', 'donation' => 'Donation', 'return' => 'Returned', 'correction' => 'Correction', 'other' => 'Other'];
    }
    if ($kind === 'out') {
        return ['consumed' => 'Consumed / used', 'damaged' => 'Damaged', 'lost' => 'Lost', 'expired' => 'Expired', 'issued' => 'Issued to class', 'other' => 'Other'];
    }
    return ['stocktake' => 'Stock-take correction', 'other' => 'Other']; // adjust
}

function inventory_reason_label(string $kind, string $code): string
{
    return inventory_reasons($kind)[$code] ?? $code;
}

/**
 * Apply a stock movement inside a transaction and keep the item quantity
 * in sync. Records balance_after on the ledger row.
 *
 *   kind = 'in'      → quantity += qty
 *   kind = 'out'     → quantity -= qty   (clamped at 0)
 *   kind = 'adjust'  → quantity  = qty   (absolute set, for stock-takes)
 *
 * Returns the new balance. Throws on bad input.
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
        else                     $balance = $qty; // adjust = absolute

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

/** Quick stats for the index header. */
function inventory_stats(): array
{
    try {
        $r = db()->query("
            SELECT
                COUNT(*)                                          AS items,
                COALESCE(SUM(quantity * COALESCE(unit_cost,0)),0) AS value,
                SUM(quantity <= reorder_level AND reorder_level > 0) AS low
            FROM inventory_items WHERE is_active = 1
        ")->fetch();
        return [
            'items' => (int)($r['items'] ?? 0),
            'value' => (float)($r['value'] ?? 0),
            'low'   => (int)($r['low'] ?? 0),
        ];
    } catch (Throwable $e) {
        return ['items' => 0, 'value' => 0.0, 'low' => 0];
    }
}

function inventory_money(float $v): string
{
    return "\u{20B9}" . number_format($v, 2);
}

/** Pretty quantity: drop trailing .00 (so 5.00 → "5", 2.50 → "2.5"). */
function inventory_qty(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}
