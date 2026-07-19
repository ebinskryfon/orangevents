<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/header.php';
require_permission('user_manage');

$db = get_db_connection();
$message = '';
$error = '';

// =========================================================
// POST HANDLERS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD USER ──────────────────────────────────────────
    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role_id  = (int)($_POST['role_id'] ?? 0);

        if (empty($username) || empty($password) || $role_id <= 0) {
            $error = 'All fields are required.';
        } else {
            // Get role name to keep legacy role column in sync
            $role_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = :id");
            $role_stmt->execute(['id' => $role_id]);
            $role_name = $role_stmt->fetchColumn();

            if (!$role_name) {
                $error = 'Invalid role selected.';
            } else {
                try {
                    $db->beginTransaction();

                    // Insert user
                    $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt_user = $db->prepare("INSERT INTO users (username, password, role) VALUES (:username, :password, :role)");
                    $stmt_user->execute([
                        'username' => $username,
                        'password' => $pass_hash,
                        'role'     => $role_name
                    ]);
                    $new_user_id = $db->lastInsertId();

                    // Insert user_role mapping
                    $stmt_ur = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $stmt_ur->execute([$new_user_id, $role_id]);

                    $db->commit();
                    $message = "User '{$username}' created successfully!";
                } catch (PDOException $e) {
                    $db->rollBack();
                    if ($e->getCode() == 23000) {
                        $error = 'Username already exists.';
                    } else {
                        $error = 'Failed to create user: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // ── EDIT USER ─────────────────────────────────────────
    if ($action === 'edit_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $role_id  = (int)($_POST['role_id'] ?? 0);

        if ($user_id <= 0 || empty($username) || $role_id <= 0) {
            $error = 'All fields are required.';
        } else {
            // Get role name to keep legacy role column in sync
            $role_stmt = $db->prepare("SELECT role_name FROM roles WHERE id = :id");
            $role_stmt->execute(['id' => $role_id]);
            $role_name = $role_stmt->fetchColumn();

            if (!$role_name) {
                $error = 'Invalid role selected.';
            } else {
                try {
                    $db->beginTransaction();

                    // Update user
                    $stmt_user = $db->prepare("UPDATE users SET username = :username, role = :role WHERE id = :id");
                    $stmt_user->execute([
                        'username' => $username,
                        'role'     => $role_name,
                        'id'       => $user_id
                    ]);

                    // Update user_role mapping (delete existing and insert new)
                    $db->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$user_id]);
                    $stmt_ur = $db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
                    $stmt_ur->execute([$user_id, $role_id]);

                    $db->commit();
                    $message = "User updated successfully!";
                } catch (PDOException $e) {
                    $db->rollBack();
                    if ($e->getCode() == 23000) {
                        $error = 'Username already exists.';
                    } else {
                        $error = 'Failed to update user: ' . $e->getMessage();
                    }
                }
            }
        }
    }

    // ── RESET PASSWORD ────────────────────────────────────
    if ($action === 'reset_password') {
        $user_id  = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['password'] ?? '';

        if ($user_id <= 0 || empty($password)) {
            $error = 'Password cannot be empty.';
        } else {
            try {
                $pass_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = :id");
                $stmt->execute([
                    'password' => $pass_hash,
                    'id'       => $user_id
                ]);
                $message = "Password updated successfully!";
            } catch (PDOException $e) {
                $error = 'Failed to update password: ' . $e->getMessage();
            }
        }
    }

    // ── DELETE USER ───────────────────────────────────────
    if ($action === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        
        if ($user_id === (int)$_SESSION['admin_id']) {
            $error = 'You cannot delete your own account.';
        } elseif ($user_id > 0) {
            try {
                $db->prepare("DELETE FROM users WHERE id = ?")->execute([$user_id]);
                $message = 'User deleted successfully.';
            } catch (PDOException $e) {
                $error = 'Failed to delete user: ' . $e->getMessage();
            }
        }
    }

    // ── UPDATE ROLE PERMISSIONS ───────────────────────────
    if ($action === 'update_role_permissions') {
        $role_perms = $_POST['permissions'] ?? []; // Array of role_id => [permission_id1, permission_id2]
        
        try {
            $db->beginTransaction();

            // Clear existing role permissions
            $db->exec("DELETE FROM role_permissions");

            // Insert new mappings
            $stmt = $db->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
            
            // Query all roles to ensure we iterate existing ones
            $all_roles_list = $db->query("SELECT id FROM roles")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($all_roles_list as $r_id) {
                if (isset($role_perms[$r_id]) && is_array($role_perms[$r_id])) {
                    foreach ($role_perms[$r_id] as $p_id) {
                        $stmt->execute([$r_id, (int)$p_id]);
                    }
                }
            }

            $db->commit();
            
            // Clear current user cached permissions so they reload immediately
            unset($_SESSION['admin_permissions']);
            
            $message = 'Role permissions updated successfully!';
        } catch (PDOException $e) {
            $db->rollBack();
            $error = 'Failed to update role permissions: ' . $e->getMessage();
        }
    }
}

// =========================================================
// FETCH DATA
// =========================================================
// Fetch all users with their assigned roles
$users = $db->query("
    SELECT u.id, u.username, u.role AS legacy_role, r.id AS role_id, r.role_name, r.description AS role_description
      FROM users u
 LEFT JOIN user_roles ur ON u.id = ur.user_id
 LEFT JOIN roles r ON ur.role_id = r.id
     ORDER BY u.id ASC
")->fetchAll();

// Fetch all roles
$roles = $db->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// Fetch all permissions
$permissions = $db->query("SELECT * FROM permissions ORDER BY id ASC")->fetchAll();

// Fetch all role permissions mapping for checkboxes matrix
$role_perms_raw = $db->query("SELECT role_id, permission_id FROM role_permissions")->fetchAll();
$role_perms_mapped = [];
foreach ($role_perms_raw as $rp) {
    $role_perms_mapped[$rp['role_id']][] = $rp['permission_id'];
}
?>

<style>
    .table th, .table td { padding: 0.4rem 0.65rem; font-size: 0.82rem; }
    .card { padding: 0.85rem !important; }
</style>

<div class="content-header" style="margin-bottom: 0.75rem; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color); flex-shrink: 0;">
    <div class="header-title">
        <h1 style="display:flex; align-items:center; gap:0.5rem; font-size:1.4rem; font-weight:800; color:var(--text-primary); margin:0;">
            <i class="fa-solid fa-users-gear" style="color:var(--accent-color);"></i>
            User & Role Management
        </h1>
        <p style="color:var(--text-secondary); margin:0.15rem 0 0; font-size:0.75rem;">Control user credentials, manage roles, and fine-tune security permissions.</p>
    </div>
</div>

<!-- Alerts -->
<?php if ($message): ?>
    <div style="background:rgba(46,213,115,0.15); color:var(--success); border:1px solid var(--success); padding:0.55rem 0.85rem; border-radius:var(--border-radius-sm); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem; font-size:0.82rem;">
        <i class="fa-solid fa-circle-check"></i><span><?= h($message) ?></span>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background:rgba(255,71,87,0.15); color:var(--danger); border:1px solid var(--danger); padding:0.55rem 0.85rem; border-radius:var(--border-radius-sm); margin-bottom:0.75rem; display:flex; align-items:center; gap:0.5rem; font-size:0.82rem;">
        <i class="fa-solid fa-circle-exclamation"></i><span><?= h($error) ?></span>
    </div>
<?php endif; ?>

<!-- Tabs Switcher -->
<div style="display:flex; gap:0.5rem; margin-bottom:0.75rem; border-bottom:1px solid var(--border-color); padding-bottom:0.35rem;">
    <button onclick="switchTab('tabUsers')" id="btnTabUsers" class="btn btn-secondary active-tab" style="background:transparent; border:none; font-size:0.85rem; font-weight:700; color:var(--accent-color); padding:0.3rem 0.75rem; cursor:pointer; display:flex; align-items:center; gap:0.4rem;">
        <i class="fa-solid fa-user-shield"></i> User Accounts
    </button>
    <button onclick="switchTab('tabPermissions')" id="btnTabPermissions" class="btn btn-secondary" style="background:transparent; border:none; font-size:0.85rem; font-weight:600; color:var(--text-secondary); padding:0.3rem 0.75rem; cursor:pointer; display:flex; align-items:center; gap:0.4rem;">
        <i class="fa-solid fa-key"></i> Role Permissions Matrix
    </button>
</div>

<!-- ============================================================
     TAB 1: USER ACCOUNTS
     ============================================================ -->
<div id="tabUsers" class="tab-content">
    <div class="card" style="margin-bottom: 0.75rem;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem; border-bottom:1px solid var(--border-color); padding-bottom:0.4rem;">
            <h2 style="margin:0; font-size:0.95rem; font-weight:700; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-users" style="color:var(--accent-color);"></i>
                Active Users (<?= count($users) ?>)
            </h2>
            <button onclick="openAddUserModal()" class="btn btn-primary" style="height:32px; font-size:0.8rem; padding:0 0.85rem;">
                <i class="fa-solid fa-user-plus"></i> Add New User
            </button>
        </div>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 10%;">ID</th>
                        <th style="width: 30%;">Username</th>
                        <th style="width: 25%;">Assigned Role</th>
                        <th style="width: 35%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['id'] ?></td>
                            <td style="font-weight: 600; color: var(--text-primary);"><?= h($u['username']) ?></td>
                            <td>
                                <span class="badge" style="background: <?= $u['role_name'] === 'admin' ? 'rgba(255, 71, 87, 0.12)' : ($u['role_name'] === 'manager' ? 'rgba(30, 144, 255, 0.12)' : 'rgba(46, 213, 115, 0.12)') ?>; color: <?= $u['role_name'] === 'admin' ? 'var(--danger)' : ($u['role_name'] === 'manager' ? 'var(--info)' : 'var(--success)') ?>; font-weight: 700; text-transform: uppercase;">
                                    <?= h($u['role_name'] ?: $u['legacy_role'] ?: 'None') ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.5rem;">
                                    <button class="btn btn-secondary" style="padding: 0.4rem 0.75rem; font-size: 0.8rem;" onclick='openEditUserModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)'>
                                        <i class="fa-solid fa-user-pen"></i> Edit
                                    </button>
                                    <button class="btn btn-secondary" style="padding: 0.4rem 0.75rem; font-size: 0.8rem; background: rgba(255, 165, 2, 0.12); color: var(--warning); border-color: rgba(255, 165, 2, 0.15);" onclick='openResetPasswordModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)'>
                                        <i class="fa-solid fa-key"></i> Pass
                                    </button>
                                    <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure you want to delete user <?= h($u['username']) ?>?');">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-danger" style="padding: 0.4rem 0.75rem; font-size: 0.8rem;" <?= $u['id'] == $_SESSION['admin_id'] ? 'disabled style="opacity: 0.5; cursor: not-allowed;" title="You cannot delete yourself"' : '' ?>>
                                            <i class="fa-solid fa-user-minus"></i> Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================
     TAB 2: ROLE PERMISSIONS MATRIX
     ============================================================ -->
<div id="tabPermissions" class="tab-content" style="display: none;">
    <div class="card">
        <div style="border-bottom:1px solid var(--border-color); padding-bottom:0.4rem; margin-bottom:0.75rem;">
            <h2 style="margin:0; font-size:0.95rem; font-weight:700; display:flex; align-items:center; gap:0.4rem;">
                <i class="fa-solid fa-shield-halved" style="color:var(--accent-color);"></i>
                Role-Based Access Control (RBAC) Settings
            </h2>
            <p style="color:var(--text-secondary); margin-top:0.2rem; font-size:0.75rem;">Check/uncheck permissions mapping for each system role and click Save to instantly update access privileges.</p>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_role_permissions">
            
            <div class="table-responsive" style="margin-bottom: 1.5rem;">
                <table class="table" style="vertical-align: middle;">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Permission / Capabilities</th>
                            <?php foreach ($roles as $r): ?>
                                <th style="text-align: center; width: 20%; font-family: 'Outfit', sans-serif;">
                                    <div style="font-weight: 700; text-transform: uppercase; color: var(--text-primary);"><?= h($r['role_name']) ?></div>
                                    <div style="font-size: 0.7rem; font-weight: normal; color: var(--text-muted); text-transform: none; max-width: 140px; margin: 0.25rem auto 0 auto; line-height: 1.3;"><?= h($r['description']) ?></div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($permissions as $p): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--text-primary);"><?= h($p['permission_key']) ?></div>
                                    <div style="font-size: 0.78rem; color: var(--text-secondary); margin-top: 0.15rem;"><?= h($p['description']) ?></div>
                                </td>
                                <?php foreach ($roles as $r): 
                                    $checked = isset($role_perms_mapped[$r['id']]) && in_array($p['id'], $role_perms_mapped[$r['id']]);
                                ?>
                                    <td style="text-align: center;">
                                        <input type="checkbox" 
                                               name="permissions[<?= $r['id'] ?>][]" 
                                               value="<?= $p['id'] ?>"
                                               <?= $checked ? 'checked' : '' ?>
                                               style="width: 20px; height: 20px; accent-color: var(--accent-color); cursor: pointer;"
                                        >
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="border-top:1px solid var(--border-color); padding-top:0.75rem; display:flex; justify-content:flex-end;">
                <button type="submit" class="btn btn-primary" style="height:32px; font-size:0.8rem; padding:0 0.85rem; display:flex; align-items:center; gap:0.4rem;">
                    <i class="fa-solid fa-floppy-disk"></i> Save Permissions Map
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================================
     MODALS
     ============================================================ -->

<!-- Modal: Add User -->
<div id="addUserModal" class="modal">
    <div class="modal-content" style="max-width: 440px;">
        <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-user-plus" style="color: var(--accent-color);"></i> Add System User
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="add_user">
            
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" placeholder="e.g. cashier_john" required autocomplete="off">
            </div>
            
            <div class="form-group" style="margin-top: 1rem;">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter secure password" required autocomplete="new-password">
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label class="form-label">Assigned Role</label>
                <select name="role_id" class="form-control" required>
                    <option value="">Select system role...</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= h(strtoupper($r['role_name'])) ?> - <?= h($r['description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" onclick="closeModal('addUserModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit User -->
<div id="editUserModal" class="modal">
    <div class="modal-content" style="max-width: 440px;">
        <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-user-pen" style="color: var(--accent-color);"></i> Edit System User
        </h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" id="editUserId">
            
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="username" id="editUsername" class="form-control" required autocomplete="off">
            </div>

            <div class="form-group" style="margin-top: 1rem;">
                <label class="form-label">Assigned Role</label>
                <select name="role_id" id="editUserRoleId" class="form-control" required>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"><?= h(strtoupper($r['role_name'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" onclick="closeModal('editUserModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Reset Password -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content" style="max-width: 420px;">
        <button class="modal-close" onclick="closeModal('resetPasswordModal')">&times;</button>
        <h3 style="margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fa-solid fa-key" style="color: var(--accent-color);"></i> Reset Password
        </h3>
        <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1rem;">Set a new password for user: <strong id="resetPassUsername" style="color: var(--text-primary);"></strong></p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetPassUserId">
            
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="password" class="form-control" placeholder="Enter new password" required autocomplete="new-password">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 1.5rem;">
                <button type="button" onclick="closeModal('resetPasswordModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-circle-check"></i> Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
// Tab Swapping
function switchTab(tabId) {
    // Hide all contents
    document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
    // Show target content
    document.getElementById(tabId).style.display = 'block';

    // Remove active styles from both buttons
    const btnUsers = document.getElementById('btnTabUsers');
    const btnPerms = document.getElementById('btnTabPermissions');
    
    btnUsers.style.color = 'var(--text-secondary)';
    btnUsers.style.fontWeight = '600';
    btnPerms.style.color = 'var(--text-secondary)';
    btnPerms.style.fontWeight = '600';

    // Set active style on current tab button
    const activeBtn = tabId === 'tabUsers' ? btnUsers : btnPerms;
    activeBtn.style.color = 'var(--accent-color)';
    activeBtn.style.fontWeight = '700';
}

// Add User Modal Helper
function openAddUserModal() {
    openModal('addUserModal');
}

// Edit User Modal Helper
function openEditUserModal(user) {
    document.getElementById('editUserId').value = user.id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editUserRoleId').value = user.role_id || '';
    openModal('editUserModal');
}

// Reset Password Modal Helper
function openResetPasswordModal(user) {
    document.getElementById('resetPassUserId').value = user.id;
    document.getElementById('resetPassUsername').textContent = user.username;
    openModal('resetPasswordModal');
}
</script>

<?php
require_once __DIR__ . '/../includes/footer.php';
?>
