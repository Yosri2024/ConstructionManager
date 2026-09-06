<?php
require_once 'config.php';
requireRole('manager');

$pdo = getDB();
$user = currentUser();

// --- CREATE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $errors = [];
    if (empty(trim($_POST['username']))) $errors[] = 'Username is required';
    if (empty(trim($_POST['full_name']))) $errors[] = 'Full name is required';
    if (empty($_POST['password']) || strlen($_POST['password']) < 4) $errors[] = 'Password must be at least 4 characters';
    if ($_POST['password'] !== $_POST['password2']) $errors[] = 'Passwords do not match';
    if (empty($_POST['role'])) $errors[] = 'Role is required';

    // Check unique username
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([trim($_POST['username'])]);
    if ($check->fetch()) $errors[] = 'Username already exists';

    if ($errors) {
        foreach ($errors as $e) flash($e);
        header('Location: users.php');
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, role, phone, email) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        trim($_POST['username']),
        password_hash($_POST['password'], PASSWORD_DEFAULT),
        trim($_POST['full_name']),
        $_POST['role'],
        trim($_POST['phone'] ?? ''),
        trim($_POST['email'] ?? '')
    ]);
    flash('User created successfully');
    header('Location: users.php');
    exit;
}

// --- DELETE ---
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    // Cannot delete yourself
    if ($delId === $user['id']) { flash('You cannot delete yourself'); header('Location: users.php'); exit; }
    // Cannot delete the last manager
    $remaining = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'manager'")->fetchColumn();
    $target = $pdo->query("SELECT role FROM users WHERE id = $delId")->fetchColumn();
    if ($target === 'manager' && $remaining <= 1) { flash('Cannot delete the last manager'); header('Location: users.php'); exit; }
    $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$delId]);
    flash('User deleted', 'warning');
    header('Location: users.php');
    exit;
}

// --- EDIT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $editId = (int)$_POST['id'];
    $errors = [];
    if (empty(trim($_POST['full_name']))) $errors[] = 'Full name is required';
    if (!empty($_POST['password']) && strlen($_POST['password']) < 4) $errors[] = 'Password must be at least 4 characters';
    if (!empty($_POST['password']) && $_POST['password'] !== $_POST['password2']) $errors[] = 'Passwords do not match';

    // Check username unique (excluding self)
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $check->execute([trim($_POST['username']), $editId]);
    if ($check->fetch()) $errors[] = 'Username already exists';

    if ($errors) {
        foreach ($errors as $e) flash($e);
        header('Location: users.php?edit=' . $editId);
        exit;
    }

    if (!empty($_POST['password'])) {
        $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, full_name=?, role=?, phone=?, email=? WHERE id=?");
        $stmt->execute([
            trim($_POST['username']), password_hash($_POST['password'], PASSWORD_DEFAULT),
            trim($_POST['full_name']), $_POST['role'],
            trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), $editId
        ]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, role=?, phone=?, email=? WHERE id=?");
        $stmt->execute([
            trim($_POST['username']), trim($_POST['full_name']), $_POST['role'],
            trim($_POST['phone'] ?? ''), trim($_POST['email'] ?? ''), $editId
        ]);
    }
    flash('User updated');
    header('Location: users.php');
    exit;
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$editing) { flash('User not found'); header('Location: users.php'); exit; }
}

$users = $pdo->query("SELECT u.*,
    (SELECT COUNT(*) FROM sites WHERE supervisor_id = u.id) as site_count
    FROM users u ORDER BY (u.role='manager') DESC, u.full_name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Construction Manager</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php require_once 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <h1>User Management</h1>
        <div class="topbar-actions">
            <button onclick="document.getElementById('userForm').style.display='block'" class="btn btn-primary">+ New User</button>
        </div>
    </div>
    <div class="content-area">
        <?php if ($msg = flash()): ?><div class="alert <?= flashType() ?> auto-dismiss"><?= h($msg) ?></div><?php endif; ?>

        <div class="card" id="userForm" style="display:<?= $editing ? 'block' : 'none' ?>">
            <div class="card-header">
                <h2><?= $editing ? 'Edit User' : 'Add New User' ?></h2>
                <a href="users.php" class="btn btn-sm btn-secondary">Close</a>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'create' ?>">
                    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username *</label>
                            <input type="text" name="username" required value="<?= h($editing['username'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="full_name" required value="<?= h($editing['full_name'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Role *</label>
                            <select name="role" required>
                                <option value="supervisor" <?= ($editing['role'] ?? '') === 'supervisor' ? 'selected' : '' ?>>Supervisor</option>
                                <option value="manager" <?= ($editing['role'] ?? '') === 'manager' ? 'selected' : '' ?>>Manager</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Phone</label>
                            <input type="text" name="phone" value="<?= h($editing['phone'] ?? '') ?>" placeholder="e.g. 555-0101">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" value="<?= h($editing['email'] ?? '') ?>" placeholder="user@company.com">
                        </div>
                        <div class="form-group">
                            <label><?= $editing ? 'New Password' : 'Password' ?> <?= $editing ? '(leave blank to keep)' : '*' ?></label>
                            <input type="password" name="password" <?= $editing ? '' : 'required' ?> placeholder="Min 4 characters">
                        </div>
                    </div>
                    <div class="form-group" style="max-width:300px">
                        <label>Confirm Password <?= $editing ? '(if changing)' : '*' ?></label>
                        <input type="password" name="password2" <?= $editing ? '' : 'required' ?> placeholder="Repeat password">
                    </div>
                    <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create User' ?></button>
                    <?php if ($editing): ?>
                        <a href="users.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>All Users (<?= count($users) ?>)</h2></div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Name</th><th>Username</th><th>Role</th><th>Phone</th><th>Email</th><th>Sites</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><strong><?= h($u['full_name']) ?></strong></td>
                            <td><?= h($u['username']) ?></td>
                            <td><span class="badge badge-<?= h($u['role']) ?>"><?= h($u['role']) ?></span></td>
                            <td><?= h($u['phone']) ?: '—' ?></td>
                            <td><?= h($u['email']) ?: '—' ?></td>
                            <td><?= (int)$u['site_count'] ?></td>
                            <td class="actions">
                                <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-sm btn-edit">Edit</a>
                                <?php if ($u['id'] !== $user['id']): ?>
                                <form method="GET" style="display:inline" data-confirm="Delete user '<?= h($u['full_name']) ?>?">
                                    <input type="hidden" name="delete" value="<?= $u['id'] ?>">
                                    <button class="btn btn-sm btn-delete">Del</button>
                                </form>
                                <?php else: ?>
                                <span style="color:#999;font-size:11px;padding:4px 8px">(you)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="js/app.js"></script>
</body>
</html>
