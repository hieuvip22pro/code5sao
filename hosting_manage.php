<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/hosting_functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$uid = current_user_id();

// Lấy thông tin hosting + gói
$stmt = $pdo->prepare('SELECT ha.*, hp.name as plan_name, hp.disk_quota_display, hp.bandwidth_display, hp.max_domains, hp.price_month FROM hosting_accounts ha JOIN hosting_plans hp ON hp.id = ha.plan_id WHERE ha.id = ? AND ha.user_id = ?');
$stmt->execute([$id, $uid]);
$hosting = $stmt->fetch();

if (!$hosting) {
    flash_set('error', 'Không tìm thấy gói hosting hoặc bạn không có quyền truy cập.');
    redirect('/dashboard?tab=hosting');
}

$cpanelUser = $hosting['cpanel_username'];
$serverId = $hosting['server_id'] ?? null;

// Xử lý các Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    
    // 1. Tự động gia hạn
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_auto_renew') {
        $new = $hosting['auto_renew'] ? 0 : 1;
        $pdo->prepare('UPDATE hosting_accounts SET auto_renew = ? WHERE id = ?')->execute([$new, $id]);
        flash_set('success', $new ? 'Đã bật tự động gia hạn.' : 'Đã tắt tự động gia hạn.');
        redirect('?id='.$id);
    }

    // 2. Đổi mật khẩu
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $newPass = trim($_POST['new_password'] ?? '');
        if (strlen($newPass) < 8) {
            flash_set('error', 'Mật khẩu phải có ít nhất 8 ký tự.');
        } else {
            try {
                whm_change_password($pdo, $cpanelUser, $newPass, $serverId);
                $encrypted = encrypt_secret($newPass);
                $pdo->prepare('UPDATE hosting_accounts SET cpanel_password = ? WHERE id = ?')->execute([$encrypted, $id]);
                flash_set('success', 'Đổi mật khẩu thành công!');
            } catch (Exception $e) {
                flash_set('error', $e->getMessage());
            }
        }
        redirect('?id='.$id);
    }

    // 3. Đổi tên miền
    if (isset($_POST['action']) && $_POST['action'] === 'change_domain') {
        $newDomain = strtolower(trim($_POST['new_domain'] ?? ''));
        $newDomain = preg_replace('#^https?://#', '', $newDomain);
        $newDomain = rtrim($newDomain, '/');
        
        if (!preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $newDomain)) {
            flash_set('error', 'Tên miền không hợp lệ.');
        } else {
            // Kiểm tra trùng trên hệ thống (chỉ check sơ bộ)
            $chk = $pdo->prepare("SELECT id FROM hosting_accounts WHERE domain = ? AND status != 'terminated'");
            $chk->execute([$newDomain]);
            if ($chk->fetch()) {
                flash_set('error', 'Tên miền này đã tồn tại trên hệ thống.');
            } else {
                try {
                    whm_change_domain($pdo, $cpanelUser, $newDomain, $serverId);
                    $pdo->prepare('UPDATE hosting_accounts SET domain = ? WHERE id = ?')->execute([$newDomain, $id]);
                    flash_set('success', 'Đổi tên miền thành công! Xin lưu ý có thể mất vài giờ để DNS cập nhật.');
                } catch (Exception $e) {
                    flash_set('error', $e->getMessage());
                }
            }
        }
        redirect('?id='.$id);
    }

    // 4. Xóa hosting
    if (isset($_POST['action']) && $_POST['action'] === 'delete_hosting') {
        $confirm = trim($_POST['confirm_delete'] ?? '');
        if ($confirm !== 'XOA') {
            flash_set('error', 'Bạn phải nhập chữ XOA để xác nhận.');
        } else {
            try {
                whm_terminate_account($pdo, $cpanelUser, $serverId);
                $pdo->prepare("UPDATE hosting_accounts SET status = 'terminated' WHERE id = ?")->execute([$id]);
                flash_set('success', 'Đã xóa vĩnh viễn gói hosting.');
                redirect('/dashboard?tab=hosting');
            } catch (Exception $e) {
                flash_set('error', $e->getMessage());
            }
        }
        redirect('?id='.$id);
    }
}

