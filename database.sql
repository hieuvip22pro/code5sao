-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Máy chủ: localhost:3306
-- Thời gian đã tạo: Th7 19, 2026 lúc 04:24 PM
-- Phiên bản máy phục vụ: 10.11.18-MariaDB-log
-- Phiên bản PHP: 8.4.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Cơ sở dữ liệu: `taowebn2_apimm`
--

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `code_listings`
--

CREATE TABLE `code_listings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `demo_image` varchar(255) DEFAULT NULL,
  `price` decimal(15,2) NOT NULL,
  `code_file` varchar(255) DEFAULT NULL,
  `status` enum('active','sold_hidden','disabled') NOT NULL DEFAULT 'active',
  `views` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `code_listings`
--

INSERT INTO `code_listings` (`id`, `user_id`, `title`, `description`, `category`, `demo_image`, `price`, `code_file`, `status`, `views`, `created_at`) VALUES
(1, 2, 'ewewfffffff', 'erggergeregrgeregrefwswswswswswswef', 'wfeeeeee', 'code_6a5b0b4f0ba64_1784351567.jpg', 10000.00, 'https://imgdes.vipshoptech.com/code_create.php', 'active', 2, '2026-07-18 12:12:47'),
(2, 2, 'wfeeeeeeeeeeeeee', '12323232323232323232323232323232323232323232323232323232323', 'GAME FREE FIRE', 'code_6a5b0b7d4ed35_1784351613.jpg', 10000.00, 'https://imgdes.vipshoptech.com/code_create.php', 'active', 3, '2026-07-18 12:13:33'),
(3, 2, 'wwfefwewfe', 'wfeeeeeeeeeeeeeeeeeee', 'GAME FREE FIRE', 'code_4b63e85cc228_1784353085_0.jpg', 10000.00, 'https://imgdes.vipshoptech.com/code_create.php', 'active', 3, '2026-07-18 12:38:05'),
(4, 2, '34tttttttttttttttt', '3t444444444444444444444444444444444444444444444444', '34ttttttttttt', 'code_545d41d8aa8b_1784353327_0.png', 10000.00, 'https://imgdes.vipshoptech.com/code_create.php', 'sold_hidden', 10, '2026-07-18 12:42:07');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `listing_domain_options`
--

CREATE TABLE `listing_domain_options` (
  `id` int(11) NOT NULL,
  `listing_id` int(11) NOT NULL,
  `extension` varchar(20) NOT NULL,
  `extra_price` decimal(15,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `listing_domain_options`
--

INSERT INTO `listing_domain_options` (`id`, `listing_id`, `extension`, `extra_price`) VALUES
(1, 1, '.com', 100000.00),
(2, 2, '.com', 1000000.00);

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `listing_images`
--

CREATE TABLE `listing_images` (
  `id` int(11) NOT NULL,
  `item_type` enum('code','web') NOT NULL,
  `item_id` int(11) NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `listing_images`
--

INSERT INTO `listing_images` (`id`, `item_type`, `item_id`, `image_path`, `sort_order`, `created_at`) VALUES
(1, 'web', 2, 'web_b64a8c562d51_1784353008_0.jpg', 0, '2026-07-18 12:36:48'),
(2, 'code', 3, 'code_4b63e85cc228_1784353085_0.jpg', 0, '2026-07-18 12:38:05'),
(3, 'code', 4, 'code_545d41d8aa8b_1784353327_0.png', 0, '2026-07-18 12:42:07'),
(4, 'code', 4, 'code_35a95261d357_1784353327_1.png', 1, '2026-07-18 12:42:07'),
(5, 'code', 4, 'code_08ed197d3152_1784353327_2.jpg', 2, '2026-07-18 12:42:07'),
(6, 'code', 4, 'code_c8575adc7877_1784353327_3.jpg', 3, '2026-07-18 12:42:07'),
(7, 'code', 4, 'code_b130b8a5f45d_1784353327_4.jpg', 4, '2026-07-18 12:42:07');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `seller_id` int(11) NOT NULL,
  `item_type` enum('code','web') NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_title` varchar(200) NOT NULL,
  `domain_name` varchar(255) DEFAULT NULL,
  `domain_extension` varchar(20) DEFAULT NULL,
  `account_username` varchar(120) DEFAULT NULL,
  `account_password` varchar(255) DEFAULT NULL,
  `months` int(11) NOT NULL DEFAULT 1,
  `discount_code` varchar(50) DEFAULT NULL,
  `price` decimal(15,2) NOT NULL,
  `admin_fee` decimal(15,2) NOT NULL,
  `seller_amount` decimal(15,2) NOT NULL,
  `status` enum('completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `orders`
--

INSERT INTO `orders` (`id`, `buyer_id`, `seller_id`, `item_type`, `item_id`, `item_title`, `domain_name`, `domain_extension`, `account_username`, `account_password`, `months`, `discount_code`, `price`, `admin_fee`, `seller_amount`, `status`, `created_at`) VALUES
(1, 3, 2, 'code', 4, '34tttttttttttttttt', NULL, NULL, NULL, NULL, 1, NULL, 10000.00, 2000.00, 8000.00, 'completed', '2026-07-18 12:43:32'),
(2, 3, 2, 'web', 2, 'rerggggggggggggggggggg', 'wefwefwef.com', '.com', 'wefwfefwe', 'd2Zld2Vmd2Vm', 1, NULL, 1010000.00, 202000.00, 808000.00, 'completed', '2026-07-18 12:44:09');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(120) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `wallet_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `role` enum('user','admin') NOT NULL DEFAULT 'user',
  `status` enum('active','banned') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `full_name`, `avatar`, `bio`, `wallet_balance`, `role`, `status`, `created_at`) VALUES
(1, 'admin', 'admin@codemarket.local', '$2b$10$yd61gGnLkEiIS8BX5fLQi.NrAc2MHEcGVoJXtzkIqHVA3wLZLFhbK', 'Qu?n tr? viên', NULL, NULL, 204000.00, 'admin', 'active', '2026-07-18 12:06:13'),
(2, 'wefwefwef', 'wefwef@gmail.com', '$2y$10$iZAUEDbcbjD6eaBn5XW0U.4EbfarSnCqnIczb2hHWiM0ZfR.2UnPq', NULL, NULL, NULL, 10816000.00, 'user', 'active', '2026-07-18 12:09:47'),
(3, '234423423423', '132132123@g.rr', '$2y$10$Od4HA.Ws1soze.WBUOFkrOh3Jh9TiByNeVKyjBmvTyxRDHfVS1EiG', NULL, NULL, NULL, 8980000.00, 'user', 'active', '2026-07-18 12:30:34');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `user_domains`
--

CREATE TABLE `user_domains` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `domain_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('credit','debit','topup','withdraw') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `wallet_transactions`
--

INSERT INTO `wallet_transactions` (`id`, `user_id`, `type`, `amount`, `description`, `order_id`, `created_at`) VALUES
(1, 2, 'topup', 10000000.00, 'N?p ti?n vào ví (demo)', NULL, '2026-07-18 12:43:11'),
(2, 3, 'topup', 10000000.00, 'N?p ti?n vào ví (demo)', NULL, '2026-07-18 12:43:24'),
(3, 3, 'debit', 10000.00, 'Thanh toán: 34tttttttttttttttt', 1, '2026-07-18 12:43:32'),
(4, 2, 'credit', 8000.00, 'Nh?n ti?n bán (80%): 34tttttttttttttttt', 1, '2026-07-18 12:43:32'),
(5, 1, 'credit', 2000.00, 'Hoa h?ng 20%: 34tttttttttttttttt', 1, '2026-07-18 12:43:32'),
(6, 3, 'debit', 1010000.00, 'Thanh toán: rerggggggggggggggggggg', 2, '2026-07-18 12:44:09'),
(7, 2, 'credit', 808000.00, 'Nh?n ti?n bán (80%): rerggggggggggggggggggg', 2, '2026-07-18 12:44:09'),
(8, 1, 'credit', 202000.00, 'Hoa h?ng 20%: rerggggggggggggggggggg', 2, '2026-07-18 12:44:09');

-- --------------------------------------------------------

--
-- Cấu trúc bảng cho bảng `web_listings`
--

CREATE TABLE `web_listings` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `domain_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `demo_image` varchar(255) DEFAULT NULL,
  `price` decimal(15,2) NOT NULL,
  `months` int(11) NOT NULL DEFAULT 1,
  `account_username` varchar(120) DEFAULT NULL,
  `account_password` varchar(255) DEFAULT NULL,
  `status` enum('active','rented','disabled') NOT NULL DEFAULT 'active',
  `views` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Đang đổ dữ liệu cho bảng `web_listings`
--

INSERT INTO `web_listings` (`id`, `user_id`, `domain_id`, `title`, `description`, `demo_image`, `price`, `months`, `account_username`, `account_password`, `status`, `views`, `created_at`) VALUES
(1, 2, NULL, 'wefffffffffffff', 'weeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', 'web_6a5b0ee94eb7c_1784352489.jpg', 10000.00, 1, NULL, NULL, 'active', 6, '2026-07-18 12:28:09'),
(2, 2, NULL, 'rerggggggggggggggggggg', 'rgergggggggggggggggggggggggggggggggggggggggggggggggggg', 'web_b64a8c562d51_1784353008_0.jpg', 10000.00, 1, NULL, NULL, 'rented', 9, '2026-07-18 12:36:48');

--
-- Chỉ mục cho các bảng đã đổ
--

--
-- Chỉ mục cho bảng `code_listings`
--
ALTER TABLE `code_listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `listing_domain_options`
--
ALTER TABLE `listing_domain_options`
  ADD PRIMARY KEY (`id`),
  ADD KEY `listing_id` (`listing_id`);

--
-- Chỉ mục cho bảng `listing_images`
--
ALTER TABLE `listing_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item` (`item_type`,`item_id`);

--
-- Chỉ mục cho bảng `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `buyer_id` (`buyer_id`),
  ADD KEY `seller_id` (`seller_id`);

--
-- Chỉ mục cho bảng `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Chỉ mục cho bảng `user_domains`
--
ALTER TABLE `user_domains`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Chỉ mục cho bảng `web_listings`
--
ALTER TABLE `web_listings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `domain_id` (`domain_id`);

--
-- AUTO_INCREMENT cho các bảng đã đổ
--

--
-- AUTO_INCREMENT cho bảng `code_listings`
--
ALTER TABLE `code_listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT cho bảng `listing_domain_options`
--
ALTER TABLE `listing_domain_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `listing_images`
--
ALTER TABLE `listing_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT cho bảng `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT cho bảng `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT cho bảng `user_domains`
--
ALTER TABLE `user_domains`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT cho bảng `web_listings`
--
ALTER TABLE `web_listings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Ràng buộc đối với các bảng kết xuất
--

--
-- Ràng buộc cho bảng `code_listings`
--
ALTER TABLE `code_listings`
  ADD CONSTRAINT `code_listings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ràng buộc cho bảng `listing_domain_options`
--
ALTER TABLE `listing_domain_options`
  ADD CONSTRAINT `listing_domain_options_ibfk_1` FOREIGN KEY (`listing_id`) REFERENCES `web_listings` (`id`) ON DELETE CASCADE;

--
-- Ràng buộc cho bảng `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`buyer_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`seller_id`) REFERENCES `users` (`id`);

--
-- Ràng buộc cho bảng `user_domains`
--
ALTER TABLE `user_domains`
  ADD CONSTRAINT `user_domains_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ràng buộc cho bảng `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `wallet_transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ràng buộc cho bảng `web_listings`
--
ALTER TABLE `web_listings`
  ADD CONSTRAINT `web_listings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `web_listings_ibfk_2` FOREIGN KEY (`domain_id`) REFERENCES `user_domains` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
