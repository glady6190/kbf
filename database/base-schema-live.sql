-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Nov 19, 2026 at 10:18 AM
-- Server version: 11.8.9-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u991932671_KBFUK`
--

-- --------------------------------------------------------

--
-- Table structure for table `Announcements`
--

CREATE TABLE `Announcements` (
  `AnnouncementId` int(11) NOT NULL,
  `Title` varchar(300) NOT NULL,
  `Content` text NOT NULL,
  `FeaturedImage` varchar(500) DEFAULT NULL,
  `TournamentId` int(11) DEFAULT NULL,
  `CreatedByAdminId` int(11) NOT NULL,
  `CreatedDate` datetime DEFAULT current_timestamp(),
  `PublishedDate` datetime DEFAULT NULL,
  `IsPublished` tinyint(1) DEFAULT 0,
  `IsVisible` tinyint(1) DEFAULT 1,
  `IsPinned` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ApprovalLogs`
--

CREATE TABLE `ApprovalLogs` (
  `LogId` int(11) NOT NULL,
  `LogType` enum('Registration','Tournament','Results') NOT NULL,
  `Action` enum('Approved','Rejected') NOT NULL,
  `ReferenceId` int(11) NOT NULL,
  `ReferenceName` varchar(255) NOT NULL,
  `ReferenceDetails` varchar(500) DEFAULT NULL,
  `ApprovedByAdminId` int(11) NOT NULL,
  `ApprovedByAdminName` varchar(255) NOT NULL,
  `LogDate` datetime DEFAULT current_timestamp(),
  `Comments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Categories`
--

CREATE TABLE `Categories` (
  `CategoryId` int(11) NOT NULL,
  `Name` varchar(30) DEFAULT NULL,
  `ShortName` varchar(10) DEFAULT NULL,
  `DisplayOrder` int(11) NOT NULL,
  `PromotionThreshold` int(11) DEFAULT 4,
  `DemotionThreshold` int(11) DEFAULT 3,
  `DemotionMonths` int(11) DEFAULT 6
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Clubs`
--

CREATE TABLE `Clubs` (
  `ClubId` int(11) NOT NULL,
  `Name` varchar(200) NOT NULL,
  `Location` varchar(200) NOT NULL,
  `ContactEmail` varchar(255) DEFAULT NULL,
  `ContactPhone` varchar(20) DEFAULT NULL,
  `IsActive` tinyint(1) DEFAULT 1,
  `CreatedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `EmailBroadcasts`
--

CREATE TABLE `EmailBroadcasts` (
  `BroadcastId` int(11) NOT NULL,
  `Subject` varchar(255) NOT NULL,
  `BodyHtml` mediumtext NOT NULL,
  `TargetType` varchar(20) NOT NULL DEFAULT 'all',
  `TargetCategoryId` int(11) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'queued',
  `TotalRecipients` int(11) NOT NULL DEFAULT 0,
  `SentCount` int(11) NOT NULL DEFAULT 0,
  `FailedCount` int(11) NOT NULL DEFAULT 0,
  `CreatedByAdminId` int(11) DEFAULT NULL,
  `CreatedDate` datetime NOT NULL DEFAULT current_timestamp(),
  `CompletedDate` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `EmailQueue`
--

CREATE TABLE `EmailQueue` (
  `QueueId` int(11) NOT NULL,
  `BroadcastId` int(11) NOT NULL,
  `UserId` int(11) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `RecipientName` varchar(255) DEFAULT NULL,
  `Status` varchar(20) NOT NULL DEFAULT 'pending',
  `Attempts` int(11) NOT NULL DEFAULT 0,
  `LastError` varchar(500) DEFAULT NULL,
  `SentDate` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `GalleryPhotos`
--

CREATE TABLE `GalleryPhotos` (
  `PhotoId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `FilePath` varchar(500) NOT NULL,
  `Caption` varchar(200) DEFAULT NULL,
  `UploadedBy` int(11) NOT NULL COMMENT 'UserId of admin who uploaded',
  `UploadedDate` datetime NOT NULL DEFAULT current_timestamp(),
  `DisplayOrder` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `LoginAttempts`
--

CREATE TABLE `LoginAttempts` (
  `AttemptId` int(11) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Success` tinyint(1) DEFAULT 0,
  `IpAddress` varchar(45) NOT NULL,
  `AttemptTime` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Matches`
--

CREATE TABLE `Matches` (
  `MatchId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `CategoryId` int(11) DEFAULT NULL,
  `Player1Id` int(11) DEFAULT NULL,
  `Partner1Id` int(11) DEFAULT NULL,
  `Player2Id` int(11) DEFAULT NULL,
  `Partner2Id` int(11) DEFAULT NULL,
  `GuestPlayer1Name` varchar(100) DEFAULT NULL,
  `GuestPartner1Name` varchar(100) DEFAULT NULL,
  `GuestPlayer2Name` varchar(100) DEFAULT NULL,
  `GuestPartner2Name` varchar(100) DEFAULT NULL,
  `Team1Score` int(11) DEFAULT NULL,
  `Team2Score` int(11) DEFAULT NULL,
  `WinnerTeam` int(11) DEFAULT NULL,
  `IsApproved` tinyint(1) DEFAULT 0,
  `RoundType` varchar(50) NOT NULL,
  `GroupName` varchar(75) DEFAULT NULL,
  `MatchDate` datetime DEFAULT NULL,
  `Court` varchar(50) DEFAULT NULL,
  `CreatedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Notifications`
--

CREATE TABLE `Notifications` (
  `NotificationId` int(11) NOT NULL,
  `UserId` int(11) NOT NULL,
  `Title` varchar(300) NOT NULL,
  `Message` text NOT NULL,
  `Type` varchar(50) DEFAULT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `PasswordResets`
--

CREATE TABLE `PasswordResets` (
  `ResetId` int(11) NOT NULL,
  `UserId` int(11) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `Token` varchar(64) NOT NULL,
  `ExpiresAt` datetime NOT NULL,
  `Used` tinyint(1) DEFAULT 0,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `IpAddress` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `PlayerPerformanceTracker`
--

CREATE TABLE `PlayerPerformanceTracker` (
  `TrackerId` int(11) NOT NULL,
  `PlayerId` int(11) NOT NULL,
  `CategoryId` int(11) NOT NULL,
  `FinalsReached` int(11) DEFAULT 0,
  `SemiFinalsReached` int(11) DEFAULT 0,
  `QuarterFinalsReached` int(11) DEFAULT 0,
  `GroupStageFailures` int(11) DEFAULT 0,
  `TotalTournamentsPlayed` int(11) DEFAULT 0,
  `LastTournamentDate` date DEFAULT NULL,
  `Last6MonthsPoints` int(11) DEFAULT 0,
  `CareerPoints` int(11) DEFAULT 0,
  `EligibleForPromotion` tinyint(1) DEFAULT 0,
  `FlaggedForDemotion` tinyint(1) DEFAULT 0,
  `LastCalculatedDate` datetime DEFAULT current_timestamp(),
  `FinalsInCurrentCategory` int(11) DEFAULT 0,
  `PointsInCurrentCategory` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `PlayerPointsHistory`
--

CREATE TABLE `PlayerPointsHistory` (
  `PointsHistoryId` int(11) NOT NULL,
  `PlayerId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `MatchId` int(11) NOT NULL,
  `PointsAwarded` int(11) NOT NULL,
  `Reason` varchar(200) DEFAULT NULL,
  `AwardedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `PlayerRankingHistory`
--

CREATE TABLE `PlayerRankingHistory` (
  `RankingHistoryId` int(11) NOT NULL,
  `PlayerId` int(11) NOT NULL,
  `OldCategoryId` int(11) DEFAULT NULL,
  `NewCategoryId` int(11) NOT NULL,
  `ChangeDate` datetime DEFAULT current_timestamp(),
  `Reason` varchar(500) NOT NULL,
  `IsAutomatic` tinyint(1) DEFAULT 1,
  `ChangedByAdminId` int(11) DEFAULT NULL,
  `Notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `PlayerRegistrations`
--

CREATE TABLE `PlayerRegistrations` (
  `RegistrationId` int(11) NOT NULL,
  `PlayerId` varchar(20) NOT NULL,
  `FirstName` varchar(100) NOT NULL,
  `LastName` varchar(100) NOT NULL,
  `Email` varchar(255) NOT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `Location` varchar(100) NOT NULL,
  `PostCode` varchar(20) DEFAULT NULL,
  `YearOfBirth` int(11) DEFAULT NULL,
  `CategoryId` int(11) NOT NULL,
  `ProfilePhoto` varchar(500) DEFAULT NULL,
  `SubmittedIpAddress` varchar(45) DEFAULT NULL,
  `Status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `SubmittedDate` datetime DEFAULT current_timestamp(),
  `ReviewedByAdminId` int(11) DEFAULT NULL,
  `ReviewedDate` datetime DEFAULT NULL,
  `ReviewComments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ResultsApprovalQueue`
--

CREATE TABLE `ResultsApprovalQueue` (
  `ApprovalId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `SubmittedByAdminId` int(11) NOT NULL,
  `SubmittedDate` datetime DEFAULT current_timestamp(),
  `Status` varchar(50) DEFAULT 'Pending',
  `ReviewedByAdminId` int(11) DEFAULT NULL,
  `ReviewedDate` datetime DEFAULT NULL,
  `ReviewComments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `RoundPoints`
--

CREATE TABLE `RoundPoints` (
  `RoundPointsId` int(11) NOT NULL,
  `RoundType` varchar(50) NOT NULL,
  `Outcome` varchar(20) NOT NULL,
  `Points` int(11) NOT NULL,
  `Reason` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Settings`
--

CREATE TABLE `Settings` (
  `SettingKey` varchar(100) NOT NULL,
  `SettingValue` varchar(255) NOT NULL,
  `Description` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `SystemSettings`
--

CREATE TABLE `SystemSettings` (
  `SettingId` int(11) NOT NULL,
  `SettingKey` varchar(100) NOT NULL,
  `SettingValue` text NOT NULL,
  `Description` varchar(500) DEFAULT NULL,
  `LastUpdatedByAdminId` int(11) DEFAULT NULL,
  `LastUpdatedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TournamentCategories`
--

CREATE TABLE `TournamentCategories` (
  `TournamentCategoryId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `CategoryId` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TournamentPhotos`
--

CREATE TABLE `TournamentPhotos` (
  `PhotoId` int(11) NOT NULL,
  `TournamentId` int(11) NOT NULL,
  `FileName` varchar(500) NOT NULL,
  `FilePath` varchar(1000) NOT NULL,
  `Caption` varchar(500) DEFAULT NULL,
  `UploadedByUserId` int(11) NOT NULL,
  `UploadedDate` datetime DEFAULT current_timestamp(),
  `DisplayOrder` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Tournaments`
--

CREATE TABLE `Tournaments` (
  `TournamentId` int(11) NOT NULL,
  `Name` varchar(300) NOT NULL,
  `TournamentDate` date NOT NULL,
  `Venue` varchar(300) NOT NULL,
  `Location` varchar(200) NOT NULL,
  `PostCode` varchar(20) DEFAULT NULL,
  `CategoriesOffered` varchar(200) DEFAULT NULL,
  `CategoriesDescription` text DEFAULT NULL,
  `OrganizingClubId` int(11) NOT NULL,
  `Status` varchar(50) DEFAULT 'Pending',
  `CreatedByAdminId` int(11) NOT NULL,
  `CreatedByMainAdminDirectly` tinyint(1) DEFAULT 0,
  `ApprovedByAdminId` int(11) DEFAULT NULL,
  `ApprovedDate` datetime DEFAULT NULL,
  `CreatedDate` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `UserActivityLogs`
--

CREATE TABLE `UserActivityLogs` (
  `LogId` int(11) NOT NULL,
  `ActionType` varchar(50) NOT NULL,
  `TargetUserId` int(11) NOT NULL,
  `TargetUserName` varchar(200) DEFAULT NULL,
  `TargetUserDetails` text DEFAULT NULL,
  `ChangedByAdminId` int(11) NOT NULL,
  `ChangedByAdminName` varchar(200) DEFAULT NULL,
  `AdminLocation` varchar(100) DEFAULT NULL,
  `LogDate` timestamp NULL DEFAULT current_timestamp(),
  `Comments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Users`
--

CREATE TABLE `Users` (
  `UserId` int(11) NOT NULL,
  `PlayerId` varchar(20) DEFAULT NULL,
  `Email` varchar(255) NOT NULL,
  `PasswordHash` varchar(255) DEFAULT NULL,
  `RequirePasswordChange` tinyint(1) DEFAULT 0,
  `FirstName` varchar(100) NOT NULL,
  `LastName` varchar(100) NOT NULL,
  `Location` varchar(200) NOT NULL,
  `PostCode` varchar(20) NOT NULL,
  `YearOfBirth` int(11) DEFAULT NULL,
  `PhoneNumber` varchar(20) DEFAULT NULL,
  `ProfilePhoto` varchar(500) DEFAULT NULL,
  `ProfilePicture` varchar(500) DEFAULT NULL,
  `Role` varchar(50) NOT NULL DEFAULT 'Player',
  `ClubId` int(11) DEFAULT NULL,
  `CategoryId` int(11) NOT NULL DEFAULT 4,
  `TotalPoints` int(11) DEFAULT 0,
  `IsActive` tinyint(1) DEFAULT 1,
  `LastLoginDate` datetime DEFAULT NULL,
  `CreatedDate` datetime DEFAULT current_timestamp(),
  `EmailOptOut` tinyint(1) NOT NULL DEFAULT 0,
  `UnsubscribeToken` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `Announcements`
--
ALTER TABLE `Announcements`
  ADD PRIMARY KEY (`AnnouncementId`),
  ADD KEY `CreatedByAdminId` (`CreatedByAdminId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `idx_published_visible_date` (`IsPublished`,`IsVisible`,`PublishedDate`);

--
-- Indexes for table `ApprovalLogs`
--
ALTER TABLE `ApprovalLogs`
  ADD PRIMARY KEY (`LogId`),
  ADD KEY `idx_log_type` (`LogType`),
  ADD KEY `idx_log_date` (`LogDate`),
  ADD KEY `idx_admin` (`ApprovedByAdminId`);

--
-- Indexes for table `Categories`
--
ALTER TABLE `Categories`
  ADD PRIMARY KEY (`CategoryId`);

--
-- Indexes for table `Clubs`
--
ALTER TABLE `Clubs`
  ADD PRIMARY KEY (`ClubId`),
  ADD KEY `idx_active` (`IsActive`),
  ADD KEY `idx_isactive` (`IsActive`);

--
-- Indexes for table `EmailBroadcasts`
--
ALTER TABLE `EmailBroadcasts`
  ADD PRIMARY KEY (`BroadcastId`),
  ADD KEY `fk_broadcast_category` (`TargetCategoryId`);

--
-- Indexes for table `EmailQueue`
--
ALTER TABLE `EmailQueue`
  ADD PRIMARY KEY (`QueueId`),
  ADD KEY `idx_queue_status` (`BroadcastId`,`Status`),
  ADD KEY `idx_queue_pending` (`Status`);

--
-- Indexes for table `GalleryPhotos`
--
ALTER TABLE `GalleryPhotos`
  ADD PRIMARY KEY (`PhotoId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `UploadedBy` (`UploadedBy`);

--
-- Indexes for table `LoginAttempts`
--
ALTER TABLE `LoginAttempts`
  ADD PRIMARY KEY (`AttemptId`),
  ADD KEY `idx_email_time` (`Email`,`AttemptTime`),
  ADD KEY `idx_cleanup` (`AttemptTime`);

--
-- Indexes for table `Matches`
--
ALTER TABLE `Matches`
  ADD PRIMARY KEY (`MatchId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `CategoryId` (`CategoryId`),
  ADD KEY `Player1Id` (`Player1Id`),
  ADD KEY `Partner1Id` (`Partner1Id`),
  ADD KEY `Player2Id` (`Player2Id`),
  ADD KEY `Partner2Id` (`Partner2Id`),
  ADD KEY `idx_tournament` (`TournamentId`),
  ADD KEY `idx_player1` (`Player1Id`),
  ADD KEY `idx_player2` (`Player2Id`),
  ADD KEY `idx_category` (`CategoryId`),
  ADD KEY `idx_isapproved` (`IsApproved`);

--
-- Indexes for table `Notifications`
--
ALTER TABLE `Notifications`
  ADD PRIMARY KEY (`NotificationId`),
  ADD KEY `UserId` (`UserId`);

--
-- Indexes for table `PasswordResets`
--
ALTER TABLE `PasswordResets`
  ADD PRIMARY KEY (`ResetId`),
  ADD UNIQUE KEY `Token` (`Token`),
  ADD KEY `UserId` (`UserId`),
  ADD KEY `idx_token` (`Token`),
  ADD KEY `idx_email` (`Email`),
  ADD KEY `idx_expires` (`ExpiresAt`);

--
-- Indexes for table `PlayerPerformanceTracker`
--
ALTER TABLE `PlayerPerformanceTracker`
  ADD PRIMARY KEY (`TrackerId`),
  ADD UNIQUE KEY `PlayerId` (`PlayerId`),
  ADD KEY `CategoryId` (`CategoryId`),
  ADD KEY `idx_player` (`PlayerId`),
  ADD KEY `idx_category` (`CategoryId`),
  ADD KEY `idx_eligible_promotion` (`EligibleForPromotion`),
  ADD KEY `idx_flagged_demotion` (`FlaggedForDemotion`);

--
-- Indexes for table `PlayerPointsHistory`
--
ALTER TABLE `PlayerPointsHistory`
  ADD PRIMARY KEY (`PointsHistoryId`),
  ADD KEY `PlayerId` (`PlayerId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `MatchId` (`MatchId`);

--
-- Indexes for table `PlayerRankingHistory`
--
ALTER TABLE `PlayerRankingHistory`
  ADD PRIMARY KEY (`RankingHistoryId`),
  ADD KEY `PlayerId` (`PlayerId`),
  ADD KEY `OldCategoryId` (`OldCategoryId`),
  ADD KEY `NewCategoryId` (`NewCategoryId`),
  ADD KEY `ChangedByAdminId` (`ChangedByAdminId`);

--
-- Indexes for table `PlayerRegistrations`
--
ALTER TABLE `PlayerRegistrations`
  ADD PRIMARY KEY (`RegistrationId`),
  ADD UNIQUE KEY `PlayerId` (`PlayerId`),
  ADD KEY `CategoryId` (`CategoryId`),
  ADD KEY `ReviewedByAdminId` (`ReviewedByAdminId`),
  ADD KEY `idx_status` (`Status`),
  ADD KEY `idx_submitted_date` (`SubmittedDate`);

--
-- Indexes for table `ResultsApprovalQueue`
--
ALTER TABLE `ResultsApprovalQueue`
  ADD PRIMARY KEY (`ApprovalId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `SubmittedByAdminId` (`SubmittedByAdminId`),
  ADD KEY `ReviewedByAdminId` (`ReviewedByAdminId`),
  ADD KEY `idx_status` (`Status`),
  ADD KEY `idx_tournament` (`TournamentId`),
  ADD KEY `idx_tournament_status` (`TournamentId`,`Status`);

--
-- Indexes for table `RoundPoints`
--
ALTER TABLE `RoundPoints`
  ADD PRIMARY KEY (`RoundPointsId`),
  ADD UNIQUE KEY `uq_round_outcome` (`RoundType`,`Outcome`);

--
-- Indexes for table `Settings`
--
ALTER TABLE `Settings`
  ADD PRIMARY KEY (`SettingKey`);

--
-- Indexes for table `SystemSettings`
--
ALTER TABLE `SystemSettings`
  ADD PRIMARY KEY (`SettingId`),
  ADD UNIQUE KEY `SettingKey` (`SettingKey`),
  ADD KEY `LastUpdatedByAdminId` (`LastUpdatedByAdminId`);

--
-- Indexes for table `TournamentCategories`
--
ALTER TABLE `TournamentCategories`
  ADD PRIMARY KEY (`TournamentCategoryId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `CategoryId` (`CategoryId`);

--
-- Indexes for table `TournamentPhotos`
--
ALTER TABLE `TournamentPhotos`
  ADD PRIMARY KEY (`PhotoId`),
  ADD KEY `TournamentId` (`TournamentId`),
  ADD KEY `UploadedByUserId` (`UploadedByUserId`);

--
-- Indexes for table `Tournaments`
--
ALTER TABLE `Tournaments`
  ADD PRIMARY KEY (`TournamentId`),
  ADD KEY `OrganizingClubId` (`OrganizingClubId`),
  ADD KEY `CreatedByAdminId` (`CreatedByAdminId`),
  ADD KEY `ApprovedByAdminId` (`ApprovedByAdminId`),
  ADD KEY `idx_status` (`Status`),
  ADD KEY `idx_organizing_club` (`OrganizingClubId`),
  ADD KEY `idx_tournament_date` (`TournamentDate`),
  ADD KEY `idx_club_status` (`OrganizingClubId`,`Status`);

--
-- Indexes for table `UserActivityLogs`
--
ALTER TABLE `UserActivityLogs`
  ADD PRIMARY KEY (`LogId`),
  ADD KEY `idx_target_user` (`TargetUserId`),
  ADD KEY `idx_changed_by` (`ChangedByAdminId`),
  ADD KEY `idx_log_date` (`LogDate`);

--
-- Indexes for table `Users`
--
ALTER TABLE `Users`
  ADD PRIMARY KEY (`UserId`),
  ADD UNIQUE KEY `Email` (`Email`),
  ADD UNIQUE KEY `PlayerId` (`PlayerId`),
  ADD KEY `ClubId` (`ClubId`),
  ADD KEY `CategoryId` (`CategoryId`),
  ADD KEY `idx_player_id` (`PlayerId`),
  ADD KEY `idx_registration_search` (`FirstName`,`LastName`,`Email`,`PhoneNumber`),
  ADD KEY `idx_active` (`IsActive`),
  ADD KEY `idx_isactive` (`IsActive`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `Announcements`
--
ALTER TABLE `Announcements`
  MODIFY `AnnouncementId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ApprovalLogs`
--
ALTER TABLE `ApprovalLogs`
  MODIFY `LogId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Categories`
--
ALTER TABLE `Categories`
  MODIFY `CategoryId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Clubs`
--
ALTER TABLE `Clubs`
  MODIFY `ClubId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `EmailBroadcasts`
--
ALTER TABLE `EmailBroadcasts`
  MODIFY `BroadcastId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `EmailQueue`
--
ALTER TABLE `EmailQueue`
  MODIFY `QueueId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `GalleryPhotos`
--
ALTER TABLE `GalleryPhotos`
  MODIFY `PhotoId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `LoginAttempts`
--
ALTER TABLE `LoginAttempts`
  MODIFY `AttemptId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Matches`
--
ALTER TABLE `Matches`
  MODIFY `MatchId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Notifications`
--
ALTER TABLE `Notifications`
  MODIFY `NotificationId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PasswordResets`
--
ALTER TABLE `PasswordResets`
  MODIFY `ResetId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PlayerPerformanceTracker`
--
ALTER TABLE `PlayerPerformanceTracker`
  MODIFY `TrackerId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PlayerPointsHistory`
--
ALTER TABLE `PlayerPointsHistory`
  MODIFY `PointsHistoryId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PlayerRankingHistory`
--
ALTER TABLE `PlayerRankingHistory`
  MODIFY `RankingHistoryId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `PlayerRegistrations`
--
ALTER TABLE `PlayerRegistrations`
  MODIFY `RegistrationId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ResultsApprovalQueue`
--
ALTER TABLE `ResultsApprovalQueue`
  MODIFY `ApprovalId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `RoundPoints`
--
ALTER TABLE `RoundPoints`
  MODIFY `RoundPointsId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `SystemSettings`
--
ALTER TABLE `SystemSettings`
  MODIFY `SettingId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TournamentCategories`
--
ALTER TABLE `TournamentCategories`
  MODIFY `TournamentCategoryId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `TournamentPhotos`
--
ALTER TABLE `TournamentPhotos`
  MODIFY `PhotoId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Tournaments`
--
ALTER TABLE `Tournaments`
  MODIFY `TournamentId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `UserActivityLogs`
--
ALTER TABLE `UserActivityLogs`
  MODIFY `LogId` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Users`
--
ALTER TABLE `Users`
  MODIFY `UserId` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `Announcements`
--
ALTER TABLE `Announcements`
  ADD CONSTRAINT `Announcements_ibfk_1` FOREIGN KEY (`CreatedByAdminId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `Announcements_ibfk_2` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`);

--
-- Constraints for table `ApprovalLogs`
--
ALTER TABLE `ApprovalLogs`
  ADD CONSTRAINT `ApprovalLogs_ibfk_1` FOREIGN KEY (`ApprovedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `EmailBroadcasts`
--
ALTER TABLE `EmailBroadcasts`
  ADD CONSTRAINT `fk_broadcast_category` FOREIGN KEY (`TargetCategoryId`) REFERENCES `Categories` (`CategoryId`);

--
-- Constraints for table `EmailQueue`
--
ALTER TABLE `EmailQueue`
  ADD CONSTRAINT `fk_queue_broadcast` FOREIGN KEY (`BroadcastId`) REFERENCES `EmailBroadcasts` (`BroadcastId`) ON DELETE CASCADE;

--
-- Constraints for table `GalleryPhotos`
--
ALTER TABLE `GalleryPhotos`
  ADD CONSTRAINT `GalleryPhotos_ibfk_1` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`) ON DELETE CASCADE,
  ADD CONSTRAINT `GalleryPhotos_ibfk_2` FOREIGN KEY (`UploadedBy`) REFERENCES `Users` (`UserId`) ON DELETE CASCADE;

--
-- Constraints for table `Matches`
--
ALTER TABLE `Matches`
  ADD CONSTRAINT `Matches_ibfk_1` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`),
  ADD CONSTRAINT `Matches_ibfk_2` FOREIGN KEY (`CategoryId`) REFERENCES `Categories` (`CategoryId`),
  ADD CONSTRAINT `Matches_ibfk_3` FOREIGN KEY (`Player1Id`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `Matches_ibfk_4` FOREIGN KEY (`Partner1Id`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `Matches_ibfk_5` FOREIGN KEY (`Player2Id`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `Matches_ibfk_6` FOREIGN KEY (`Partner2Id`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `Notifications`
--
ALTER TABLE `Notifications`
  ADD CONSTRAINT `Notifications_ibfk_1` FOREIGN KEY (`UserId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `PasswordResets`
--
ALTER TABLE `PasswordResets`
  ADD CONSTRAINT `PasswordResets_ibfk_1` FOREIGN KEY (`UserId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `PlayerPerformanceTracker`
--
ALTER TABLE `PlayerPerformanceTracker`
  ADD CONSTRAINT `PlayerPerformanceTracker_ibfk_1` FOREIGN KEY (`PlayerId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `PlayerPerformanceTracker_ibfk_2` FOREIGN KEY (`CategoryId`) REFERENCES `Categories` (`CategoryId`);

--
-- Constraints for table `PlayerPointsHistory`
--
ALTER TABLE `PlayerPointsHistory`
  ADD CONSTRAINT `PlayerPointsHistory_ibfk_1` FOREIGN KEY (`PlayerId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `PlayerPointsHistory_ibfk_2` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`),
  ADD CONSTRAINT `PlayerPointsHistory_ibfk_3` FOREIGN KEY (`MatchId`) REFERENCES `Matches` (`MatchId`);

--
-- Constraints for table `PlayerRankingHistory`
--
ALTER TABLE `PlayerRankingHistory`
  ADD CONSTRAINT `PlayerRankingHistory_ibfk_1` FOREIGN KEY (`PlayerId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `PlayerRankingHistory_ibfk_2` FOREIGN KEY (`OldCategoryId`) REFERENCES `Categories` (`CategoryId`),
  ADD CONSTRAINT `PlayerRankingHistory_ibfk_3` FOREIGN KEY (`NewCategoryId`) REFERENCES `Categories` (`CategoryId`),
  ADD CONSTRAINT `PlayerRankingHistory_ibfk_4` FOREIGN KEY (`ChangedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `PlayerRegistrations`
--
ALTER TABLE `PlayerRegistrations`
  ADD CONSTRAINT `PlayerRegistrations_ibfk_1` FOREIGN KEY (`CategoryId`) REFERENCES `Categories` (`CategoryId`),
  ADD CONSTRAINT `PlayerRegistrations_ibfk_2` FOREIGN KEY (`ReviewedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `ResultsApprovalQueue`
--
ALTER TABLE `ResultsApprovalQueue`
  ADD CONSTRAINT `ResultsApprovalQueue_ibfk_1` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`),
  ADD CONSTRAINT `ResultsApprovalQueue_ibfk_2` FOREIGN KEY (`SubmittedByAdminId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `ResultsApprovalQueue_ibfk_3` FOREIGN KEY (`ReviewedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `SystemSettings`
--
ALTER TABLE `SystemSettings`
  ADD CONSTRAINT `SystemSettings_ibfk_1` FOREIGN KEY (`LastUpdatedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `TournamentCategories`
--
ALTER TABLE `TournamentCategories`
  ADD CONSTRAINT `TournamentCategories_ibfk_1` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`),
  ADD CONSTRAINT `TournamentCategories_ibfk_2` FOREIGN KEY (`CategoryId`) REFERENCES `Categories` (`CategoryId`);

--
-- Constraints for table `TournamentPhotos`
--
ALTER TABLE `TournamentPhotos`
  ADD CONSTRAINT `TournamentPhotos_ibfk_1` FOREIGN KEY (`TournamentId`) REFERENCES `Tournaments` (`TournamentId`),
  ADD CONSTRAINT `TournamentPhotos_ibfk_2` FOREIGN KEY (`UploadedByUserId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `Tournaments`
--
ALTER TABLE `Tournaments`
  ADD CONSTRAINT `Tournaments_ibfk_1` FOREIGN KEY (`OrganizingClubId`) REFERENCES `Clubs` (`ClubId`),
  ADD CONSTRAINT `Tournaments_ibfk_2` FOREIGN KEY (`CreatedByAdminId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `Tournaments_ibfk_3` FOREIGN KEY (`ApprovedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `UserActivityLogs`
--
ALTER TABLE `UserActivityLogs`
  ADD CONSTRAINT `UserActivityLogs_ibfk_1` FOREIGN KEY (`TargetUserId`) REFERENCES `Users` (`UserId`),
  ADD CONSTRAINT `UserActivityLogs_ibfk_2` FOREIGN KEY (`ChangedByAdminId`) REFERENCES `Users` (`UserId`);

--
-- Constraints for table `Users`
--
ALTER TABLE `Users`
  ADD CONSTRAINT `Users_ibfk_1` FOREIGN KEY (`ClubId`) REFERENCES `Clubs` (`ClubId`),
  ADD CONSTRAINT `Users_ibfk_2` FOREIGN KEY (`CategoryId`) REFERENCES `Categories` (`CategoryId`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
