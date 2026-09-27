<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

// Chặn đăng bán nếu chưa xác thực email (mua code thì không cần)
$__seller = get_user($pdo, current_user_id());
if (!is_email_verified($__seller)) {
    flash_set('error', 'Bạn cần xác thực email trước khi đăng bán code. Hãy kiểm tra hộp thư hoặc gửi lại email xác thực.');
    redirect('/verify_email');
}

// Upload nhiều ảnh cùng lúc (dùng chung cho code & web).
function upload_multi_images(array $filesInput, string $destPath, string $prefix): array {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxSize = 5 * 1024 * 1024;
    $maxCount = 8;
    $saved = [];
    $count = 0;

    foreach ($filesInput['name'] as $i => $name) {
        if ($name === '' || $filesInput['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        if ($filesInput['error'][$i] !== UPLOAD_ERR_OK) {
            throw new Exception('Lỗi khi tải ảnh: ' . $name);
        }
        if ($filesInput['size'][$i] > $maxSize) {
            throw new Exception('Ảnh "' . $name . '" vượt quá 5MB.');
        }
        $mime = mime_content_type($filesInput['tmp_name'][$i]);
        if (!isset($allowed[$mime])) {
            throw new Exception('Ảnh "' . $name . '" không đúng định dạng JPG/PNG/WEBP.');
        }
        $count++;
        if ($count > $maxCount) {
            throw new Exception('Chỉ được tải tối đa ' . $maxCount . ' ảnh.');
        }
        $ext = $allowed[$mime];
        $filename = $prefix . '_' . bin2hex(random_bytes(6)) . '_' . time() . '_' . $i . '.' . $ext;
        $target = rtrim($destPath, '/') . '/' . $filename;
        if (!move_uploaded_file($filesInput['tmp_name'][$i], $target)) {
            throw new Exception('Không thể lưu ảnh: ' . $name);
        }
        if (function_exists('compress_image_file')) { @compress_image_file($target); }
        $saved[] = $filename;
    }
    return $saved;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $title = trim($_POST['title'] ?? '');
    $catArr = $_POST['category'] ?? [];
    if (!is_array($catArr)) { $catArr = [$catArr]; }
    $catArr = array_filter(array_map('trim', $catArr), 'strlen');
    $category = implode(', ', $catArr);
    $description = sanitize_html($_POST['description'] ?? '');
    $keywords_raw = trim($_POST['keywords'] ?? '');
    $kwArr = array_filter(array_map('trim', explode(',', $keywords_raw)), 'strlen');
    $kwArr = array_map(function($k){ return implode(' ', array_slice(preg_split('/\s+/', $k), 0, 3)); }, $kwArr);
    $kwArr = array_slice(array_values(array_unique($kwArr)), 0, 15);
    $keywords = implode(', ', $kwArr);
    $price = (float)($_POST['price'] ?? 0);
    $code_file_link = trim($_POST['code_file_link'] ?? '');

    $demo_admin_url = trim($_POST['demo_admin_url'] ?? '');
    $demo_admin_account = trim($_POST['demo_admin_account'] ?? '');
    $demo_client_url = trim($_POST['demo_client_url'] ?? '');
    $demo_client_account = trim($_POST['demo_client_account'] ?? '');
    $warranty_extended_price = trim($_POST['warranty_extended_price'] ?? '');
    $reseller_price = trim($_POST['reseller_price'] ?? '');
    $seller_contact = trim($_POST['seller_contact'] ?? '');
    $install_support_price = trim($_POST['install_support_price'] ?? '');

    if ($title === '' || mb_strlen($title) < 20) $errors[] = 'Tên sản phẩm (tiêu đề) phải có ít nhất 20 ký tự để chuẩn SEO.';
    if ($description === '' || mb_strlen(trim(strip_tags($description))) < 70) $errors[] = 'Mô tả chi tiết phải có ít nhất 70 ký tự để chuẩn SEO.';
    if ($price < 0) $errors[] = 'Giá bán không được nhỏ hơn 0.';
    if ($code_file_link !== '' && !filter_var($code_file_link, FILTER_VALIDATE_URL)) $errors[] = 'Link tải file source code không hợp lệ.';
    if ($demo_admin_url !== '' && !filter_var($demo_admin_url, FILTER_VALIDATE_URL)) $errors[] = 'Link Demo Admin không hợp lệ.';
    if ($demo_client_url !== '' && !filter_var($demo_client_url, FILTER_VALIDATE_URL)) $errors[] = 'Link Demo Client không hợp lệ.';
    if ($warranty_extended_price !== '' && (float)$warranty_extended_price <= 0) $errors[] = 'Giá bảo hành mở rộng phải lớn hơn 0.';
    if ($reseller_price !== '' && (float)$reseller_price <= $price) $errors[] = 'Giá bản Full quyền phải lớn hơn giá tiêu chuẩn.';
    if ($install_support_price !== '' && (float)$install_support_price < 0) $errors[] = 'Giá hỗ trợ cài đặt không hợp lệ.';
    if (empty($_FILES['demo_images']) || empty(array_filter($_FILES['demo_images']['name']))) {
        $errors[] = 'Vui lòng chọn ít nhất 1 ảnh demo.';
    }

    if (!$errors) {
        try {
            $uploadedImages = upload_multi_images($_FILES['demo_images'], UPLOAD_PATH_CODE, 'code');
            if (empty($uploadedImages)) {
                $errors[] = 'Tải ảnh lên thất bại, vui lòng thử lại.';
            } else {
                $demo_image = $uploadedImages[0];
                $stmt = $pdo->prepare('INSERT INTO code_listings
                    (user_id, title, description, category, keywords, demo_image, price, code_file,
                     demo_admin_url, demo_admin_account, demo_client_url, demo_client_account,
                     warranty_extended_price, reseller_price, seller_contact, install_support_price, status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([
                    current_user_id(), $title, $description, $category ?: null, $keywords ?: null, $demo_image, $price, $code_file_link ?: null,
                    $demo_admin_url ?: null, $demo_admin_account ?: null, $demo_client_url ?: null, $demo_client_account ?: null,
                    $warranty_extended_price !== '' ? (float)$warranty_extended_price : null,
                    $reseller_price !== '' ? (float)$reseller_price : null,
                    $seller_contact ?: null,
                    $install_support_price !== '' ? (float)$install_support_price : null,
                    'pending',
                ]);
                $newId = $pdo->lastInsertId();

                $imgStmt = $pdo->prepare('INSERT INTO listing_images (item_type, item_id, image_path, sort_order) VALUES (?,?,?,?)');
                foreach ($uploadedImages as $i => $fname) {
                    $imgStmt->execute(['code', $newId, $fname, $i]);
                }

                flash_set('success', 'Đã gửi sản phẩm! Sản phẩm đang chờ admin duyệt và sẽ hiển thị công khai sau khi được phê duyệt.');
                redirect('/dashboard?tab=code');
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$page_title = 'Đăng bán Code';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="form-card wide">
            <h2>📦 Đăng bán Source Code</h2>
            <p class="hint" style="margin-bottom:24px;">Đính kèm nhiều ảnh demo giao diện và mô tả rõ chức năng để tăng khả năng bán được hàng. Không nên để link tải chế độ công khai</p>

            <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>

            <form method="post" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Tên sản phẩm (tiêu đề)</label>
                    <input type="text" name="title" minlength="20" placeholder="VD: Mã nguồn Web bán hàng PHP + MySQL đầy đủ giỏ hàng" value="<?= e($_POST['title'] ?? '') ?>" required>
                    <p class="hint">Nên dài ≥ 20 ký tự và chứa từ khóa người mua hay tìm (ngôn ngữ, chức năng) để chuẩn SEO.</p>
                </div>

                <style>
                .cm-seo-box{border:1px dashed var(--border,#61646c);border-radius:8px;padding:14px 16px;margin-bottom:18px;}
                .cm-kw-row{display:flex;gap:8px;flex-wrap:wrap;}
                .cm-kw-row input{flex:1;min-width:220px;}
                .cm-kw-list{margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;}
                .cm-kw-chip{background:transparent;border:1px solid var(--border,#61646c);color:inherit;border-radius:999px;padding:6px 12px;font-size:13px;cursor:pointer;transition:.15s;}
                .cm-kw-chip:hover{border-color:var(--accent,#2563eb);color:var(--accent,#2563eb);}
                .cm-count{margin-top:6px;font-size:12px;}
                .cm-kwtags{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid var(--border,#61646c);border-radius:8px;padding:8px;min-height:44px;background:#fff;cursor:text;}
                .cm-kwtags input{border:none;outline:none;flex:1;min-width:160px;background:transparent;font-size:14px;color:#111;}
                .cm-kw-tag{display:inline-flex;align-items:center;gap:6px;background:#e7f4d6;border:1px solid #9bcf5f;color:#3f6212;border-radius:6px;padding:3px 8px;font-size:13px;line-height:1.4;}
                .cm-kw-tag button{border:none;background:transparent;color:#3f6212;cursor:pointer;font-size:15px;line-height:1;padding:0;}
                .cm-quill{background:#fff;color:#111;border-radius:0 0 8px 8px;}
                .cm-quill .ql-editor{min-height:180px;font-size:14px;}
                </style>
                <div class="form-group cm-seo-box">
                    <label>🔎 Gợi ý TOP từ khóa Google (giúp code lên top tìm kiếm)</label>
                    <div class="cm-kw-row">
                        <input type="text" id="cmKwSeed" placeholder="Nhập chủ đề, VD: web bán hàng, quản lý kho, đặt phòng...">
                        <button type="button" class="btn btn-outline btn-sm" id="cmKwGen">Gợi ý</button>
                    </div>
                    <p class="hint">Bấm vào từ khóa gợi ý để chèn nhanh vào tiêu đề. Tiêu đề khớp từ khóa người dùng hay tìm sẽ dễ lên top Google.</p>
                    <div id="cmKwList" class="cm-kw-list"></div>
                </div>
                <div class="form-group cm-kwtags-group">
                    <label>Từ khóa <span style="color:#dc2626">*</span> <span class="hint" style="font-weight:400;">(mỗi từ khóa tối đa 3 từ, giúp tìm kiếm &amp; SEO)</span></label>
                    <div id="kwTags" class="cm-kwtags"><input type="text" id="kwInput" placeholder="Nhập từ khóa rồi nhấn Enter..."></div>
                    <input type="hidden" name="keywords" id="kwHidden" value="<?= e($_POST['keywords'] ?? '') ?>">
                    <div style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline btn-sm" id="kwAuto">✨ Tự động gợi ý từ khóa</button>
                        <span class="hint">Tự tạo từ khóa dựa trên tiêu đề/chủ đề (mỗi từ khóa ≤ 3 từ).</span>
                    </div>
                </div>
                <div class="form-group">
                    <label>Thể loại code</label>
                    <?= render_category_chips($_POST['category'] ?? '') ?>
                </div>
                <div class="form-group">
                    <label>Giá bán tiêu chuẩn (VNĐ)</label>
                    <input type="number" name="price" min="0" step="any" placeholder="VD: 500000" value="<?= e($_POST['price'] ?? '') ?>" required>
                </div>
                <div class="form-group">
    <label>Mô tả chi tiết</label>
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <div id="descEditor" class="cm-quill"></div>
    <textarea name="description" id="descInput" style="display:none;"><?= e($_POST['description'] ?? '') ?></textarea>
    <p class="hint">Dùng thanh công cụ để in đậm, tạo tiêu đề, danh sách, chèn liên kết... (nội dung tối thiểu 70 ký tự).</p>
</div>
               <style>
.cm-img-grid{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;}
.cm-img-thumb{position:relative;width:96px;height:96px;border:1px solid var(--border,#61646c);border-radius:8px;overflow:hidden;}
.cm-img-thumb img{width:100%;height:100%;object-fit:cover;display:block;}
.cm-img-badge{position:absolute;left:0;bottom:0;right:0;background:rgba(37,99,235,.9);color:#fff;font-size:10px;text-align:center;padding:2px 0;}
.cm-img-remove{position:absolute;top:2px;right:2px;width:20px;height:20px;border:none;border-radius:50%;background:rgba(0,0,0,.6);color:#fff;font-size:14px;line-height:20px;cursor:pointer;padding:0;}
.cm-img-remove:hover{background:#dc2626;}
.cm-img-count{font-size:12px;margin-top:4px;}
.cm-img-thumb{cursor:grab;}
.cm-img-thumb.cm-dragging{opacity:.4;}
.cm-img-thumb.cm-drag-over{outline:2px dashed var(--accent,#2563eb);outline-offset:2px;}
</style>
<div id="cmImgGrid" class="cm-img-grid"></div>
<button type="button" id="cmImgAddBtn" class="btn btn-outline btn-sm">+ Chọn ảnh</button>
<div id="cmImgCount" class="cm-img-count hint"></div>
<input type="file" id="cmImgInput" name="demo_images[]" accept="image/*" multiple style="display:none;">
<p class="hint">JPG/PNG/WEBP, tối đa 5MB/ảnh, tối đa 8 ảnh. Bạn có thể bấm "Chọn ảnh" nhiều lần — ảnh đã chọn trước đó sẽ không bị mất. Ảnh có nhãn "Đại diện" sẽ hiển thị ở trang chủ (kéo-thả để đổi thứ tự nếu cần).</p>

<script>
(function(){
  var input = document.getElementById('cmImgInput');
  var addBtn = document.getElementById('cmImgAddBtn');
  var grid = document.getElementById('cmImgGrid');
  var countEl = document.getElementById('cmImgCount');
  var MAX = 8, MAXSIZE = 5 * 1024 * 1024;
  var files = [];
  var dragIndex = null;

  addBtn.addEventListener('click', function(){ input.click(); });

  input.addEventListener('change', function(){
    var newFiles = Array.prototype.slice.call(input.files);
    var rejected = [];
    newFiles.forEach(function(f){
      if (files.length >= MAX) { rejected.push(f.name + ' (đã đủ ' + MAX + ' ảnh)'); return; }
      if (f.size > MAXSIZE) { rejected.push(f.name + ' (vượt 5MB)'); return; }
      files.push(f);
    });
    if (rejected.length) alert('Bỏ qua các ảnh sau:\n' + rejected.join('\n'));
    input.value = '';
    sync();
  });

  function sync(){
    var dt = new DataTransfer();
    files.forEach(function(f){ dt.items.add(f); });
    input.files = dt.files;
    render();
  }

  function render(){
    grid.innerHTML = '';
    files.forEach(function(f, i){
      var url = URL.createObjectURL(f);
      var box = document.createElement('div');
      box.className = 'cm-img-thumb';
      box.draggable = true;
      box.dataset.index = i;

      var img = document.createElement('img');
      img.src = url;
      box.appendChild(img);

      if (i === 0) {
        var badge = document.createElement('span');
        badge.className = 'cm-img-badge';
        badge.textContent = 'Đại diện';
        box.appendChild(badge);
      }

      var rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'cm-img-remove';
      rm.textContent = '×';
      rm.title = 'Xoá ảnh này';
      rm.addEventListener('click', function(){
        files.splice(i, 1);
        sync();
      });
      box.appendChild(rm);

      box.addEventListener('dragstart', function(e){
        dragIndex = i;
        box.classList.add('cm-dragging');
        e.dataTransfer.effectAllowed = 'move';
      });
      box.addEventListener('dragend', function(){
        box.classList.remove('cm-dragging');
        dragIndex = null;
      });
      box.addEventListener('dragover', function(e){
        e.preventDefault();
        box.classList.add('cm-drag-over');
      });
      box.addEventListener('dragleave', function(){
        box.classList.remove('cm-drag-over');
      });
      box.addEventListener('drop', function(e){
        e.preventDefault();
        box.classList.remove('cm-drag-over');
        var to = i;
        if (dragIndex === null || dragIndex === to) return;
        var moved = files.splice(dragIndex, 1)[0];
        files.splice(to, 0, moved);
        sync();
      });

      grid.appendChild(box);
    });
    countEl.textContent = files.length + '/' + MAX + ' ảnh đã chọn';
  }

  var form = input.closest('form');
  if (form) {
    form.addEventListener('submit', function(e){
      if (files.length === 0) {
        e.preventDefault();
        alert('Vui lòng chọn ít nhất 1 ảnh demo.');
        input.scrollIntoView({behavior:'smooth', block:'center'});
      }
    });
  }
})();
</script>
                <div class="form-group">
                    <label>Link tải file source code</label>
                    <input type="text" name="code_file_link" placeholder="VD: https://drive.google.com/file/d/xxxx/view" value="<?= e($_POST['code_file_link'] ?? '') ?>">
                    <p class="hint">Dán link Google Drive / Mega / Dropbox. Link này chỉ có 1 mình bạn thấy (không nên để công khai link).</p>
                </div>
                <div class="form-group">
                    <label>Thông tin liên hệ hỗ trợ (Zalo / Messenger / SĐT)</label>
                    <input type="text" name="seller_contact" placeholder="VD: Zalo 0912345678 | m.me/tenban | SDT 0912345678" value="<?= e($_POST['seller_contact'] ?? '') ?>">
                    <p class="hint">🔒 Chỉ hiển thị cho người mua <b>sau khi thanh toán thành công</b> để hỗ trợ cài đặt &amp; bảo hành. Giúp tránh việc khách nhắn mua ngoài web.</p>
                </div>
                <div class="form-group">
                    <label>🛠️ Hỗ trợ cài đặt (tuỳ chọn)</label>
                    <input type="number" name="install_support_price" min="0" step="any" placeholder="Bỏ trống nếu không hỗ trợ" value="<?= e($_POST['install_support_price'] ?? '') ?>">
                    <p class="hint">Nếu bạn nhận cài đặt lên Hosting/VPS cho khách: nhập <b>0 = miễn phí</b>, hoặc nhập giá tuỳ ý (VNĐ). <b>Bỏ trống</b> nếu không cung cấp — người mua sẽ không thấy ô tích này. Phí cài đặt được cộng 100% cho bạn.</p>
                </div>

                <hr style="border-color:var(--border); margin:28px 0;">
                <h3 style="font-size:16px;">🔗 Live Demo (không bắt buộc)</h3>
                <p class="hint" style="margin-bottom:14px;">Cung cấp link demo để khách xem thử trước khi mua.</p>
                <div class="row-2">
                    <div class="form-group">
                        <label>Link Demo Client</label>
                        <input type="text" name="demo_client_url" placeholder="https://demo.example.com" value="<?= e($_POST['demo_client_url'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Tài khoản Demo Client (nếu cần)</label>
                        <input type="text" name="demo_client_account" placeholder="VD: user/1234" value="<?= e($_POST['demo_client_account'] ?? '') ?>">
                    </div>
                </div>
                

                
                <div class="form-group" style="margin-top: 24px;">
                    <div style="background-color: #e3e3e3; border: 1px solid #000000; padding: 15px; border-radius: 4px; font-size: 13.5px; line-height: 1.6; color: #000000; margin-bottom: 12px;">
                        - Thành viên cam kết mọi thông tin đăng tải trên hệ thống là hoàn toàn chính xác và trung thực.<br>
                        - Chất lượng mã nguồn: Source code khi tải lên phải đảm bảo hoạt động ổn định; phần mô tả và hình ảnh minh họa phải khớp 100% với thực tế sản phẩm.<br>
                        - Tệp nén cần được kiểm duyệt kỹ lưỡng, tuyệt đối không chứa mã độc, virus, file lỗi hoặc chèn các liên kết ngoài không an toàn.<br>
                        - File chứa code có đầy đủ file chạy, thông tin chi tiết về source, hướng dẫn cài đặt và tài khoản đăng nhập chi tiết.<br>
                        - Tác giả có nghĩa vụ hỗ trợ và khắc phục lỗi khi người mua liên hệ (qua email/SĐT), vì phí tải về đã bao gồm chi phí bảo hành.<br>
                        - Tất cả source code bị báo cáo vi phạm bản quyền nếu được ban quản trị xác nhận là đúng, source code sẽ bị xóa bỏ.<br>
                        - Source code đã upload lên diễn đàn là thành viên upload đã đồng ý cho phép các thành viên download và sử dụng.
                    </div>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 500; color: #dc2626;">
                        <input type="checkbox" name="agree_terms" value="1" required style="width: 16px; height: 16px; cursor: pointer;">
                        <a href="/terms" target="_blank" class="aorange" title="Xem thêm điều khoản sử dụng">Tôi đã đọc và đồng ý với các điều khoản trên</a>
                    </label>
                </div>
                <button class="btn btn-primary btn-block" type="submit" style="margin-top:12px;">Đăng bán</button>
            </form>
        </div>
    </div>
</div>
<script>
(function(){
  var seed = document.getElementById('cmKwSeed');
  var gen = document.getElementById('cmKwGen');
  var list = document.getElementById('cmKwList');
  var titleInput = document.querySelector('input[name="title"]');
  var descInput = document.querySelector('textarea[name="description"]');
  var tpl = ['Source code {s}','{s} full source code','Code {s} PHP','Chia sẻ code {s}','Mã nguồn {s}','{s} PHP MySQL','Download code {s}','{s} có demo','Code {s} miễn phí','Template {s}','{s} responsive','Phần mềm quản lý {s}'];
  function render(){
    if(!list) return;
    var s = ((seed && seed.value) || '').trim();
    list.innerHTML = '';
    if(!s){ list.innerHTML = '<span class="hint">Nhập chủ đề rồi bấm \"Gợi ý\".</span>'; return; }
    tpl.forEach(function(t){
      var kw = t.replace('{s}', s);
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'cm-kw-chip'; b.textContent = kw;
      b.addEventListener('click', function(){
        if(titleInput){ titleInput.value = kw; titleInput.focus(); titleInput.dispatchEvent(new Event('input')); }
      });
      list.appendChild(b);
    });
    var g = document.createElement('a');
    g.href = 'https://www.google.com/search?q=' + encodeURIComponent(s);
    g.target = '_blank'; g.rel = 'noopener'; g.className = 'hint';
    g.style.display = 'inline-block'; g.style.marginTop = '8px';
    g.textContent = '↗ Xem gợi ý thực tế trên Google cho "' + s + '"';
    list.appendChild(document.createElement('br'));
    list.appendChild(g);
  }
  if(gen) gen.addEventListener('click', render);
  if(seed) seed.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); render(); } });
  function counter(el, min){
    if(!el) return;
    var c = document.createElement('div'); c.className = 'cm-count';
    el.parentNode.appendChild(c);
    function upd(){
      var len = (el.value || '').length;
      c.textContent = len + ' ký tự' + (len < min ? ' (tối thiểu ' + min + ')' : ' ✓');
      c.style.color = len < min ? '#dc2626' : '#16a34a';
    }
    el.addEventListener('input', upd); upd();
  }
  counter(titleInput, 20);
})();
</script>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
(function(){
  if(typeof Quill==='undefined') return;
  var descInput=document.getElementById('descInput');
  var q=new Quill('#descEditor',{theme:'snow',placeholder:'Mô tả chức năng, công nghệ, hướng dẫn cài đặt...',modules:{toolbar:[['bold','italic','underline','strike'],[{list:'ordered'},{list:'bullet'}],[{header:[2,3,false]}],['blockquote','code-block'],['link'],['clean']]}});
  if(descInput && descInput.value.trim()!==''){ q.clipboard.dangerouslyPasteHTML(descInput.value); }
  function sync(){ if(!descInput) return; var html=q.root.innerHTML; if(q.getText().trim()===''){ html=''; } descInput.value=html; }
  q.on('text-change', sync); sync();
  var f=descInput?descInput.closest('form'):null; if(f) f.addEventListener('submit', sync);
  var wrap=document.getElementById('kwTags'), input=document.getElementById('kwInput'), hidden=document.getElementById('kwHidden');
  if(wrap && input && hidden){
    var tags=[];
    function syncKw(){ hidden.value=tags.join(', '); }
    function render(){ Array.prototype.slice.call(wrap.querySelectorAll('.cm-kw-tag')).forEach(function(n){n.remove();}); tags.forEach(function(t,i){ var s=document.createElement('span'); s.className='cm-kw-tag'; s.appendChild(document.createTextNode(t)); var b=document.createElement('button'); b.type='button'; b.textContent='\u00d7'; b.addEventListener('click',function(){ tags.splice(i,1); render(); }); s.appendChild(b); wrap.insertBefore(s,input); }); syncKw(); }
    function norm(v){ v=(v||'').replace(/\s+/g,' ').trim(); if(!v) return ''; return v.split(' ').slice(0,3).join(' '); }
    function add(v){ v=norm(v); if(!v) return; if(tags.length>=15){ return; } var low=v.toLowerCase(); for(var i=0;i<tags.length;i++){ if(tags[i].toLowerCase()===low) return; } tags.push(v); render(); }
    (hidden.value||'').split(',').forEach(function(v){ if(v.trim()) add(v); });
    input.addEventListener('keydown',function(e){ if(e.key==='Enter'||e.key===','){ e.preventDefault(); add(input.value); input.value=''; } else if(e.key==='Backspace' && input.value===''){ tags.pop(); render(); } });
    input.addEventListener('blur',function(){ if(input.value.trim()){ add(input.value); input.value=''; } });
    wrap.addEventListener('click',function(){ input.focus(); });
    var auto=document.getElementById('kwAuto');
    if(auto) auto.addEventListener('click',function(){
      var title=(document.querySelector('input[name="title"]')||{}).value||'';
      var seed=(document.getElementById('cmKwSeed')||{}).value||'';
      var base=(seed||title).trim();
      if(!base){ alert('Nhập tiêu đề hoặc chủ đề trước để gợi ý từ khóa.'); return; }
      var core=base.toLowerCase().replace(/[^0-9a-z\u00c0-\u1ef9\s]/gi,' ').replace(/\s+/g,' ').trim().split(' ').slice(0,3).join(' ');
      ['source code '+core,'code '+core,core+' php','mã nguồn '+core,'download '+core,core+' miễn phí','template '+core, core].forEach(function(s){ add(s); });
    });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>