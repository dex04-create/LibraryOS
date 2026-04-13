-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 13, 2026 at 06:20 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `library_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `books`
--

CREATE TABLE `books` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `author` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `genre` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `books`
--

INSERT INTO `books` (`id`, `title`, `author`, `quantity`, `created_at`, `genre`) VALUES
(1, 'PHP & MySQL', 'Jon Duckett', 3, '2026-04-01 01:04:33', NULL),
(2, 'JavaScript', 'Kyle Simpson', 0, '2026-04-01 01:04:33', NULL),
(3, 'Clean Code', 'Robert Martin', 0, '2026-04-01 01:04:33', NULL),
(4, 'Mocking Bird', 'Eminem', 7, '2026-04-01 01:32:31', NULL),
(5, 'Harry Potter', 'Ako', 0, '2026-04-01 01:33:02', NULL),
(6, 'The Hunger Games', 'Suzume Collins', 19, '2026-04-13 03:19:33', 'Fiction');

-- --------------------------------------------------------

--
-- Table structure for table `borrows`
--

CREATE TABLE `borrows` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `book_id` int(11) NOT NULL,
  `borrowed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `due_date` date NOT NULL,
  `returned_at` datetime DEFAULT NULL,
  `status` enum('active','returned','overdue') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `borrows`
--

INSERT INTO `borrows` (`id`, `user_id`, `book_id`, `borrowed_at`, `due_date`, `returned_at`, `status`) VALUES
(1, 8, 1, '2026-04-13 11:17:56', '2026-04-20', NULL, 'active'),
(2, 5, 6, '2026-04-13 11:19:53', '2026-04-20', NULL, 'active'),
(3, 5, 2, '2026-04-13 11:20:03', '2026-04-20', NULL, 'active'),
(4, 5, 4, '2026-04-13 11:20:11', '2026-04-20', NULL, 'active'),
(5, 9, 5, '2026-04-13 11:32:42', '2026-04-20', NULL, 'active'),
(6, 9, 2, '2026-04-13 11:32:51', '2026-04-20', NULL, 'active'),
(7, 2, 1, '2026-04-13 12:02:46', '2026-04-20', NULL, 'active');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
(1, 'admin', '0192023a7bbd73250516f069df18b500', 'admin', '2026-04-01 01:04:33'),
(2, 'user', '6ad14ba9986e3615423dfca256d04e3f', 'user', '2026-04-01 01:04:33'),
(5, 'dex', '20a59f0ede50a9e73e1d7a7bec396877', 'user', '2026-04-01 01:29:55'),
(6, 'dave', '70b9f55c5b2ab6ab9e5a3fed086f1ce7', 'user', '2026-04-01 01:36:30'),
(8, 'cris', '758d0fa2b9fb5d3fc3e6a9e5a72b07e0', 'user', '2026-04-13 03:16:21'),
(9, 'ralp', '059aa6a6a90e69fbcc583cba3a9cd88f', 'user', '2026-04-13 03:31:28');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `books`
--
ALTER TABLE `books`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `borrows`
--
ALTER TABLE `borrows`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_borrow_user` (`user_id`),
  ADD KEY `fk_borrow_book` (`book_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `books`
--
ALTER TABLE `books`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `borrows`
--
ALTER TABLE `borrows`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `borrows`
--
ALTER TABLE `borrows`
  ADD CONSTRAINT `fk_borrow_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`id`),
  ADD CONSTRAINT `fk_borrow_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
