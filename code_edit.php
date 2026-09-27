<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM code_listings WHERE id = ? AND user_id = ?');
$stmt->execute([$id, current_user_id()]);
$item = $stmt->fetch();
if (!$item) { http_response_code(404); die('Không tìm thấy sản phẩm hoặc bạn không có quyền sửa.'); }

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

    if ($title === '' || mb_strlen($title) < 5) $errors[] = 'Tên sản phẩm phải có ít nhất 5 ký tự.';
    if ($description === '' || mb_strlen(trim(strip_tags($description))) < 20) $errors[] = 'Mô tả phải có ít nhất 20 ký tự.';
if ($price < 0) $errors[] = 'Giá bán không được nhỏ hơn 0.';
if ($code_file_link !== '' && !filter_var($code_file_link, FILTER_VALIDATE_URL)) $errors[] = 'Link tải file source code không hợp lệ.';
    if ($demo_admin_url !== '' && !filter_var($demo_admin_url, FILTER_VALIDATE_URL)) $errors[] = 'Link Demo Admin không hợp lệ.';
    if ($demo_client_url !== '' && !filter_var($demo_client_url, FILTER_VALIDATE_URL)) $errors[] = 'Link Demo Client không hợp lệ.';
    if ($reseller_price !== '' && (float)$reseller_price <= $price) $errors[] = 'Giá bản Full quyền phải lớn hơn giá tiêu chuẩn.';

    if (!$errors) {
        $reStatus = ($item['status'] === 'sold_hidden') ? 'sold_hidden' : 'pending';
        $pdo->prepare('UPDATE code_listings SET title=?, category=?, keywords=?, description=?, price=?, code_file=?,
            demo_admin_url=?, demo_admin_account=?, demo_client_url=?, demo_client_account=?,
            warranty_extended_price=?, reseller_price=?, seller_contact=?, install_support_price=?, status=? WHERE id=?')
            ->execute([
                $title, $category ?: null, $keywords ?: null, $description, $price, $code_file_link ?: null,
                $demo_admin_url ?: null, $demo_admin_account ?: null, $demo_client_url ?: null, $demo_client_account ?: null,
                $warranty_extended_price !== '' ? (float)$warranty_extended_price : null,
                $reseller_price !== '' ? (float)$reseller_price : null,
                $seller_contact ?: null,
                $install_support_price !== '' ? (float)$install_support_price : null,
                $reStatus,
                $id,
            ]);
        if ($reStatus === 'pending') {
            flash_set('success', 'Đã cập nhật. Sản phẩm sẽ được admin duyệt lại trước khi hiển thị công khai.');
        } else {
            flash_set('success', 'Đã cập nhật sản phẩm.');
        }
        redirect('/dashboard?tab=code');
    }
    // Giữ lại giá trị vừa nhập nếu có lỗi
    $item = array_merge($item, compact('title','category','keywords','description','price','code_file_link',
        'demo_admin_url','demo_admin_account','demo_client_url','demo_client_account',
        'warranty_extended_price','reseller_price','seller_contact','install_support_price'));
    $item['code_file'] = $code_file_link;
}

