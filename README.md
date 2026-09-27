# CodeMarket — Chợ mua bán Code & cho thuê Website

Website được xây dựng hoàn toàn bằng **PHP thuần + MySQL (PDO) + HTML/CSS/JS thuần**, không dùng framework.

## ✨ Tính năng
- Đăng ký / đăng nhập tài khoản.
- Đăng bán **Source Code**: ảnh demo, mô tả, giá, file code (zip/rar) giao sau khi mua.
- Đăng **Website cho thuê**: quản lý danh sách domain riêng của mình → chọn domain → nhập tài khoản/mật khẩu quản trị web → số tháng sử dụng → ảnh demo → mô tả.
- Trang **Profile** công khai: hiển thị danh mục Code và danh mục Web của từng user.
- Mua Code / Thuê Web qua **ví nội bộ (wallet)**: hệ thống tự động chia **80% cho người bán — 20% cho admin** ngay khi giao dịch thành công.
- Sau khi mua Code → mở khoá link tải file. Sau khi thuê Web → hiển thị tài khoản/mật khẩu quản trị.
- Dashboard user: quản lý sản phẩm đã đăng, đơn đã mua, đơn đã bán, số dư ví, lịch sử giao dịch, chỉnh sửa hồ sơ/avatar.
- Trang **Admin**: tổng doanh thu 20%, danh sách toàn bộ đơn hàng, quản lý user (khoá/mở khoá), quản lý & gỡ sản phẩm vi phạm.

## 🛠 Yêu cầu môi trường
- PHP 8.0+ (bật extension `pdo_mysql`, `fileinfo`)
- MySQL/MariaDB 5.7+
- Apache/Nginx (khuyến nghị dùng XAMPP hoặc Laragon để chạy nhanh trên máy local)

## 🚀 Cài đặt

1. Giải nén thư mục `codemarket` vào thư mục web root, ví dụ với XAMPP: `htdocs/codemarket`.
2. Tạo database và import cấu trúc:
   - Mở phpMyAdmin (hoặc CLI MySQL) → chạy toàn bộ nội dung file `database.sql`.
   - File này sẽ tự tạo database `codemarket` và 1 tài khoản admin mẫu.
3. Mở `config/config.php`, chỉnh lại thông tin kết nối DB nếu cần:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'codemarket');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   define('BASE_URL', '/codemarket'); // để '' nếu chạy ở domain gốc, vd https://example.com
   ```
4. Đảm bảo các thư mục sau có quyền ghi (777 hoặc 755 tuỳ hệ điều hành) để upload ảnh/file hoạt động:
   ```
   assets/uploads/code/
   assets/uploads/code/files/
   assets/uploads/web/
   assets/uploads/avatar/
   ```
5. Truy cập `http://localhost/codemarket/index.php`.

## 🔑 Tài khoản admin mặc định
```
Username: admin
Password: admin123
```
Đăng nhập rồi vào menu **Admin** (góc phải trên) để xem doanh thu, quản lý user/sản phẩm.

## 💰 Cơ chế ví & chia hoa hồng
- Mỗi user có `wallet_balance` lưu trong bảng `users`.
- Trang **Ví của tôi** (`wallet.php`) cho phép "nạp tiền demo" (không tích hợp cổng thanh toán thật — bạn có thể tích hợp VNPay/Momo/Stripe sau này bằng cách thay thế hàm nạp tiền).
- Khi mua Code / thuê Web (`process_purchase()` trong `includes/functions.php`):
  1. Trừ tiền người mua.
  2. Cộng **80%** cho người bán.
  3. Cộng **20%** cho tài khoản admin đầu tiên trong hệ thống.
  4. Ghi lại đơn hàng vào bảng `orders` và lịch sử vào `wallet_transactions`.
- Tỉ lệ hoa hồng có thể chỉnh ở `config/config.php`:
  ```php
  define('ADMIN_FEE_PERCENT', 20);
  define('SELLER_PERCENT', 80);
  ```

## 📂 Cấu trúc thư mục
```
codemarket/
├── admin/              Trang quản trị (dashboard, orders, users, listings)
├── assets/
│   ├── css/style.css   Toàn bộ giao diện
│   ├── js/main.js
│   └── uploads/        Ảnh demo, file code, avatar do user upload
├── config/config.php   Cấu hình DB & hằng số hệ thống
├── includes/           header, footer, functions dùng chung
├── database.sql        Cấu trúc + dữ liệu khởi tạo
├── index.php            Trang chủ / marketplace
├── register.php login.php logout.php
├── code_create.php code_view.php     Đăng bán & xem chi tiết Code
├── web_create.php web_view.php       Đăng cho thuê & xem chi tiết Web
├── profile.php          Hồ sơ công khai (danh mục Code/Web)
├── dashboard.php         Bảng điều khiển user
└── wallet.php             Ví & lịch sử giao dịch
```

## ⚠️ Lưu ý bảo mật khi triển khai thật (production)
- Đổi mật khẩu admin mặc định ngay sau khi cài đặt.
- Mật khẩu quản trị web hiện đang mã hoá bằng `base64` (chỉ để demo, **không an toàn**) — nên thay bằng mã hoá 2 chiều mạnh hơn (ví dụ `openssl_encrypt` với khoá bí mật lưu ngoài code) trước khi dùng thật.
- Bật HTTPS, giới hạn upload, và thêm xác thực email khi đăng ký nếu triển khai công khai.
- Cân nhắc tích hợp cổng thanh toán thật (VNPay, Momo, Stripe...) thay cho chức năng "nạp tiền demo".