// Xử lý SSO login
if (isset($_GET['sso'])) {
    $dest = $_GET['sso'];
    $app = '';
    switch ($dest) {
        case 'cpanel': $app = 'cpaneld'; break;
        case 'filemanager': $app = 'cpaneld'; $goto = 'filemanager/index.html'; break;
        case 'email': $app = 'cpaneld'; $goto = 'mail/pops.html'; break;
        case 'db': $app = 'cpaneld'; $goto = 'sql/index.html'; break;
        case 'phpmyadmin': $app = 'cpaneld'; $goto = 'sql/PhpMyAdmin.html'; break;
        case 'domain': $app = 'cpaneld'; $goto = 'domains/index.html'; break;
        case 'cron': $app = 'cpaneld'; $goto = 'cron/index.html'; break;
        default: $app = 'cpaneld';
    }

    try {
        $sso_url = whm_get_sso_url($pdo, $cpanelUser, $app, $serverId);

        if (isset($goto)) {
            $parsed = parse_url($sso_url);
            $sso_url = $parsed['scheme'].'://'.$parsed['host'].(isset($parsed['port'])?':'.$parsed['port']:'').$parsed['path'];
            $sso_url .= (isset($parsed['query']) ? $parsed['query'].'&' : '') . 'goto_app=' . urlencode($goto);
        }

        // Redirect trực tiếp, KHÔNG dùng hàm redirect() nội bộ vì nó có thể
        // tự động nối thêm BASE_URL vào URL tuyệt đối, gây lỗi URL bị dính chuỗi.
        header('Location: ' . $sso_url);
        exit;
    } catch (Exception $e) {
        flash_set('error', 'Lỗi SSO: ' . $e->getMessage());
        redirect('?id='.$id);
    }
}

// Lấy thông tin dung lượng từ API (không dùng CloudLinux nên chỉ có Disk/BW)
$usage = [];
try {
    $usage = whm_get_usage($pdo, $cpanelUser, $serverId);
} catch (Exception $e) {
    $usage_error = $e->getMessage();
}

$page_title = 'Quản lý Hosting - ' . e($hosting['domain']);
require_once __DIR__ . '/includes/header.php';
?>

