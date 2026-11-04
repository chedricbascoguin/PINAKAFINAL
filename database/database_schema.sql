-- Schema only: database and 32 tables; no records.
CREATE DATABASE IF NOT EXISTS `if0_42839743_rhu` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `if0_42839743_rhu`;
SET NAMES utf8mb4;

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `health_worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(150) NOT NULL,
  `module_name` varchar(100) DEFAULT NULL,
  `record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` bigint(20) UNSIGNED DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `barangays` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `municipality` varchar(100) NOT NULL DEFAULT 'Nasugbu',
  `province` varchar(100) NOT NULL DEFAULT 'Batangas',
  `population` int(11) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `certificates` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `issued_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `certificate_number` varchar(100) NOT NULL,
  `certificate_type` varchar(100) NOT NULL,
  `purpose` text DEFAULT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Issued',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `certificate_doctor_assignments` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `certificate_type_id` int(11) NOT NULL,
  `purpose_keyword` varchar(150) NOT NULL DEFAULT '',
  `staff_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `certificate_email_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `certificate_id` int(11) NOT NULL,
  `recipient_email` varchar(255) NOT NULL,
  `email_type` varchar(60) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `delivery_status` varchar(30) NOT NULL,
  `delivery_method` varchar(60) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `certificate_signature_approvals` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `certificate_id` int(11) NOT NULL,
  `approver_type` varchar(30) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `approver_email` varchar(255) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `certificate_workflow_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `certificate_id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_staff_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `confidential_access_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `staff_id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `record_id` bigint(20) UNSIGNED NOT NULL,
  `action` varchar(80) NOT NULL,
  `accessed_at` datetime DEFAULT current_timestamp(),
  `ip_address` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `confidential_lab_tests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `test_type` varchar(80) NOT NULL DEFAULT 'HIV Screening',
  `result_status` varchar(50) NOT NULL DEFAULT 'Pending',
  `result_encrypted` longtext DEFAULT NULL,
  `specimen_date` date DEFAULT NULL,
  `release_date` date DEFAULT NULL,
  `counselor_staff_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by_staff_id` bigint(20) UNSIGNED NOT NULL,
  `notes_encrypted` longtext DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `consultations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `health_worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `health_record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `consultation_date` date NOT NULL,
  `consultation_time` time DEFAULT NULL,
  `chief_complaint` text NOT NULL,
  `symptoms` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `treatment` text DEFAULT NULL,
  `medications_prescribed` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `physician_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_by_staff_id` bigint(20) UNSIGNED DEFAULT NULL,
  `recorded_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `icd_code` varchar(50) DEFAULT NULL,
  `treatment_plan` text DEFAULT NULL,
  `consultation_notes` text DEFAULT NULL,
  `consultation_status` varchar(50) DEFAULT 'Scheduled',
  `referral_needed` tinyint(1) NOT NULL DEFAULT 0,
  `referral_to` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `dental_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `record_type` varchar(40) NOT NULL,
  `resident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `walkin_name` varchar(150) DEFAULT NULL,
  `record_date` date NOT NULL,
  `title` varchar(200) DEFAULT NULL,
  `tooth_numbers` varchar(120) DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Completed',
  `priority` varchar(30) DEFAULT NULL,
  `meta_json` longtext DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `disease_cases` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `disease_type_id` bigint(20) UNSIGNED NOT NULL,
  `consultation_id` bigint(20) UNSIGNED DEFAULT NULL,
  `barangay_id` bigint(20) UNSIGNED NOT NULL,
  `case_date` date NOT NULL,
  `date_reported` date NOT NULL,
  `symptoms` text DEFAULT NULL,
  `case_status` varchar(50) NOT NULL DEFAULT 'Suspected',
  `outcome` varchar(100) DEFAULT NULL,
  `reported_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `disease_id` bigint(20) UNSIGNED DEFAULT NULL,
  `case_classification` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `disease_types` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `icd_code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_reportable` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `disease_name` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `barangay_id` bigint(20) UNSIGNED DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `organizer_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Scheduled',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `fhsis_reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_type` varchar(100) NOT NULL DEFAULT 'FHSIS',
  `reporting_period` varchar(50) NOT NULL,
  `report_month` tinyint(3) UNSIGNED DEFAULT NULL,
  `report_year` smallint(5) UNSIGNED DEFAULT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ;

CREATE TABLE `health_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `height_cm` decimal(5,2) DEFAULT NULL,
  `weight_kg` decimal(5,2) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `temperature` decimal(4,1) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `immunization_notes` text DEFAULT NULL,
  `family_history` text DEFAULT NULL,
  `last_checkup_date` date DEFAULT NULL,
  `recorded_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `health_workers` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'Staff',
  `position_title` varchar(100) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `assigned_barangay_id` bigint(20) UNSIGNED DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Active',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `medicine_inventory` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `item_type` varchar(30) NOT NULL DEFAULT 'Medicine',
  `category` varchar(100) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `unit` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_level` int(11) NOT NULL DEFAULT 0,
  `expiration_date` date DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `supplier` varchar(150) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_announcements` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `title` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL DEFAULT 'Health Notice',
  `content` text NOT NULL,
  `badge_text` varchar(50) DEFAULT NULL,
  `image_url` text DEFAULT NULL,
  `is_popup` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `posted_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `event_date` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `venue` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `image_url` text DEFAULT NULL,
  `badge_color` varchar(50) DEFAULT 'bg-emerald-500',
  `scheduled_date` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Scheduled',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `audience_role` varchar(50) DEFAULT NULL,
  `message` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_push_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_push_subscriptions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` text NOT NULL,
  `auth` text NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `portal_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `pregnancy_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `health_worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `pregnancy_status` varchar(50) NOT NULL DEFAULT 'Pregnant',
  `last_menstrual_period` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `gravida` int(10) UNSIGNED DEFAULT NULL,
  `para` int(10) UNSIGNED DEFAULT NULL,
  `prenatal_visits_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `risk_level` varchar(50) NOT NULL DEFAULT 'Normal',
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `residents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `birthdate` date NOT NULL,
  `sex` varchar(20) NOT NULL,
  `civil_status` varchar(50) DEFAULT NULL,
  `barangay_id` bigint(20) UNSIGNED NOT NULL,
  `address` text NOT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `national_id` varchar(50) DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_number` varchar(30) DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `barangay` varchar(100) DEFAULT NULL,
  `philhealth_id` varchar(50) DEFAULT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `medical_conditions` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `resident_health_profiles` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `height` double(5,2) DEFAULT NULL,
  `weight` double(5,2) DEFAULT NULL,
  `blood_pressure` varchar(20) DEFAULT NULL,
  `heart_rate` int(11) DEFAULT NULL,
  `temperature` double(4,1) DEFAULT NULL,
  `last_checkup_date` date DEFAULT NULL,
  `smoking_status` varchar(50) DEFAULT NULL,
  `alcohol_consumption` varchar(50) DEFAULT NULL,
  `exercise_frequency` varchar(50) DEFAULT NULL,
  `diet_type` varchar(50) DEFAULT NULL,
  `blood_type` varchar(10) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `allergies` text DEFAULT NULL,
  `chronic_conditions` text DEFAULT NULL,
  `current_medications` text DEFAULT NULL,
  `emergency_contact_name` varchar(150) DEFAULT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `resident_locations` (
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `accuracy_m` int(10) UNSIGNED DEFAULT NULL,
  `source` varchar(40) NOT NULL DEFAULT 'resident_device',
  `shared_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sanitary_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `record_type` varchar(80) NOT NULL,
  `title` varchar(200) NOT NULL,
  `barangay` varchar(120) DEFAULT NULL,
  `record_date` date DEFAULT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'Recorded',
  `reference_id` bigint(20) UNSIGNED DEFAULT NULL,
  `resident_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `details_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ;