$page_title = 'Sửa sản phẩm';
require_once __DIR__ . '/includes/header.php';
?>
<div class="section">
    <div class="container">
        <div class="form-card wide">
            <h2>✏️ Sửa sản phẩm: <?= e($item['title']) ?></h2>
            <p class="hint" style="margin-bottom:24px;">Muốn đổi ảnh demo, vui lòng đăng sản phẩm mới (chưa hỗ trợ thay ảnh ở bản này).</p>

            <?php foreach ($errors as $err): ?><div class="alert alert-error"><?= e($err) ?></div><?php endforeach; ?>
            <?php if ($item['status'] === 'sold_hidden'): ?>
                <div class="alert alert-info">Sản phẩm đã bán — bạn vẫn có thể sửa mô tả/demo nhưng không ảnh hưởng đơn đã hoàn tất.</div>
            <?php endif; ?>

            <form method="post">
                <?= csrf_input() ?>
                <div class="form-group">
                    <label>Tên sản phẩm</label>
                    <input type="text" name="title" value="<?= e($item['title']) ?>" required>
                </div>
                <style>
                .cm-kwtags{display:flex;flex-wrap:wrap;gap:6px;align-items:center;border:1px solid var(--border,#61646c);border-radius:8px;padding:8px;min-height:44px;background:#fff;cursor:text;}
                .cm-kwtags input{border:none;outline:none;flex:1;min-width:160px;background:transparent;font-size:14px;color:#111;}
                .cm-kw-tag{display:inline-flex;align-items:center;gap:6px;background:#e7f4d6;border:1px solid #9bcf5f;color:#3f6212;border-radius:6px;padding:3px 8px;font-size:13px;line-height:1.4;}
                .cm-kw-tag button{border:none;background:transparent;color:#3f6212;cursor:pointer;font-size:15px;line-height:1;padding:0;}
                .cm-quill{background:#fff;color:#111;border-radius:0 0 8px 8px;}
                .cm-quill .ql-editor{min-height:180px;font-size:14px;}
                </style>
                <div class="form-group">
                    <label>Thể loại code</label>
                    <?= render_category_chips($item['category'] ?? '') ?>
                </div>
                <div class="form-group cm-kwtags-group">
                    <label>Từ khóa <span class="hint" style="font-weight:400;">(mỗi từ khóa tối đa 3 từ)</span></label>
                    <div id="kwTags" class="cm-kwtags"><input type="text" id="kwInput" placeholder="Nhập từ khóa rồi nhấn Enter..."></div>
                    <input type="hidden" name="keywords" id="kwHidden" value="<?= e($item['keywords'] ?? '') ?>">
                    <div style="margin-top:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline btn-sm" id="kwAuto">✨ Tự động gợi ý từ khóa</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Giá bán tiêu chuẩn (VNĐ)</label>
                    <input type="number" name="price" min="0" step="any" value="<?= e($item['price']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Mô tả chi tiết</label>
                    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
                    <div id="descEditor" class="cm-quill"></div>
                    <textarea name="description" id="descInput" style="display:none;"><?= e($item['description']) ?></textarea>
                </div>
                <div class="form-group">
                    <label>Link tải file source code</label>
                    <input type="text" name="code_file_link" value="<?= e($item['code_file'] ?? '') ?>">
                </div>

                <hr style="border-color:var(--border); margin:28px 0;">
                <h3 style="font-size:16px;">🔗 Live Demo</h3>
                <div class="row-2">
                    <div class="form-group">
                        <label>Link Demo Client</label>
                        <input type="text" name="demo_client_url" value="<?= e($item['demo_client_url'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Tài khoản Demo Client</label>
                        <input type="text" name="demo_client_account" value="<?= e($item['demo_client_account'] ?? '') ?>">
                    </div>
                </div>
                

                <div class="form-group">
                    <label>Thông tin liên hệ hỗ trợ (Zalo / Messenger / SĐT)</label>
                    <input type="text" name="seller_contact" placeholder="VD: Zalo 0912345678 | m.me/tenban | SDT 0912345678" value="<?= e($item['seller_contact'] ?? '') ?>">
                    <p class="hint">🔒 Chỉ hiển thị cho người mua sau khi thanh toán thành công.</p>
                </div>
                <div class="form-group">
                    <label>🛠️ Hỗ trợ cài đặt (tuỳ chọn)</label>
                    <input type="number" name="install_support_price" min="0" step="any" placeholder="Bỏ trống nếu không hỗ trợ" value="<?= e($item['install_support_price'] ?? '') ?>">
                    <p class="hint"><b>0 = miễn phí</b>, hoặc giá tuỳ ý (VNĐ). Bỏ trống nếu không cung cấp. Phí cộng 100% cho bạn.</p>
                </div>

                <hr style="border-color:var(--border); margin:28px 0;">
                <h3 style="font-size:16px;">💰 Gói mở rộng (tuỳ chọn)</h3>
                <div class="row-2">
                    
                    <div class="form-group">
                        <label>Giá bản Full quyền phân phối lại</label>
                        <input type="number" name="reseller_price" min="1000" step="any" value="<?= e($item['reseller_price'] ?? '') ?>">
                    </div>
                </div>

                <div style="display:flex; gap:10px; margin-top:12px;">
                    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                    <a href="<?= BASE_URL ?>/code_view?id=<?= $id ?>" class="btn btn-outline">Huỷ</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
<script>
(function(){
  if(typeof Quill==='undefined') return;
  var descInput=document.getElementById('descInput');
  if(descInput){
    var q=new Quill('#descEditor',{theme:'snow',modules:{toolbar:[['bold','italic','underline','strike'],[{list:'ordered'},{list:'bullet'}],[{header:[2,3,false]}],['blockquote','code-block'],['link'],['clean']]}});
    if(descInput.value.trim()!==''){ q.clipboard.dangerouslyPasteHTML(descInput.value); }
    function sync(){ var html=q.root.innerHTML; if(q.getText().trim()===''){ html=''; } descInput.value=html; }
    q.on('text-change', sync); sync();
    var f=descInput.closest('form'); if(f) f.addEventListener('submit', sync);
  }
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
      var base=title.trim();
      if(!base){ alert('Nhập tiêu đề trước để gợi ý từ khóa.'); return; }
      var core=base.toLowerCase().replace(/[^0-9a-z\u00c0-\u1ef9\s]/gi,' ').replace(/\s+/g,' ').trim().split(' ').slice(0,3).join(' ');
      ['source code '+core,'code '+core,core+' php','mã nguồn '+core,'download '+core,core+' miễn phí','template '+core, core].forEach(function(s){ add(s); });
    });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
