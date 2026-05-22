<?php
/**
 * expenses/admin.php — manage expense categories.
 *
 * Admin-only. Categories are a thin lookup table (id, name, display_order,
 * is_active). Deleting a category sets the FK to NULL on existing rows.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $order = (int)($_POST['display_order'] ?? 0);
        if ($name === '') {
            flash_set('error', 'Category name is required.');
            redirect('/expenses/admin.php');
        }
        try {
            $stmt = db()->prepare("INSERT INTO expense_categories (name, display_order, is_active) VALUES (:n, :o, 1)");
            $stmt->execute([':n' => $name, ':o' => $order]);
            flash_set('ok', 'Category added.');
        } catch (PDOException $e) {
            flash_set('error', 'Category name must be unique.');
        }
        redirect('/expenses/admin.php');
    }

    if ($op === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $order  = (int)($_POST['display_order'] ?? 0);
        $active = !empty($_POST['is_active']) ? 1 : 0;
        if ($id <= 0 || $name === '') {
            flash_set('error', 'Bad input.');
            redirect('/expenses/admin.php');
        }
        try {
            $stmt = db()->prepare("UPDATE expense_categories SET name=:n, display_order=:o, is_active=:a WHERE id=:id");
            $stmt->execute([':n' => $name, ':o' => $order, ':a' => $active, ':id' => $id]);
            flash_set('ok', 'Category updated.');
        } catch (PDOException $e) {
            flash_set('error', 'Category name must be unique.');
        }
        redirect('/expenses/admin.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("DELETE FROM expense_categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        flash_set('ok', 'Category deleted.');
        redirect('/expenses/admin.php');
    }
}

$cats = db()->query("
    SELECT c.*, (SELECT COUNT(*) FROM expenses e WHERE e.category_id = c.id) AS n_used
    FROM expense_categories c
    ORDER BY c.display_order, c.name
")->fetchAll();

$pageTitle = 'Expense categories';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Expense categories</h1>
        <p class="muted">These are the buckets users pick from on the new-expense form.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn" href="/expenses/index.php">← All expenses</a>
    </div>
</div>

<details class="card card-form" open>
    <summary>Add a category</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="row">
            <div class="field" style="flex: 2 1 320px;">
                <label>Name</label>
                <input name="name" maxlength="60" required placeholder="e.g. Library books">
            </div>
            <div class="field">
                <label>Order</label>
                <input name="display_order" type="number" value="10" min="0" max="999">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Add</button>
        </div>
    </form>
</details>

<ul class="team-list">
    <?php foreach ($cats as $c):
        $fid = 'cat-edit-' . (int)$c['id'];
    ?>
        <form id="<?= $fid ?>" method="post" hidden>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="update">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        </form>
        <form id="cat-del-<?= (int)$c['id'] ?>" method="post" hidden
              onsubmit="return confirm('Delete this category? Existing expenses will lose the category but be kept.');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="delete">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        </form>
        <li class="team-row">
            <div>
                <div class="team-name"><?= e($c['name']) ?></div>
                <div class="team-meta">
                    Order <?= (int)$c['display_order'] ?>
                    · <?= $c['is_active'] ? 'active' : 'inactive' ?>
                    · used by <?= (int)$c['n_used'] ?> expense<?= (int)$c['n_used'] === 1 ? '' : 's' ?>
                </div>
            </div>
            <div class="team-edit">
                <input form="<?= $fid ?>" name="name" value="<?= e($c['name']) ?>" maxlength="60">
                <input form="<?= $fid ?>" name="display_order" type="number" value="<?= (int)$c['display_order'] ?>" min="0" max="999" style="width:5rem;">
                <label class="checkbox">
                    <input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
                <button class="btn" form="<?= $fid ?>">Save</button>
                <button class="link-btn" form="cat-del-<?= (int)$c['id'] ?>">Delete</button>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . '/../includes/footer.php'; ?>