CREATE TABLE `stock_transactions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `medicine_inventory_id` bigint(20) UNSIGNED NOT NULL,
  `health_worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `transaction_type` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL,
  `transaction_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vaccination_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED NOT NULL,
  `vaccine_inventory_id` bigint(20) UNSIGNED DEFAULT NULL,
  `health_worker_id` bigint(20) UNSIGNED DEFAULT NULL,
  `provider_name` varchar(150) DEFAULT NULL,
  `age_group` varchar(50) NOT NULL,
  `vaccine_name` varchar(150) NOT NULL,
  `dose_number` varchar(50) DEFAULT NULL,
  `vaccination_date` date NOT NULL,
  `next_dose_date` date DEFAULT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  `vaccine_id` bigint(20) UNSIGNED DEFAULT NULL,
  `healthcare_provider_id` bigint(20) UNSIGNED DEFAULT NULL,
  `site_of_injection` varchar(100) DEFAULT NULL,
  `adverse_reactions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `vital_statistics` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `barangay_id` bigint(20) UNSIGNED NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_date` date NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `birth_status` varchar(50) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Recorded',
  `reference_number` varchar(100) DEFAULT NULL,
  `supporting_document` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `recorded_by_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_worker` (`health_worker_id`),
  ADD KEY `idx_audit_created` (`created_at`);

ALTER TABLE `barangays`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_barangay_name` (`name`);

ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_certificate_number` (`certificate_number`),
  ADD KEY `idx_certificate_resident` (`resident_id`),
  ADD KEY `idx_certificate_worker` (`issued_by_id`);

ALTER TABLE `certificate_doctor_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_certificate_assignment` (`certificate_type_id`,`purpose_keyword`,`staff_id`),
  ADD KEY `idx_certificate_assignment_lookup` (`certificate_type_id`,`purpose_keyword`,`is_active`);

ALTER TABLE `certificate_email_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_certificate_email_certificate` (`certificate_id`);

ALTER TABLE `certificate_signature_approvals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_certificate_signature_certificate` (`certificate_id`),
  ADD KEY `idx_certificate_signature_status` (`status`);

ALTER TABLE `certificate_workflow_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_certificate_workflow_certificate` (`certificate_id`);

ALTER TABLE `confidential_access_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_confidential_access_record` (`record_id`),
  ADD KEY `idx_confidential_access_staff` (`staff_id`),
  ADD KEY `idx_confidential_access_resident` (`resident_id`);

ALTER TABLE `confidential_lab_tests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_confidential_resident` (`resident_id`),
  ADD KEY `idx_confidential_counselor` (`counselor_staff_id`),
  ADD KEY `idx_confidential_type_status` (`test_type`,`result_status`);

ALTER TABLE `consultations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_consultation_resident` (`resident_id`),
  ADD KEY `idx_consultation_date` (`consultation_date`);

ALTER TABLE `dental_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_type` (`record_type`),
  ADD KEY `idx_resident` (`resident_id`),
  ADD KEY `idx_date` (`record_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_type_date` (`record_type`,`record_date`),
  ADD KEY `idx_performed_by` (`performed_by`);

ALTER TABLE `disease_cases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_disease_case_resident` (`resident_id`),
  ADD KEY `idx_disease_case_type` (`disease_type_id`),
  ADD KEY `idx_disease_case_date` (`case_date`);

ALTER TABLE `disease_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_disease_type_name` (`name`);

ALTER TABLE `events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_barangay` (`barangay_id`),
  ADD KEY `idx_event_start` (`start_datetime`);

ALTER TABLE `health_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_health_record_resident` (`resident_id`);

ALTER TABLE `health_workers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_health_worker_email` (`email`),
  ADD UNIQUE KEY `uq_health_worker_username` (`username`),
  ADD KEY `idx_health_worker_barangay` (`assigned_barangay_id`);

ALTER TABLE `medicine_inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_name` (`item_name`),
  ADD KEY `idx_inventory_type` (`item_type`);

ALTER TABLE `portal_announcements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_announcements_active` (`is_active`);

ALTER TABLE `portal_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_events_active` (`is_active`);

ALTER TABLE `portal_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resident` (`resident_id`),
  ADD KEY `idx_user_role` (`user_id`,`audience_role`),
  ADD KEY `idx_audience_read` (`audience_role`,`is_read`);

ALTER TABLE `portal_push_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `portal_push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_push_endpoint` (`endpoint`(191)),
  ADD KEY `idx_push_resident` (`resident_id`,`is_active`);

