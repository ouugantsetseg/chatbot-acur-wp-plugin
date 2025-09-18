-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 13, 2025 at 12:40 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wordpress_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `chat_logs`
--

CREATE TABLE `chat_logs` (
  `id` bigint(20) NOT NULL,
  `session_id` varchar(64) NOT NULL,
  `user_query` text NOT NULL,
  `matched_faq_id` int(11) DEFAULT NULL,
  `match_score` decimal(5,4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `chat_logs`
--

INSERT INTO `chat_logs` (`id`, `session_id`, `user_query`, `matched_faq_id`, `match_score`, `created_at`) VALUES
(1, '', 'reset password', 1, 0.1393, '2025-09-13 05:19:30'),
(2, 'local-123', 'reset password', 1, 0.1393, '2025-09-13 05:20:19'),
(3, 'local-123', 'reset password', 1, 0.1393, '2025-09-13 05:29:41'),
(4, 'local-123', 'reset password', 1, 0.1393, '2025-09-13 05:38:07'),
(5, 'local-123', 'reset password', 1, 0.4687, '2025-09-13 05:40:50'),
(6, 'local-123', 'reset password', 1, 0.4687, '2025-09-13 05:43:27'),
(7, 'wp-test', 'reset password', 1, 0.4687, '2025-09-13 06:13:24'),
(8, 'wp-test', 'reset password', 1, 0.4687, '2025-09-13 06:17:16'),
(9, 'wp-test', 'reset password', 1, 0.4687, '2025-09-13 06:17:33'),
(10, 'local-123', 'reset password', 1, 0.4687, '2025-09-13 06:31:39'),
(11, 'local-123', 'reset password', 1, 0.4687, '2025-09-13 06:48:57'),
(12, 'wp-q5hysar3ngq', 'd', 1, 0.1074, '2025-09-13 09:08:33'),
(13, 'wp-q5hysar3ngq', 'scholarship', 1, 0.0853, '2025-09-13 09:08:55'),
(14, 'wp-q5hysar3ngq', 'password', 1, 0.3196, '2025-09-13 09:09:40'),
(15, 'wp-q5hysar3ngq', 'okok', 1, 0.0898, '2025-09-13 09:09:48'),
(16, 'wp-q5hysar3ngq', 'Do you offer student discounts?', 2, 1.0000, '2025-09-13 09:09:52'),
(17, 'wp-q5hysar3ngq', 'reset', 1, 0.3317, '2025-09-13 09:10:02'),
(18, 'wp-q5hysar3ngq', 'schunu', 2, 0.0841, '2025-09-13 09:10:23'),
(19, 'wp-q5hysar3ngq', 'discount', 2, 0.1080, '2025-09-13 09:10:40'),
(20, 'wp-test', 'reset password', 1, 0.4687, '2025-09-13 09:11:05'),
(21, 'wp-q5hysar3ngq', 'account', 2, 0.0967, '2025-09-13 09:11:53'),
(22, 'wp-q5hysar3ngq', 'account', 2, 0.0967, '2025-09-13 09:13:59'),
(23, 'wp-q5hysar3ngq', 'discount', 2, 0.1080, '2025-09-13 09:14:05'),
(24, 'wp-q5hysar3ngq', 'Do you offer student discounts?', 2, 1.0000, '2025-09-13 09:14:42'),
(25, 'wp-q5hysar3ngq', 'student discount', 2, 0.3981, '2025-09-13 09:14:52'),
(26, 'wp-q5hysar3ngq', 'get student discount', 2, 0.3989, '2025-09-13 09:15:05'),
(27, 'wp-q5hysar3ngq', 'offer student discount', 2, 0.5981, '2025-09-13 09:15:16'),
(28, 'wp-q5hysar3ngq', 'd', 1, 0.1074, '2025-09-13 09:15:28'),
(29, 'local-123', 'offer student discount', 2, 0.5645, '2025-09-13 09:30:39'),
(30, 'wp-q5hysar3ngq', 'student discount', 2, 0.4623, '2025-09-13 09:32:12'),
(31, 'wp-q5hysar3ngq', 'student', 2, 0.3495, '2025-09-13 09:32:19'),
(32, 'wp-q5hysar3ngq', 'hehe', 1, 0.0898, '2025-09-13 09:32:47'),
(33, 'wp-q5hysar3ngq', 'snu', 2, 0.0761, '2025-09-13 09:32:51'),
(34, 'wp-q5hysar3ngq', 'student', 2, 0.3495, '2025-09-13 09:32:56'),
(35, 'wp-q5hysar3ngq', 'ok', 1, 0.0824, '2025-09-13 09:33:03'),
(36, 'wp-q5hysar3ngq', 'reset', 1, 0.3453, '2025-09-13 09:33:07'),
(37, 'wp-q5hysar3ngq', 'scholarship', 1, 0.0853, '2025-09-13 09:46:16'),
(38, 'wp-q5hysar3ngq', 'reset', 1, 0.3453, '2025-09-13 09:46:21'),
(39, 'wp-q5hysar3ngq', 'passwrd', 1, 0.1124, '2025-09-13 09:46:26'),
(40, 'wp-q5hysar3ngq', 'pass', 1, 0.0866, '2025-09-13 09:46:34'),
(41, 'wp-q5hysar3ngq', 'password', 1, 0.3333, '2025-09-13 09:46:40');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `chat_logs`
--
ALTER TABLE `chat_logs`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `chat_logs`
--
ALTER TABLE `chat_logs`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