<style>
.layout-hosting { display: grid; grid-template-columns: 280px 1fr; gap: 24px; align-items: start; }
@media (max-width: 768px) { .layout-hosting { grid-template-columns: 1fr; } }
.sidebar-panel { background: #0b0e15; border: 1px solid var(--border); border-radius: 12px; padding: 16px; }
.dark-mode .sidebar-panel { background: #1f1f1f; }
.sidebar-panel .title { font-size: 16px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.action-btn { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--bg); border-radius: 8px; margin-bottom: 8px; font-weight: 500; color: var(--text); cursor: pointer; border: 1px solid transparent; transition: 0.2s; text-decoration: none; width: 100%; text-align: left; font-size:14px; }
.action-btn:hover { background: var(--border); }
.action-btn.danger { color: var(--danger); background: rgba(220, 53, 69, 0.1); border-color: rgba(220, 53, 69, 0.2); }
.action-btn.danger:hover { background: rgba(220, 53, 69, 0.2); }

.box-panel { background: #0b0e15; border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
.dark-mode .box-panel { background: #1f1f1f; }
.box-panel .title { font-size: 16px; font-weight: 600; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
.box-panel .hint { font-size: 13px; color: var(--muted); margin-bottom: 16px; margin-top: -16px; font-weight:normal; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
@media (max-width: 768px) { .grid-2, .grid-3 { grid-template-columns: 1fr; } }

.info-item { background: var(--bg); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
.info-label { font-size: 12px; color: var(--muted); text-transform: uppercase; font-weight: 600; margin-bottom: 6px; }
.info-value { font-size: 15px; font-weight: 600; color: var(--text); }

.copy-group { display: flex; align-items: center; justify-content: space-between; background: var(--bg); padding: 16px; border-radius: 8px; border: 1px solid var(--border); }
.copy-group .info { flex: 1; }
.copy-group .actions { display: flex; gap: 8px; }

.progress-wrap { margin-top: 8px; }
.progress-bar { height: 6px; background: var(--border); border-radius: 4px; overflow: hidden; margin-bottom: 6px; }
.progress-fill { height: 100%; background: var(--accent); }
.progress-text { font-size: 12px; color: var(--muted); font-family: monospace; }

.sso-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.sso-btn { display: flex; align-items: center; gap: 8px; padding: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; font-weight: 500; font-size: 14px; color: var(--text); text-decoration: none; transition: 0.2s; }
.sso-btn:hover { border-color: var(--accent); color: var(--accent); }

/* Switch Toggle */
.switch { position: relative; display: inline-block; width: 40px; height: 22px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border); transition: .4s; border-radius: 22px; }
.slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
input:checked + .slider { background-color: var(--accent); }
input:checked + .slider:before { transform: translateX(18px); }

/* Modals */
.modal { display: none; position: fixed; z-index: 999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); align-items:center; justify-content:center; }
.modal.show { display: flex; }
.modal-content { background-color: #0b0e15; margin: auto; padding: 24px; border: 1px solid var(--border); width: 100%; max-width: 400px; border-radius: 12px; position:relative; }
.dark-mode .modal-content { background-color: #1f1f1f; }
.close-modal { position: absolute; top: 16px; right: 16px; cursor: pointer; font-size: 20px; line-height:1; color:var(--muted); }
</style>

<div class="section">
    <div class="container">
        
        <?php $__flash = flash_get(); if ($__flash): ?>
            <div class="alert alert-<?= $__flash['type'] ?>" style="margin-bottom:24px;"><?= e($__flash['message']) ?></div>
        <?php endif; ?>

        <?php if ($hosting['status'] === 'terminated'): ?>
            <div class="alert alert-error">Gói hosting này đã bị xóa vĩnh viễn và không thể quản lý được nữa.</div>
            <?php require_once __DIR__ . '/includes/footer.php'; exit; ?>
        <?php endif; ?>

        <div class="layout-hosting">
            
            <!-- SIDEBAR -->
            <div class="sidebar-panel">
                <div class="title">⚙️ Thao tác nhanh</div>
                <div style="font-size:12px; color:var(--muted); margin-bottom:16px;">Quản trị hosting qua API hệ thống.</div>

                <form method="post" id="formToggleRenew">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_auto_renew">
                    <label class="action-btn" style="cursor:pointer;">
                        <div>
                            Tự động gia hạn
                            <div style="font-size:11px; color:var(--muted); font-weight:normal; margin-top:4px;">Tự gia hạn khi đến kỳ nếu ví đủ dư.</div>
                        </div>
                        <div class="switch">
                            <input type="checkbox" onchange="document.getElementById('formToggleRenew').submit();" <?= $hosting['auto_renew'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </div>
                    </label>
                </form>

                <a href="?id=<?= $id ?>&sso=cpanel" target="_blank" class="action-btn">🚪 Đăng nhập cPanel</a>
                <a href="<?= BASE_URL ?>/hosting_renew?id=<?= $id ?>" class="action-btn">💳 Gia hạn Hosting</a>
                <button class="action-btn" onclick="openModal('modalPassword')">🔑 Đổi mật khẩu</button>
                <button class="action-btn" onclick="openModal('modalDomain')">🌐 Đổi miền chính</button>
                
                <hr style="border-color:var(--border); margin:16px 0;">
                
                <button class="action-btn danger" onclick="openModal('modalDelete')">🗑 Xóa Hosting</button>
            </div>

            <!-- MAIN CONTENT -->
            <div>
                <!-- Tổng quan -->
                <div class="box-panel">
                    <div class="title">🏢 Tổng quan</div>
                    <div class="hint">Thông tin gói, thời hạn và cấu hình đang sử dụng.</div>
                    
                    <div class="grid-3">
                        <div class="info-item">
                            <div class="info-label">Gói dịch vụ</div>
                            <div class="info-value"><?= e($hosting['plan_name']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Dung lượng</div>
                            <div class="info-value"><?= e($hosting['disk_quota_display']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Băng thông</div>
                            <div class="info-value"><?= e($hosting['bandwidth_display']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Trạng thái</div>
                            <div class="info-value">
                                <span class="tag tag-<?= $hosting['status']==='active'?'active':'sold_hidden' ?>"><?= e($hosting['status']) ?></span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Hết hạn vào</div>
                            <div class="info-value"><?= date('d/m/Y', strtotime($hosting['expires_at'])) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Giá gia hạn / tháng</div>
                            <div class="info-value mono"><?= number_format($hosting['price_month'],0,',','.') ?>đ</div>
                        </div>
                    </div>
                </div>

                <!-- Thông tin truy cập -->
                <div class="box-panel">
                    <div class="title">🛡 Thông tin truy cập</div>
                    <div class="hint">Dùng để đăng nhập FTP, SSH hoặc cPanel trực tiếp.</div>

                    <div class="grid-2">
                        <div class="copy-group">
                            <div class="info">
                                <div class="info-label">Tên miền</div>
                                <div class="info-value mono"><a href="http://<?= e($hosting['domain']) ?>" target="_blank" style="color:inherit;"><?= e($hosting['domain']) ?></a></div>
                            </div>
                        </div>
                        <div class="copy-group">
                            <div class="info">
                                <div class="info-label">Tài khoản</div>
                                <div class="info-value mono"><?= e($hosting['cpanel_username']) ?></div>
                            </div>
                        </div>
                        <div class="copy-group" style="grid-column: 1 / -1;">
                            <div class="info">
                                <div class="info-label">Mật khẩu</div>
                                <div class="info-value mono" id="pwField" data-pw="<?= e(decrypt_secret($hosting['cpanel_password'])) ?>">••••••••</div>
                            </div>
                            <div class="actions">
                                <button class="btn btn-sm btn-outline" onclick="togglePw()">👁 Hiện</button>
                                <button class="btn btn-sm btn-outline" onclick="copyPw()">📋 Copy</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tài nguyên sử dụng -->
                <div class="box-panel">
                    <div class="title" style="justify-content:space-between;">
                        <span style="display:flex;align-items:center;gap:8px;">📈 Tài nguyên sử dụng</span>
                        <a href="?id=<?= $id ?>" class="btn btn-sm btn-outline" title="Làm mới">🔄</a>
                    </div>
                    <div class="hint">Tự động đồng bộ từ cPanel. Thông số Disk/Bandwidth được cập nhật theo chu kỳ của WHM.</div>
                    
                    <?php if (isset($usage_error)): ?>
                        <div class="alert alert-error">Lỗi khi lấy thông số: <?= e($usage_error) ?></div>
                    <?php elseif (!empty($usage)): ?>
                        <?php 
                            // API accountsummary trả về diskused / disklimit (tính bằng MB)
                            $diskUsed = str_replace('M', '', $usage['diskused'] ?? '0');
                            $diskLimit = str_replace('M', '', $usage['disklimit'] ?? '0');
                            $diskUsed = (float)$diskUsed;
                            $diskLimit = $diskLimit === 'unlimited' ? -1 : (float)$diskLimit;
                            $diskPct = $diskLimit > 0 ? min(100, round(($diskUsed / $diskLimit) * 100, 1)) : 0;
                            
                            $bwUsed = str_replace('M', '', $usage['plan'] ?? '0'); // accountsummary doesn't easily show real time bw in simple format without full UAPI, but we try disk first
                            // Thực tế WHM accountsummary thường chỉ trả disk.
                        ?>
                        <div class="grid-2">
                            <div class="info-item">
                                <div class="info-label">💾 Dung lượng Disk</div>
                                <div class="progress-wrap">
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?= $diskPct ?>%;"></div>
                                    </div>
                                    <div class="progress-text"><?= $diskPct ?>% (<?= $diskUsed ?> MB / <?= $diskLimit > 0 ? $diskLimit . ' MB' : '∞' ?>)</div>
                                </div>
                            </div>
                            <!-- Bandwidth có thể không có sẵn trong accountsummary trả về ngắn, thay bằng Inodes nếu không có CloudLinux -->
                            <div class="info-item">
                                <div class="info-label">🌐 Bandwidth / Khác</div>
                                <div class="progress-text" style="margin-top:10px;">(Xem chi tiết trong cPanel)</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Liên kết cPanel -->
                <div class="box-panel">
                    <div class="title">🔗 Liên kết cPanel</div>
                    <div class="hint">Đăng nhập nhanh các chức năng thường dùng trong cPanel mà không cần mật khẩu.</div>
                    
                    <div class="sso-grid">
                        <a href="?id=<?= $id ?>&sso=cpanel" target="_blank" class="sso-btn">⚙️ Trang chủ cPanel</a>
                        <a href="?id=<?= $id ?>&sso=filemanager" target="_blank" class="sso-btn">📁 File Manager</a>
                        <a href="?id=<?= $id ?>&sso=email" target="_blank" class="sso-btn">✉️ Email Accounts</a>
                        <a href="?id=<?= $id ?>&sso=db" target="_blank" class="sso-btn">🗄️ MySQL Databases</a>
                        <a href="?id=<?= $id ?>&sso=phpmyadmin" target="_blank" class="sso-btn">🐘 phpMyAdmin</a>
                        <a href="?id=<?= $id ?>&sso=domain" target="_blank" class="sso-btn">🌐 Domains</a>
                        <a href="?id=<?= $id ?>&sso=cron" target="_blank" class="sso-btn">⏱ Cron Jobs</a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Modal Đổi mật khẩu -->
<div id="modalPassword" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalPassword')">&times;</span>
        <h3>🔑 Đổi mật khẩu cPanel</h3>
        <p class="hint" style="margin-bottom:16px;">Mật khẩu mới sẽ được đồng bộ ngay lập tức tới máy chủ.</p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label>Mật khẩu mới</label>
                <input type="text" name="new_password" minlength="8" required placeholder="Mật khẩu mạnh (chữ, số, ký tự đb)">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Lưu mật khẩu mới</button>
        </form>
    </div>
</div>

<!-- Modal Đổi tên miền -->
<div id="modalDomain" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalDomain')">&times;</span>
        <h3>🌐 Đổi miền chính</h3>
        <p class="hint" style="margin-bottom:16px;">Thay đổi tên miền chính của gói hosting. Thao tác này sẽ tự động đổi thư mục document root trên cPanel.</p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="change_domain">
            <div class="form-group">
                <label>Tên miền mới</label>
                <input type="text" name="new_domain" required placeholder="VD: domain-moi.com">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Đổi tên miền</button>
        </form>
    </div>
</div>

<!-- Modal Xóa Hosting -->
<div id="modalDelete" class="modal">
    <div class="modal-content">
        <span class="close-modal" onclick="closeModal('modalDelete')">&times;</span>
        <h3 style="color:var(--danger);">🗑 Xóa Hosting (Nguy hiểm)</h3>
        <p class="hint" style="margin-bottom:16px; color:var(--danger);">
            Hành động này sẽ XÓA VĨNH VIỄN toàn bộ dữ liệu, mã nguồn và database trên cPanel. KHÔNG THỂ KHÔI PHỤC!
        </p>
        <form method="post">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="delete_hosting">
            <div class="form-group">
                <label>Gõ chữ <b style="color:red">XOA</b> để xác nhận</label>
                <input type="text" name="confirm_delete" required autocomplete="off">
            </div>
            <button class="btn btn-danger btn-block" type="submit">Xác nhận XÓA VĨNH VIỄN</button>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('show'); }
function closeModal(id) { document.getElementById(id).classList.remove('show'); }
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

function togglePw() {
    const el = document.getElementById('pwField');
    if (el.textContent === '••••••••') {
        el.textContent = el.dataset.pw;
    } else {
        el.textContent = '••••••••';
    }
}
function copyPw() {
    const pw = document.getElementById('pwField').dataset.pw;
    navigator.clipboard.writeText(pw).then(() => alert('Đã copy mật khẩu!'));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