ALTER TABLE `portal_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `pregnancy_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pregnancy_resident` (`resident_id`),
  ADD KEY `idx_pregnancy_status` (`pregnancy_status`);

ALTER TABLE `residents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resident_name` (`last_name`,`first_name`),
  ADD KEY `idx_resident_barangay` (`barangay_id`);

ALTER TABLE `resident_health_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `resident_id` (`resident_id`),
  ADD UNIQUE KEY `uniq_resident_health_profile` (`resident_id`),
  ADD KEY `idx_rhp_resident` (`resident_id`);

ALTER TABLE `resident_locations`
  ADD PRIMARY KEY (`resident_id`),
  ADD KEY `idx_resident_locations_shared_at` (`shared_at`);

ALTER TABLE `stock_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stock_inventory` (`medicine_inventory_id`),
  ADD KEY `idx_stock_date` (`transaction_date`);

ALTER TABLE `vaccination_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vaccination_resident` (`resident_id`),
  ADD KEY `idx_vaccination_date` (`vaccination_date`);

ALTER TABLE `vital_statistics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vital_reference` (`reference_number`),
  ADD KEY `idx_vital_resident` (`resident_id`),
  ADD KEY `idx_vital_event` (`event_type`,`event_date`);

ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=833;

ALTER TABLE `barangays`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

ALTER TABLE `certificates`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

ALTER TABLE `certificate_doctor_assignments`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `certificate_email_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `certificate_signature_approvals`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `certificate_workflow_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `confidential_access_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `confidential_lab_tests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `consultations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=224;

ALTER TABLE `dental_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

ALTER TABLE `disease_cases`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=72;

ALTER TABLE `disease_types`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

ALTER TABLE `fhsis_reports`
  ADD PRIMARY KEY (`id`),
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `health_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=187;

ALTER TABLE `health_workers`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

ALTER TABLE `medicine_inventory`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

ALTER TABLE `portal_announcements`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

ALTER TABLE `portal_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `portal_notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=384;

ALTER TABLE `portal_push_subscriptions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=288;

ALTER TABLE `pregnancy_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

ALTER TABLE `residents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

ALTER TABLE `resident_health_profiles`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `sanitary_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

ALTER TABLE `stock_transactions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

ALTER TABLE `vaccination_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

ALTER TABLE `vital_statistics`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;
