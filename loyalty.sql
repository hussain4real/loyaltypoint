-- -------------------------------------------------------------
-- TablePlus 6.7.8(650)
--
-- https://tableplus.com/
--
-- Database: main
-- Generation Time: 2025-12-31 11:39:30.4100
-- -------------------------------------------------------------
-- MySQL-compatible dump (converted from Postgres)
-- Notes:
-- - Removes Postgres schemas/sequences/casts and uses AUTO_INCREMENT
-- - Converts Postgres types (int2/int4/int8/bool/json) to MySQL equivalents
-- - Drops `USING btree` and `COMMENT ON COLUMN` syntax
SET
    FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `migrations`;

CREATE TABLE `migrations` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `migration` varchar(255) NOT NULL,
    `batch` int NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `password_reset_tokens`;

CREATE TABLE `password_reset_tokens` (
    `email` varchar(255) NOT NULL,
    `token` varchar(255) NOT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`email`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `sessions`;

CREATE TABLE `sessions` (
    `id` varchar(255) NOT NULL,
    `user_id` bigint unsigned DEFAULT NULL,
    `ip_address` varchar(45) DEFAULT NULL,
    `user_agent` longtext,
    `payload` longtext NOT NULL,
    `last_activity` int NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache`;

CREATE TABLE `cache` (
    `key` varchar(255) NOT NULL,
    `value` mediumtext NOT NULL,
    `expiration` int NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `cache_locks`;

CREATE TABLE `cache_locks` (
    `key` varchar(255) NOT NULL,
    `owner` varchar(255) NOT NULL,
    `expiration` int NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `jobs`;

CREATE TABLE `jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `queue` varchar(255) NOT NULL,
    `payload` longtext NOT NULL,
    `attempts` smallint unsigned NOT NULL,
    `reserved_at` int DEFAULT NULL,
    `available_at` int NOT NULL,
    `created_at` int NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `job_batches`;

CREATE TABLE `job_batches` (
    `id` varchar(255) NOT NULL,
    `name` varchar(255) NOT NULL,
    `total_jobs` int NOT NULL,
    `pending_jobs` int NOT NULL,
    `failed_jobs` int NOT NULL,
    `failed_job_ids` longtext NOT NULL,
    `options` longtext,
    `cancelled_at` int DEFAULT NULL,
    `created_at` int NOT NULL,
    `finished_at` int DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `failed_jobs`;

CREATE TABLE `failed_jobs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `uuid` varchar(255) NOT NULL,
    `connection` text NOT NULL,
    `queue` text NOT NULL,
    `payload` longtext NOT NULL,
    `exception` longtext NOT NULL,
    `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `email_verified_at` timestamp NULL DEFAULT NULL,
    `password` varchar(255) NOT NULL,
    `remember_token` varchar(100) DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `personal_access_tokens`;

CREATE TABLE `personal_access_tokens` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `tokenable_type` varchar(255) NOT NULL,
    `tokenable_id` bigint unsigned NOT NULL,
    `name` text NOT NULL,
    `token` varchar(64) NOT NULL,
    `abilities` text,
    `last_used_at` timestamp NULL DEFAULT NULL,
    `expires_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `point_transactions`;

CREATE TABLE `point_transactions` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned NOT NULL,
    `provider_id` bigint unsigned NOT NULL,
    `type` varchar(255) NOT NULL,
    `points` int NOT NULL,
    `balance_after` bigint NOT NULL,
    `description` varchar(255) DEFAULT NULL,
    `metadata` json DEFAULT NULL,
    `expires_at` timestamp NULL DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `providers`;

CREATE TABLE `providers` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `trade_name` varchar(255) DEFAULT NULL,
    `slug` varchar(255) NOT NULL,
    `category` varchar(255) DEFAULT NULL,
    `description` text,
    `official_logo` varchar(255) DEFAULT NULL,
    `web_link` varchar(255) DEFAULT NULL,
    `is_active` tinyint (1) NOT NULL DEFAULT 1,
    `points_to_value_ratio` decimal(10, 4) NOT NULL DEFAULT 1.0000 COMMENT 'Value of 1 point in currency (e.g., 0.1 means 10 points = $1)',
    `transfer_fee_percent` decimal(5, 2) NOT NULL DEFAULT 0.00 COMMENT 'Fee charged when transferring OUT of this provider',
    `metadata` json DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `otps`;

CREATE TABLE `otps` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned NOT NULL,
    `code` varchar(6) NOT NULL,
    `purpose` varchar(255) NOT NULL DEFAULT 'vendor_auth',
    `expires_at` timestamp NOT NULL,
    `verified_at` timestamp NULL DEFAULT NULL,
    `attempts` smallint unsigned NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `user_provider_balances`;

CREATE TABLE `user_provider_balances` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned NOT NULL,
    `provider_id` bigint unsigned NOT NULL,
    `balance` bigint NOT NULL DEFAULT 0,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `vendor_user_links`;

CREATE TABLE `vendor_user_links` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `user_id` bigint unsigned NOT NULL,
    `provider_id` bigint unsigned NOT NULL,
    `vendor_email` varchar(255) NOT NULL,
    `linked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at` timestamp NULL DEFAULT NULL,
    `updated_at` timestamp NULL DEFAULT NULL,
    PRIMARY KEY (`id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO
    `migrations` (`id`, `migration`, `batch`)
VALUES
    (1, '0001_01_01_000000_create_users_table', 1),
    (2, '0001_01_01_000001_create_cache_table', 1),
    (3, '0001_01_01_000002_create_jobs_table', 1),
    (4, '0001_01_01_000003_create_providers_table', 1),
    (
        5,
        '2025_12_19_131342_create_personal_access_tokens_table',
        1
    ),
    (
        6,
        '2025_12_19_152522_create_point_transactions_table',
        1
    ),
    (7, '2025_12_21_065401_create_otps_table', 1),
    (
        8,
        '2025_12_22_064558_create_user_provider_balances_table',
        1
    ),
    (
        9,
        '2025_12_24_054642_create_vendor_user_links_table',
        1
    );

INSERT INTO
    `users` (
        `id`,
        `name`,
        `email`,
        `email_verified_at`,
        `password`,
        `remember_token`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        'Test User',
        'test@example.com',
        '2025-12-24 09:03:08',
        '$2y$12$yeDaOsFPUeGLZ7fMYvFkg.0XR2D.Jfa8CqvZT6uzlIgkttI7wuvNe',
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        2,
        'Alice Johnson',
        'alice@example.com',
        '2025-12-24 09:03:08',
        '$2y$12$eQgmjhyC7cq3XUhXXOijoOfo7cSZgRqRT1Yj6Tnu22Mt2KqAkcwKS',
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        3,
        'Bob Smith',
        'bob@example.com',
        '2025-12-24 09:03:08',
        '$2y$12$JYo2glzkvPgSPayTvr5WjO/x8EwH0mPQLtWT9LtXeRrSfgwFg/3Ya',
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        4,
        'Aminu Hussain',
        'hussain_aminu@yahoo.com',
        '2025-12-24 09:03:08',
        '$2y$12$1HVYwr2oKaoeVuPO2bKBPugQtRo2Ep.0UD8uYcQLxYbmwaDj28qEC',
        NULL,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        5,
        'David Brown',
        'david@example.com',
        '2025-12-24 09:03:09',
        '$2y$12$aVhDfK1Gf51LP0gb5id8Q.h9sNeydy3rLCvfRxXTMC0IEht/dCtbO',
        NULL,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        6,
        'Emma Davis',
        'emma@example.com',
        '2025-12-24 09:03:09',
        '$2y$12$1ycYs54p0cHTGlqP3/9lBepgUGRAr7wpofaZG6DoLcA3rr.JMnSUm',
        NULL,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        7,
        'Erastus',
        'era9833@gmail.com',
        '2025-12-24 09:03:09',
        '$2y$12$1ycYs54p0cHTGlqP3/9lBepgUGRAr7wpofaZG6DoLcA3rr.JMnSUm',
        NULL,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    );

INSERT INTO
    `personal_access_tokens` (
        `id`,
        `tokenable_type`,
        `tokenable_id`,
        `name`,
        `token`,
        `abilities`,
        `last_used_at`,
        `expires_at`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'a8c9cc5e37b5e1e53acfce4d12d4dfbe92b816c16c918763888d669754be5630',
        '["points:read","transactions:read"]',
        '2025-12-24 09:18:18',
        NULL,
        '2025-12-24 09:11:35',
        '2025-12-24 09:18:18'
    ),
    (
        2,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '7f4c2dc7bbc7a49c44e74c2eabc1cf09976c8a72bf7ae0b2041af300f2804d0c',
        '["points:read","transactions:read"]',
        '2025-12-24 09:26:08',
        NULL,
        '2025-12-24 09:25:56',
        '2025-12-24 09:26:08'
    ),
    (
        3,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '6db13007889ab7bdf7799a630712e45d31989dec4ae5532c1da9e89eb26e1f3e',
        '["points:read","transactions:read"]',
        '2025-12-24 09:28:32',
        NULL,
        '2025-12-24 09:26:44',
        '2025-12-24 09:28:32'
    ),
    (
        4,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '586f9f1c8f69eb1d41a2e7ec9494a0e460eed28add877189f3d858064fdb1b87',
        '["points:read","transactions:read"]',
        '2025-12-24 09:45:49',
        NULL,
        '2025-12-24 09:32:20',
        '2025-12-24 09:45:49'
    ),
    (
        5,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'b7a96b3033cec63d46bcdf7e5887aa58898bec7981590afa26e1897125a8594c',
        '["points:read","transactions:read"]',
        '2025-12-24 09:47:49',
        NULL,
        '2025-12-24 09:47:32',
        '2025-12-24 09:47:49'
    ),
    (
        6,
        'App\Models\User',
        5,
        'Wafaa App Android',
        '1b1ed0808b608342f7875e554ee653ce0a6177bede1f97a82c695f65eaf2b89f',
        '["points:read","transactions:read"]',
        '2025-12-24 10:10:20',
        NULL,
        '2025-12-24 10:09:06',
        '2025-12-24 10:10:20'
    ),
    (
        7,
        'App\Models\User',
        5,
        'Wafaa App Android',
        'c3e0ed0bc7bd6d3dc70780ad6ec9a33e2c9a81980a5053884fead5a69fb4621e',
        '["points:read","transactions:read"]',
        '2025-12-25 08:32:15',
        NULL,
        '2025-12-24 10:10:57',
        '2025-12-25 08:32:15'
    ),
    (
        8,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '812feaf332fb399ade22ced5554a5eca3fcf0fc4670c15be64deb33a335f98b2',
        '["points:read","transactions:read"]',
        '2025-12-24 17:26:19',
        NULL,
        '2025-12-24 17:25:45',
        '2025-12-24 17:26:19'
    ),
    (
        9,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '0e9104b1e8703671f0384a276c985a4bb6ae454d8d72cd3fc4c096ee9172d35f',
        '["points:read","transactions:read"]',
        '2025-12-25 08:52:20',
        NULL,
        '2025-12-25 08:42:22',
        '2025-12-25 08:52:20'
    ),
    (
        10,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        'e0815fefdb5dc906536158479b8a5ae55fcca20597d63d63a6d347a656690309',
        '["points:read","transactions:read"]',
        '2025-12-26 07:11:12',
        NULL,
        '2025-12-25 09:44:07',
        '2025-12-26 07:11:12'
    ),
    (
        11,
        'App\Models\User',
        4,
        'Wafaa App Android',
        '94f1998b9eb601f48f3b5d99ed9663f31303aa7eb03d63897754cd61e3291757',
        '["points:read","transactions:read"]',
        '2025-12-25 11:06:30',
        NULL,
        '2025-12-25 11:06:09',
        '2025-12-25 11:06:30'
    ),
    (
        12,
        'App\Models\User',
        4,
        'Wafaa App Android',
        'c15362aa47ab16e4a4d0a969a1157dddf1cf4ce332977872b0cd2658b8c021ec',
        '["points:read","transactions:read"]',
        '2025-12-28 05:26:01',
        NULL,
        '2025-12-25 11:07:00',
        '2025-12-28 05:26:01'
    ),
    (
        13,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '8291d62fa4aa63e6a5954fa9b90421c979a6671cdc491b9ff5a5badf2bbddfc8',
        '["points:read","transactions:read"]',
        '2025-12-26 05:15:26',
        NULL,
        '2025-12-26 04:07:45',
        '2025-12-26 05:15:26'
    ),
    (
        14,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'f20040d99de96cb04bec0d7a43ea604e24a7dd8e52923f882cb090cac637f53b',
        '["points:read","transactions:read"]',
        '2025-12-26 06:12:10',
        NULL,
        '2025-12-26 06:11:16',
        '2025-12-26 06:12:10'
    ),
    (
        15,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '2822ce778e12e9e537af0a7eea90911aacba3165aca0f07193bf204d0c7b0918',
        '["points:read","transactions:read"]',
        '2025-12-26 07:14:53',
        NULL,
        '2025-12-26 06:15:13',
        '2025-12-26 07:14:53'
    ),
    (
        16,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        'a43588cbcdbda8b9bafd6a7de89dc8cfb1a6d0ee92a004b4c65213ecd9f86f30',
        '["points:read","transactions:read"]',
        '2025-12-26 07:12:03',
        NULL,
        '2025-12-26 07:11:55',
        '2025-12-26 07:12:03'
    ),
    (
        17,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'e08582e7a5b2ce00ac36183a34b7bf3031958433bc2db310ec426b3d6a485d22',
        '["points:read","transactions:read"]',
        '2025-12-26 07:25:46',
        NULL,
        '2025-12-26 07:19:09',
        '2025-12-26 07:25:46'
    ),
    (
        18,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '2f01868915db422da16c57ca5d0d821894e5996517f01c8cbfdcb744152dba84',
        '["points:read","transactions:read"]',
        '2025-12-26 08:04:04',
        NULL,
        '2025-12-26 08:01:08',
        '2025-12-26 08:04:04'
    ),
    (
        19,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '7a562c6f6ecf047757bbc70fd4865e1a4ee4590296ab93b8d00d82ddd20a2c0b',
        '["points:read","transactions:read"]',
        '2025-12-26 08:10:09',
        NULL,
        '2025-12-26 08:05:22',
        '2025-12-26 08:10:09'
    ),
    (
        20,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '0eacc2fc2cdb2f17161cedcf6e7b2d4a1512a6fc9a35a42671c77fdb3739d441',
        '["points:read","transactions:read"]',
        '2025-12-26 08:16:26',
        NULL,
        '2025-12-26 08:11:14',
        '2025-12-26 08:16:26'
    ),
    (
        21,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '28fc3cc8f461802b106cfe89122010c9954e4954e3cd02f23a681702070422fd',
        '["points:read","transactions:read"]',
        '2025-12-26 08:32:40',
        NULL,
        '2025-12-26 08:32:32',
        '2025-12-26 08:32:40'
    ),
    (
        22,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '0adae96737499fff43ede45c6713774db34a5d8e95aa2169b598a7b5ca7dba49',
        '["points:read","transactions:read"]',
        '2025-12-26 08:37:06',
        NULL,
        '2025-12-26 08:33:40',
        '2025-12-26 08:37:06'
    ),
    (
        23,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'db593e10b2a2e9293056df8e88793cf9417e4935b8a1c8536023817dd5c2325f',
        '["points:read","transactions:read"]',
        '2025-12-26 09:57:13',
        NULL,
        '2025-12-26 08:37:42',
        '2025-12-26 09:57:13'
    ),
    (
        24,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '394a3576525ea20a3e509471910b2d2881302294aa0d247cd9444f3253cfd2b6',
        '["points:read","transactions:read"]',
        '2025-12-26 10:02:44',
        NULL,
        '2025-12-26 10:02:35',
        '2025-12-26 10:02:44'
    ),
    (
        25,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '6b1ac6f40b62d6dd7dff64d95096a6b4f722ac1f0b6f53eae9c6b9601311c396',
        '["points:read","transactions:read"]',
        '2025-12-26 10:33:42',
        NULL,
        '2025-12-26 10:31:55',
        '2025-12-26 10:33:42'
    ),
    (
        26,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '4f5f4b86161d7ea9998142ef28b5b0946a60fda0ac7d7a26bb70b0b498154c56',
        '["points:read","transactions:read"]',
        '2025-12-26 11:40:42',
        NULL,
        '2025-12-26 11:32:54',
        '2025-12-26 11:40:42'
    ),
    (
        27,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '037d47d6a331e63a8d24107c5bea1e746cf637b4ee5cca8553a32bd01d69eeca',
        '["points:read","transactions:read"]',
        '2025-12-26 12:32:57',
        NULL,
        '2025-12-26 11:57:21',
        '2025-12-26 12:32:57'
    ),
    (
        28,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '05c7c35b315165e4c3d4d4b1b27a526992b9921ececfed9d04cdb43f63f699d1',
        '["points:read","transactions:read"]',
        '2025-12-26 14:31:38',
        NULL,
        '2025-12-26 12:33:46',
        '2025-12-26 14:31:38'
    ),
    (
        29,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        'e60344af4611ea884b4e6e99043898938633a92bc5aafbd24930f44006c0533e',
        '["points:read","transactions:read"]',
        '2025-12-30 19:47:45',
        NULL,
        '2025-12-26 16:32:50',
        '2025-12-30 19:47:45'
    ),
    (
        30,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'ba382481b5ba715e53d8689ffadb9eefc2dc66e37a7fabbac29e6d1d18cd3c64',
        '["points:read","transactions:read"]',
        '2025-12-28 08:28:51',
        NULL,
        '2025-12-26 18:16:21',
        '2025-12-28 08:28:51'
    ),
    (
        31,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'e9c04eaddb74b571745d752f3cff63bef0d648d05cd4f46f604b3d317067c120',
        '["points:read","transactions:read"]',
        '2025-12-28 09:28:42',
        NULL,
        '2025-12-28 08:53:13',
        '2025-12-28 09:28:42'
    ),
    (
        32,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '2c1cc55abd2f1f5f0a607b35a2254d389900845ee7d9b39307982997c8fc112c',
        '["points:read","transactions:read"]',
        '2025-12-28 09:43:58',
        NULL,
        '2025-12-28 09:32:38',
        '2025-12-28 09:43:58'
    ),
    (
        33,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '5e9dc8a9157388733a7dfbab0c85ae4e435ee2336c14c98f07e03a169c865d34',
        '["points:read","transactions:read"]',
        '2025-12-28 10:01:34',
        NULL,
        '2025-12-28 09:48:54',
        '2025-12-28 10:01:34'
    ),
    (
        34,
        'App\Models\User',
        2,
        'Wafaa App Android',
        '9cf74256ddaea8eeaec181e1effd1fad589a2a81401e1893fbb67283e60b053d',
        '["points:read","transactions:read"]',
        '2025-12-28 11:19:41',
        NULL,
        '2025-12-28 11:05:17',
        '2025-12-28 11:19:41'
    ),
    (
        35,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'f1ffdac44f2046af4aa711ddfe43a51993b3d926b8c537958f5b1f7469e5de73',
        '["points:read","transactions:read"]',
        '2025-12-31 04:05:56',
        NULL,
        '2025-12-28 11:41:15',
        '2025-12-31 04:05:56'
    ),
    (
        36,
        'App\Models\User',
        4,
        'Wafaa App Android',
        '1fe3b406ae15bf129b6a4df2e350b11e54df0c6cebe574cc287c5573dde58465',
        '["points:read","transactions:read"]',
        '2025-12-28 11:45:01',
        NULL,
        '2025-12-28 11:44:58',
        '2025-12-28 11:45:01'
    ),
    (
        37,
        'App\Models\User',
        2,
        'Wafaa App iOS',
        '011fd6c29df5cb8f2417f336a7498d49dfe55677eb171d6bb6f3ad8f6184701d',
        '["points:read","transactions:read"]',
        '2025-12-31 04:49:13',
        NULL,
        '2025-12-30 19:49:21',
        '2025-12-31 04:49:13'
    ),
    (
        38,
        'App\Models\User',
        2,
        'Wafaa App Android',
        'cad95c86df721baec5372ba12c202f8e34d4a90aa3c2de80bbac3dbd628b6d89',
        '["points:read","transactions:read"]',
        '2025-12-31 05:26:45',
        NULL,
        '2025-12-31 04:11:37',
        '2025-12-31 05:26:45'
    ),
    (
        39,
        'App\Models\User',
        7,
        'Wafaa App iOS',
        '1731f7d81a4fecec327fb8dfc07429c7204791fda47e3f97e40fa511f1988e54',
        '["points:read","transactions:read"]',
        '2025-12-31 05:46:39',
        NULL,
        '2025-12-31 04:49:12',
        '2025-12-31 05:46:39'
    ),
    (
        41,
        'App\Models\User',
        2,
        'Store Terminal #1',
        '604509684edec37e86ba60ec3e16afabdecb14a9113d28558ee0ba714592222b',
        '["points:read","transactions:read"]',
        '2025-12-31 05:16:55',
        NULL,
        '2025-12-31 05:02:49',
        '2025-12-31 05:16:55'
    );

INSERT INTO
    `point_transactions` (
        `id`,
        `user_id`,
        `provider_id`,
        `type`,
        `points`,
        `balance_after`,
        `description`,
        `metadata`,
        `expires_at`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        5,
        4,
        'bonus',
        500,
        500,
        'VIP welcome bonus',
        NULL,
        NULL,
        '2025-10-15 09:03:09',
        '2025-10-15 09:03:09'
    ),
    (
        2,
        3,
        2,
        'earn',
        500,
        500,
        'Purchase #ORD-200001',
        NULL,
        NULL,
        '2025-10-25 09:03:09',
        '2025-10-25 09:03:09'
    ),
    (
        3,
        4,
        3,
        'earn',
        175,
        175,
        'Purchase #ORD-300001',
        NULL,
        NULL,
        '2025-10-30 09:03:09',
        '2025-10-30 09:03:09'
    ),
    (
        4,
        2,
        1,
        'bonus',
        100,
        100,
        'Welcome bonus',
        NULL,
        NULL,
        '2025-11-04 09:03:09',
        '2025-11-04 09:03:09'
    ),
    (
        5,
        5,
        4,
        'earn',
        600,
        1100,
        'Purchase #ORD-400001',
        NULL,
        NULL,
        '2025-11-04 09:03:09',
        '2025-11-04 09:03:09'
    ),
    (
        6,
        2,
        1,
        'earn',
        250,
        350,
        'Purchase #ORD-100001',
        NULL,
        NULL,
        '2025-11-09 09:03:09',
        '2025-11-09 09:03:09'
    ),
    (
        7,
        3,
        2,
        'earn',
        350,
        850,
        'Purchase #ORD-200002',
        NULL,
        NULL,
        '2025-11-14 09:03:09',
        '2025-11-14 09:03:09'
    ),
    (
        8,
        3,
        2,
        'bonus',
        200,
        1050,
        'Loyalty bonus',
        NULL,
        NULL,
        '2025-11-19 09:03:09',
        '2025-11-19 09:03:09'
    ),
    (
        9,
        2,
        1,
        'earn',
        150,
        500,
        'Purchase #ORD-100002',
        NULL,
        NULL,
        '2025-11-24 09:03:09',
        '2025-11-24 09:03:09'
    ),
    (
        10,
        5,
        4,
        'earn',
        450,
        1550,
        'Purchase #ORD-400002',
        NULL,
        NULL,
        '2025-11-24 09:03:09',
        '2025-11-24 09:03:09'
    ),
    (
        11,
        2,
        2,
        'bonus',
        50,
        50,
        'Signup bonus',
        NULL,
        NULL,
        '2025-11-29 09:03:09',
        '2025-11-29 09:03:09'
    ),
    (
        12,
        4,
        3,
        'earn',
        225,
        400,
        'Purchase #ORD-300002',
        NULL,
        NULL,
        '2025-11-29 09:03:09',
        '2025-11-29 09:03:09'
    ),
    (
        13,
        2,
        2,
        'earn',
        200,
        250,
        'Partner purchase',
        NULL,
        NULL,
        '2025-12-04 09:03:09',
        '2025-12-04 09:03:09'
    ),
    (
        14,
        3,
        2,
        'redeem',
        -150,
        900,
        'Reward redemption',
        NULL,
        NULL,
        '2025-12-04 09:03:09',
        '2025-12-04 09:03:09'
    ),
    (
        15,
        6,
        1,
        'earn',
        125,
        125,
        'Purchase #ORD-500001',
        NULL,
        NULL,
        '2025-12-04 09:03:09',
        '2025-12-04 09:03:09'
    ),
    (
        16,
        2,
        1,
        'redeem',
        -75,
        425,
        'Reward redemption',
        NULL,
        NULL,
        '2025-12-09 09:03:09',
        '2025-12-09 09:03:09'
    ),
    (
        17,
        5,
        4,
        'redeem',
        -200,
        1350,
        'Reward redemption',
        NULL,
        NULL,
        '2025-12-09 09:03:09',
        '2025-12-09 09:03:09'
    ),
    (
        18,
        5,
        5,
        'earn',
        1000,
        1000,
        'Premium purchase',
        NULL,
        NULL,
        '2025-12-09 09:03:09',
        '2025-12-09 09:03:09'
    ),
    (
        19,
        6,
        1,
        'earn',
        200,
        325,
        'Purchase #ORD-500002',
        NULL,
        NULL,
        '2025-12-12 09:03:09',
        '2025-12-12 09:03:09'
    ),
    (
        20,
        3,
        2,
        'earn',
        275,
        1175,
        'Purchase #ORD-200003',
        NULL,
        NULL,
        '2025-12-14 09:03:09',
        '2025-12-14 09:03:09'
    ),
    (
        21,
        5,
        5,
        'bonus',
        500,
        1500,
        'VIP tier bonus',
        NULL,
        NULL,
        '2025-12-14 09:03:09',
        '2025-12-14 09:03:09'
    ),
    (
        22,
        6,
        1,
        'bonus',
        75,
        400,
        'Referral bonus',
        NULL,
        NULL,
        '2025-12-14 09:03:09',
        '2025-12-14 09:03:09'
    ),
    (
        23,
        4,
        3,
        'earn',
        400,
        800,
        'Purchase #ORD-300003',
        NULL,
        NULL,
        '2025-12-16 09:03:09',
        '2025-12-16 09:03:09'
    ),
    (
        24,
        5,
        4,
        'earn',
        550,
        1900,
        'Purchase #ORD-400003',
        NULL,
        NULL,
        '2025-12-17 09:03:09',
        '2025-12-17 09:03:09'
    ),
    (
        25,
        2,
        1,
        'earn',
        300,
        725,
        'Purchase #ORD-100003',
        NULL,
        NULL,
        '2025-12-19 09:03:09',
        '2025-12-19 09:03:09'
    ),
    (
        26,
        6,
        1,
        'redeem',
        -50,
        350,
        'Reward redemption',
        NULL,
        NULL,
        '2025-12-19 09:03:09',
        '2025-12-19 09:03:09'
    ),
    (
        27,
        3,
        2,
        'redeem',
        -100,
        1075,
        'Reward redemption',
        NULL,
        NULL,
        '2025-12-21 09:03:09',
        '2025-12-21 09:03:09'
    ),
    (
        28,
        5,
        4,
        'adjustment',
        50,
        1950,
        'Customer service adjustment',
        NULL,
        NULL,
        '2025-12-22 09:03:09',
        '2025-12-22 09:03:09'
    ),
    (
        29,
        2,
        1,
        'transfer_out',
        -363,
        362,
        'Transfer to Rewards Hub',
        '{"exchange_id":"a274f905-924f-4d73-9aaf-e5b1f3815598","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":363,"gross_value":36.3,"total_fee_percent":10,"total_fee_value":3.63,"net_value":32.67,"points_received":32}',
        NULL,
        '2025-12-24 09:28:12',
        '2025-12-24 09:28:12'
    ),
    (
        30,
        2,
        2,
        'transfer_in',
        32,
        282,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"a274f905-924f-4d73-9aaf-e5b1f3815598","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":363,"gross_value":36.3,"total_fee_percent":10,"total_fee_value":3.63,"net_value":32.67}',
        NULL,
        '2025-12-24 09:28:12',
        '2025-12-24 09:28:12'
    ),
    (
        31,
        5,
        4,
        'transfer_out',
        -975,
        975,
        'Transfer to Loyalty Plus',
        '{"exchange_id":"1075ea34-ae48-4670-9723-0b93c6101df6","vendor_exchange":true,"to_provider_id":1,"to_provider_slug":"loyalty-plus","to_user_id":2,"points_sent":975,"gross_value":487.5,"total_fee_percent":9,"total_fee_value":43.88,"net_value":443.62,"points_received":4436}',
        NULL,
        '2025-12-24 11:24:25',
        '2025-12-24 11:24:25'
    ),
    (
        32,
        2,
        1,
        'transfer_in',
        4436,
        4798,
        'Transfer from Bonus Network',
        '{"exchange_id":"1075ea34-ae48-4670-9723-0b93c6101df6","vendor_exchange":true,"from_provider_id":4,"from_provider_slug":"bonus-network","from_user_id":5,"original_points":975,"gross_value":487.5,"total_fee_percent":9,"total_fee_value":43.88,"net_value":443.62}',
        NULL,
        '2025-12-24 11:24:25',
        '2025-12-24 11:24:25'
    ),
    (
        33,
        5,
        5,
        'transfer_out',
        -375,
        1125,
        'Transfer to Bonus Network',
        '{"exchange_id":"5e3caf67-e241-4d75-bcbe-98a10e8df71e","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":375,"gross_value":750,"total_fee_percent":8.5,"total_fee_value":63.75,"net_value":686.25,"points_received":1372}',
        NULL,
        '2025-12-24 13:53:26',
        '2025-12-24 13:53:26'
    ),
    (
        34,
        5,
        4,
        'transfer_in',
        1372,
        2347,
        'Transfer from Premium Rewards',
        '{"exchange_id":"5e3caf67-e241-4d75-bcbe-98a10e8df71e","vendor_exchange":true,"from_provider_id":5,"from_provider_slug":"premium-rewards","from_user_id":5,"original_points":375,"gross_value":750,"total_fee_percent":8.5,"total_fee_value":63.75,"net_value":686.25}',
        NULL,
        '2025-12-24 13:53:26',
        '2025-12-24 13:53:26'
    ),
    (
        35,
        2,
        1,
        'transfer_out',
        -1200,
        3598,
        'Transfer to Bonus Network',
        '{"exchange_id":"1a22b245-da50-42eb-b24e-d8ccaf591a3b","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":1200,"gross_value":120,"total_fee_percent":9,"total_fee_value":10.8,"net_value":109.2,"points_received":218}',
        NULL,
        '2025-12-25 06:14:10',
        '2025-12-25 06:14:10'
    ),
    (
        36,
        5,
        4,
        'transfer_in',
        218,
        2565,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"1a22b245-da50-42eb-b24e-d8ccaf591a3b","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":1200,"gross_value":120,"total_fee_percent":9,"total_fee_value":10.8,"net_value":109.2}',
        NULL,
        '2025-12-25 06:14:10',
        '2025-12-25 06:14:10'
    ),
    (
        37,
        2,
        2,
        'transfer_out',
        -100,
        182,
        'Transfer to Loyalty Plus',
        '{"exchange_id":"4f5dd06b-a9de-48d6-82ca-e04969a9ac59","vendor_exchange":true,"to_provider_id":1,"to_provider_slug":"loyalty-plus","to_user_id":2,"points_sent":100,"gross_value":100,"total_fee_percent":10,"total_fee_value":10,"net_value":90,"points_received":900}',
        NULL,
        '2025-12-25 06:29:05',
        '2025-12-25 06:29:05'
    ),
    (
        38,
        2,
        1,
        'transfer_in',
        900,
        4498,
        'Transfer from Rewards Hub',
        '{"exchange_id":"4f5dd06b-a9de-48d6-82ca-e04969a9ac59","vendor_exchange":true,"from_provider_id":2,"from_provider_slug":"rewards-hub","from_user_id":2,"original_points":100,"gross_value":100,"total_fee_percent":10,"total_fee_value":10,"net_value":90}',
        NULL,
        '2025-12-25 06:29:05',
        '2025-12-25 06:29:05'
    ),
    (
        39,
        2,
        1,
        'transfer_out',
        -2249,
        2249,
        'Transfer to Rewards Hub',
        '{"exchange_id":"40b8eccb-9426-4d9d-bc95-a5edd65cdfbd","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":2249,"gross_value":224.9,"total_fee_percent":10,"total_fee_value":22.49,"net_value":202.41,"points_received":202}',
        NULL,
        '2025-12-25 06:35:01',
        '2025-12-25 06:35:01'
    ),
    (
        40,
        2,
        2,
        'transfer_in',
        202,
        384,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"40b8eccb-9426-4d9d-bc95-a5edd65cdfbd","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":2249,"gross_value":224.9,"total_fee_percent":10,"total_fee_value":22.49,"net_value":202.41}',
        NULL,
        '2025-12-25 06:35:01',
        '2025-12-25 06:35:01'
    ),
    (
        41,
        2,
        1,
        'transfer_out',
        -562,
        1687,
        'Transfer to Bonus Network',
        '{"exchange_id":"ad48cc12-d868-4e44-80a2-18569bee64bd","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":562,"gross_value":56.2,"total_fee_percent":9,"total_fee_value":5.06,"net_value":51.14,"points_received":102}',
        NULL,
        '2025-12-25 08:43:24',
        '2025-12-25 08:43:24'
    ),
    (
        42,
        5,
        4,
        'transfer_in',
        102,
        2667,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"ad48cc12-d868-4e44-80a2-18569bee64bd","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":562,"gross_value":56.2,"total_fee_percent":9,"total_fee_value":5.06,"net_value":51.14}',
        NULL,
        '2025-12-25 08:43:24',
        '2025-12-25 08:43:24'
    ),
    (
        43,
        5,
        4,
        'transfer_out',
        -1000,
        1667,
        'Transfer to Rewards Hub',
        '{"exchange_id":"1f6f26a7-df7e-4556-8b77-8edb46d97009","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":1000,"gross_value":500,"total_fee_percent":11,"total_fee_value":55,"net_value":445,"points_received":445}',
        NULL,
        '2025-12-25 09:55:23',
        '2025-12-25 09:55:23'
    ),
    (
        44,
        2,
        2,
        'transfer_in',
        445,
        829,
        'Transfer from Bonus Network',
        '{"exchange_id":"1f6f26a7-df7e-4556-8b77-8edb46d97009","vendor_exchange":true,"from_provider_id":4,"from_provider_slug":"bonus-network","from_user_id":5,"original_points":1000,"gross_value":500,"total_fee_percent":11,"total_fee_value":55,"net_value":445}',
        NULL,
        '2025-12-25 09:55:23',
        '2025-12-25 09:55:23'
    ),
    (
        45,
        2,
        1,
        'transfer_out',
        -422,
        1265,
        'Transfer to Bonus Network',
        '{"exchange_id":"056d2265-96b3-4731-979b-6f7343ff4a17","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":422,"gross_value":42.2,"total_fee_percent":9,"total_fee_value":3.8,"net_value":38.4,"points_received":76}',
        NULL,
        '2025-12-25 12:48:40',
        '2025-12-25 12:48:40'
    ),
    (
        46,
        5,
        4,
        'transfer_in',
        76,
        1743,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"056d2265-96b3-4731-979b-6f7343ff4a17","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":422,"gross_value":42.2,"total_fee_percent":9,"total_fee_value":3.8,"net_value":38.4}',
        NULL,
        '2025-12-25 12:48:40',
        '2025-12-25 12:48:40'
    ),
    (
        47,
        5,
        4,
        'transfer_out',
        -436,
        1307,
        'Transfer to Loyalty Plus',
        '{"exchange_id":"fb22108f-5e4a-4275-9b4d-a5ed4876120d","vendor_exchange":true,"to_provider_id":1,"to_provider_slug":"loyalty-plus","to_user_id":2,"points_sent":436,"gross_value":218,"total_fee_percent":9,"total_fee_value":19.62,"net_value":198.38,"points_received":1983}',
        NULL,
        '2025-12-26 04:08:19',
        '2025-12-26 04:08:19'
    ),
    (
        48,
        2,
        1,
        'transfer_in',
        1983,
        3248,
        'Transfer from Bonus Network',
        '{"exchange_id":"fb22108f-5e4a-4275-9b4d-a5ed4876120d","vendor_exchange":true,"from_provider_id":4,"from_provider_slug":"bonus-network","from_user_id":5,"original_points":436,"gross_value":218,"total_fee_percent":9,"total_fee_value":19.62,"net_value":198.38}',
        NULL,
        '2025-12-26 04:08:19',
        '2025-12-26 04:08:19'
    ),
    (
        49,
        2,
        1,
        'transfer_out',
        -812,
        2436,
        'Transfer to Bonus Network',
        '{"exchange_id":"205932e7-46e8-4e79-87b7-80f650575c2d","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":812,"gross_value":81.2,"total_fee_percent":9,"total_fee_value":7.31,"net_value":73.89,"points_received":147}',
        NULL,
        '2025-12-26 10:35:35',
        '2025-12-26 10:35:35'
    ),
    (
        50,
        5,
        4,
        'transfer_in',
        147,
        1454,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"205932e7-46e8-4e79-87b7-80f650575c2d","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":812,"gross_value":81.2,"total_fee_percent":9,"total_fee_value":7.31,"net_value":73.89}',
        NULL,
        '2025-12-26 10:35:35',
        '2025-12-26 10:35:35'
    ),
    (
        51,
        2,
        1,
        'transfer_out',
        -609,
        1827,
        'Transfer to Premium Rewards',
        '{"exchange_id":"7fabdde0-5e76-4538-8827-476ba808bcb6","vendor_exchange":true,"to_provider_id":5,"to_provider_slug":"premium-rewards","to_user_id":5,"points_sent":609,"gross_value":60.9,"total_fee_percent":7.5,"total_fee_value":4.57,"net_value":56.33,"points_received":28}',
        NULL,
        '2025-12-26 17:10:40',
        '2025-12-26 17:10:40'
    ),
    (
        52,
        5,
        5,
        'transfer_in',
        28,
        1153,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"7fabdde0-5e76-4538-8827-476ba808bcb6","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":609,"gross_value":60.9,"total_fee_percent":7.5,"total_fee_value":4.57,"net_value":56.33}',
        NULL,
        '2025-12-26 17:10:40',
        '2025-12-26 17:10:40'
    ),
    (
        53,
        5,
        4,
        'transfer_out',
        -200,
        1254,
        'Transfer to Rewards Hub',
        '{"exchange_id":"26799a86-3ab7-49c4-ab04-0956780a28d1","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":200,"gross_value":100,"total_fee_percent":11,"total_fee_value":11,"net_value":89,"points_received":89}',
        NULL,
        '2025-12-31 04:36:09',
        '2025-12-31 04:36:09'
    ),
    (
        54,
        2,
        2,
        'transfer_in',
        89,
        918,
        'Transfer from Bonus Network',
        '{"exchange_id":"26799a86-3ab7-49c4-ab04-0956780a28d1","vendor_exchange":true,"from_provider_id":4,"from_provider_slug":"bonus-network","from_user_id":5,"original_points":200,"gross_value":100,"total_fee_percent":11,"total_fee_value":11,"net_value":89}',
        NULL,
        '2025-12-31 04:36:09',
        '2025-12-31 04:36:09'
    ),
    (
        55,
        2,
        1,
        'transfer_out',
        -457,
        1370,
        'Transfer to Bonus Network',
        '{"exchange_id":"fa155d9f-8f94-4aad-8e21-1f093048593c","vendor_exchange":true,"to_provider_id":4,"to_provider_slug":"bonus-network","to_user_id":5,"points_sent":457,"gross_value":45.7,"total_fee_percent":9,"total_fee_value":4.11,"net_value":41.59,"points_received":83}',
        NULL,
        '2025-12-31 04:37:05',
        '2025-12-31 04:37:05'
    ),
    (
        56,
        5,
        4,
        'transfer_in',
        83,
        1337,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"fa155d9f-8f94-4aad-8e21-1f093048593c","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":457,"gross_value":45.7,"total_fee_percent":9,"total_fee_value":4.11,"net_value":41.59}',
        NULL,
        '2025-12-31 04:37:05',
        '2025-12-31 04:37:05'
    ),
    (
        57,
        2,
        1,
        'transfer_out',
        -343,
        1027,
        'Transfer to Premium Rewards',
        '{"exchange_id":"56eda5b9-90ec-4eee-9a15-f8fbdb84e297","vendor_exchange":true,"to_provider_id":5,"to_provider_slug":"premium-rewards","to_user_id":5,"points_sent":343,"gross_value":34.3,"total_fee_percent":7.5,"total_fee_value":2.57,"net_value":31.73,"points_received":15}',
        NULL,
        '2025-12-31 04:38:36',
        '2025-12-31 04:38:36'
    ),
    (
        58,
        5,
        5,
        'transfer_in',
        15,
        1168,
        'Transfer from Loyalty Plus',
        '{"exchange_id":"56eda5b9-90ec-4eee-9a15-f8fbdb84e297","vendor_exchange":true,"from_provider_id":1,"from_provider_slug":"loyalty-plus","from_user_id":2,"original_points":343,"gross_value":34.3,"total_fee_percent":7.5,"total_fee_value":2.57,"net_value":31.73}',
        NULL,
        '2025-12-31 04:38:36',
        '2025-12-31 04:38:36'
    ),
    (
        59,
        2,
        2,
        'transfer_out',
        -230,
        688,
        'Transfer to Premium Rewards',
        '{"exchange_id":"bad629ad-f4ee-454a-a5f1-e869e338afd4","vendor_exchange":true,"to_provider_id":5,"to_provider_slug":"premium-rewards","to_user_id":5,"points_sent":230,"gross_value":230,"total_fee_percent":9.5,"total_fee_value":21.85,"net_value":208.15,"points_received":104}',
        NULL,
        '2025-12-31 04:44:28',
        '2025-12-31 04:44:28'
    ),
    (
        60,
        5,
        5,
        'transfer_in',
        104,
        1272,
        'Transfer from Rewards Hub',
        '{"exchange_id":"bad629ad-f4ee-454a-a5f1-e869e338afd4","vendor_exchange":true,"from_provider_id":2,"from_provider_slug":"rewards-hub","from_user_id":2,"original_points":230,"gross_value":230,"total_fee_percent":9.5,"total_fee_value":21.85,"net_value":208.15}',
        NULL,
        '2025-12-31 04:44:28',
        '2025-12-31 04:44:28'
    ),
    (
        61,
        5,
        4,
        'transfer_out',
        -334,
        1003,
        'Transfer to Points Express',
        '{"exchange_id":"6d973ba0-8522-4a9f-8f33-d3f7c5ff32dd","vendor_exchange":true,"to_provider_id":3,"to_provider_slug":"points-express","to_user_id":7,"points_sent":334,"gross_value":167,"total_fee_percent":9.5,"total_fee_value":15.87,"net_value":151.13,"points_received":15113}',
        NULL,
        '2025-12-31 04:52:20',
        '2025-12-31 04:52:20'
    ),
    (
        62,
        7,
        3,
        'transfer_in',
        15113,
        15613,
        'Transfer from Bonus Network',
        '{"exchange_id":"6d973ba0-8522-4a9f-8f33-d3f7c5ff32dd","vendor_exchange":true,"from_provider_id":4,"from_provider_slug":"bonus-network","from_user_id":5,"original_points":334,"gross_value":167,"total_fee_percent":9.5,"total_fee_value":15.87,"net_value":151.13}',
        NULL,
        '2025-12-31 04:52:20',
        '2025-12-31 04:52:20'
    ),
    (
        63,
        7,
        3,
        'transfer_out',
        -3903,
        11710,
        'Transfer to Rewards Hub',
        '{"exchange_id":"8008a0c6-e876-48de-9fb7-888bc54391ee","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":3903,"gross_value":39.03,"total_fee_percent":10.5,"total_fee_value":4.1,"net_value":34.93,"points_received":34}',
        NULL,
        '2025-12-31 05:18:54',
        '2025-12-31 05:18:54'
    ),
    (
        64,
        2,
        2,
        'transfer_in',
        34,
        722,
        'Transfer from Points Express',
        '{"exchange_id":"8008a0c6-e876-48de-9fb7-888bc54391ee","vendor_exchange":true,"from_provider_id":3,"from_provider_slug":"points-express","from_user_id":7,"original_points":3903,"gross_value":39.03,"total_fee_percent":10.5,"total_fee_value":4.1,"net_value":34.93}',
        NULL,
        '2025-12-31 05:18:54',
        '2025-12-31 05:18:54'
    ),
    (
        65,
        7,
        3,
        'transfer_out',
        -2928,
        8782,
        'Transfer to Loyalty Plus',
        '{"exchange_id":"2eb5d1f4-45d7-48ff-ba4f-ff40db505223","vendor_exchange":true,"to_provider_id":1,"to_provider_slug":"loyalty-plus","to_user_id":2,"points_sent":2928,"gross_value":29.28,"total_fee_percent":8.5,"total_fee_value":2.49,"net_value":26.79,"points_received":267}',
        NULL,
        '2025-12-31 05:25:21',
        '2025-12-31 05:25:21'
    ),
    (
        66,
        2,
        1,
        'transfer_in',
        267,
        1294,
        'Transfer from Points Express',
        '{"exchange_id":"2eb5d1f4-45d7-48ff-ba4f-ff40db505223","vendor_exchange":true,"from_provider_id":3,"from_provider_slug":"points-express","from_user_id":7,"original_points":2928,"gross_value":29.28,"total_fee_percent":8.5,"total_fee_value":2.49,"net_value":26.79}',
        NULL,
        '2025-12-31 05:25:21',
        '2025-12-31 05:25:21'
    ),
    (
        67,
        7,
        3,
        'transfer_out',
        -2196,
        6586,
        'Transfer to Rewards Hub',
        '{"exchange_id":"63f90215-ba94-445c-aecc-e59cf14c7136","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":2196,"gross_value":21.96,"total_fee_percent":10.5,"total_fee_value":2.31,"net_value":19.65,"points_received":19}',
        NULL,
        '2025-12-31 05:26:02',
        '2025-12-31 05:26:02'
    ),
    (
        68,
        2,
        2,
        'transfer_in',
        19,
        741,
        'Transfer from Points Express',
        '{"exchange_id":"63f90215-ba94-445c-aecc-e59cf14c7136","vendor_exchange":true,"from_provider_id":3,"from_provider_slug":"points-express","from_user_id":7,"original_points":2196,"gross_value":21.96,"total_fee_percent":10.5,"total_fee_value":2.31,"net_value":19.65}',
        NULL,
        '2025-12-31 05:26:02',
        '2025-12-31 05:26:02'
    ),
    (
        69,
        5,
        5,
        'transfer_out',
        -318,
        954,
        'Transfer to Rewards Hub',
        '{"exchange_id":"5c652d1a-f4b9-44a7-8604-f68b56277831","vendor_exchange":true,"to_provider_id":2,"to_provider_slug":"rewards-hub","to_user_id":2,"points_sent":318,"gross_value":636,"total_fee_percent":9.5,"total_fee_value":60.42,"net_value":575.58,"points_received":575}',
        NULL,
        '2025-12-31 05:31:41',
        '2025-12-31 05:31:41'
    ),
    (
        70,
        2,
        2,
        'transfer_in',
        575,
        1316,
        'Transfer from Premium Rewards',
        '{"exchange_id":"5c652d1a-f4b9-44a7-8604-f68b56277831","vendor_exchange":true,"from_provider_id":5,"from_provider_slug":"premium-rewards","from_user_id":5,"original_points":318,"gross_value":636,"total_fee_percent":9.5,"total_fee_value":60.42,"net_value":575.58}',
        NULL,
        '2025-12-31 05:31:41',
        '2025-12-31 05:31:41'
    );

INSERT INTO
    `providers` (
        `id`,
        `name`,
        `trade_name`,
        `slug`,
        `category`,
        `description`,
        `official_logo`,
        `web_link`,
        `is_active`,
        `points_to_value_ratio`,
        `transfer_fee_percent`,
        `metadata`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        'Loyalty Plus',
        'Loyalty+',
        'loyalty-plus',
        'retail',
        'Earn points on every purchase at partner retail stores.',
        'https://example.com/logos/loyalty-plus.png',
        'https://loyaltyplus.example.com',
        1,
        0.1000,
        1.50,
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        2,
        'Rewards Hub',
        'RewardsHub',
        'rewards-hub',
        'travel',
        'Collect and redeem points for travel and experiences.',
        'https://example.com/logos/rewards-hub.png',
        'https://rewardshub.example.com',
        1,
        1.0000,
        3.50,
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        3,
        'Points Express',
        'PointsX',
        'points-express',
        'dining',
        'Earn points at participating restaurants and cafes.',
        'https://example.com/logos/points-express.png',
        'https://pointsexpress.example.com',
        1,
        0.0100,
        2.00,
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        4,
        'Bonus Network',
        'BonusNet',
        'bonus-network',
        'entertainment',
        'Earn bonus points on entertainment and gaming purchases.',
        'https://example.com/logos/bonus-network.png',
        'https://bonusnetwork.example.com',
        1,
        0.5000,
        2.50,
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    ),
    (
        5,
        'Premium Rewards',
        'PremiumR',
        'premium-rewards',
        'luxury',
        'Exclusive rewards program for premium members.',
        'https://example.com/logos/premium-rewards.png',
        'https://premiumrewards.example.com',
        1,
        2.0000,
        1.00,
        NULL,
        '2025-12-24 09:03:08',
        '2025-12-24 09:03:08'
    );

INSERT INTO
    `otps` (
        `id`,
        `user_id`,
        `code`,
        `purpose`,
        `expires_at`,
        `verified_at`,
        `attempts`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        2,
        '986433',
        'vendor_auth',
        '2025-12-24 09:21:21',
        '2025-12-24 09:11:35',
        1,
        '2025-12-24 09:11:21',
        '2025-12-24 09:11:35'
    ),
    (
        2,
        2,
        '961402',
        'vendor_auth',
        '2025-12-24 09:35:10',
        '2025-12-24 09:25:56',
        1,
        '2025-12-24 09:25:10',
        '2025-12-24 09:25:56'
    ),
    (
        3,
        2,
        '423986',
        'vendor_auth',
        '2025-12-24 09:36:30',
        '2025-12-24 09:26:44',
        1,
        '2025-12-24 09:26:30',
        '2025-12-24 09:26:44'
    ),
    (
        4,
        2,
        '325407',
        'vendor_auth',
        '2025-12-24 09:41:53',
        '2025-12-24 09:32:20',
        1,
        '2025-12-24 09:31:53',
        '2025-12-24 09:32:20'
    ),
    (
        5,
        2,
        '924310',
        'vendor_auth',
        '2025-12-24 09:57:07',
        '2025-12-24 09:47:32',
        1,
        '2025-12-24 09:47:07',
        '2025-12-24 09:47:32'
    ),
    (
        6,
        5,
        '282902',
        'vendor_auth',
        '2025-12-24 10:15:19',
        '2025-12-24 10:05:33',
        1,
        '2025-12-24 10:05:19',
        '2025-12-24 10:05:33'
    ),
    (
        7,
        5,
        '483302',
        'vendor_auth',
        '2025-12-24 10:18:53',
        '2025-12-24 10:09:06',
        1,
        '2025-12-24 10:08:53',
        '2025-12-24 10:09:06'
    ),
    (
        8,
        5,
        '759495',
        'vendor_auth',
        '2025-12-24 10:20:38',
        '2025-12-24 10:10:57',
        1,
        '2025-12-24 10:10:38',
        '2025-12-24 10:10:57'
    ),
    (
        9,
        2,
        '475786',
        'vendor_auth',
        '2025-12-24 15:19:11',
        NULL,
        0,
        '2025-12-24 15:09:11',
        '2025-12-24 15:09:11'
    ),
    (
        10,
        2,
        '619184',
        'vendor_auth',
        '2025-12-24 17:17:11',
        NULL,
        0,
        '2025-12-24 17:15:26',
        '2025-12-24 17:17:11'
    ),
    (
        11,
        2,
        '013855',
        'vendor_auth',
        '2025-12-24 17:21:39',
        NULL,
        0,
        '2025-12-24 17:17:11',
        '2025-12-24 17:21:39'
    ),
    (
        12,
        2,
        '911452',
        'vendor_auth',
        '2025-12-24 17:31:39',
        '2025-12-24 17:25:45',
        1,
        '2025-12-24 17:21:39',
        '2025-12-24 17:25:45'
    ),
    (
        13,
        2,
        '800607',
        'vendor_auth',
        '2025-12-25 08:51:53',
        '2025-12-25 08:42:22',
        1,
        '2025-12-25 08:41:53',
        '2025-12-25 08:42:22'
    ),
    (
        14,
        2,
        '813473',
        'vendor_auth',
        '2025-12-25 09:53:55',
        '2025-12-25 09:44:07',
        1,
        '2025-12-25 09:43:55',
        '2025-12-25 09:44:07'
    ),
    (
        15,
        4,
        '854054',
        'vendor_auth',
        '2025-12-25 11:14:47',
        '2025-12-25 11:06:08',
        1,
        '2025-12-25 11:04:47',
        '2025-12-25 11:06:08'
    ),
    (
        16,
        4,
        '756456',
        'vendor_auth',
        '2025-12-25 11:16:43',
        '2025-12-25 11:07:00',
        1,
        '2025-12-25 11:06:43',
        '2025-12-25 11:07:00'
    ),
    (
        17,
        2,
        '100034',
        'vendor_auth',
        '2025-12-26 04:17:17',
        '2025-12-26 04:07:45',
        1,
        '2025-12-26 04:07:17',
        '2025-12-26 04:07:45'
    ),
    (
        18,
        2,
        '332259',
        'vendor_auth',
        '2025-12-26 06:20:52',
        '2025-12-26 06:11:16',
        1,
        '2025-12-26 06:10:52',
        '2025-12-26 06:11:16'
    ),
    (
        19,
        2,
        '722708',
        'vendor_auth',
        '2025-12-26 06:25:00',
        '2025-12-26 06:15:13',
        1,
        '2025-12-26 06:15:00',
        '2025-12-26 06:15:13'
    ),
    (
        20,
        2,
        '016519',
        'vendor_auth',
        '2025-12-26 07:21:38',
        '2025-12-26 07:11:55',
        1,
        '2025-12-26 07:11:38',
        '2025-12-26 07:11:55'
    ),
    (
        21,
        2,
        '442323',
        'vendor_auth',
        '2025-12-26 07:28:29',
        '2025-12-26 07:19:09',
        1,
        '2025-12-26 07:18:29',
        '2025-12-26 07:19:09'
    ),
    (
        22,
        2,
        '778434',
        'vendor_auth',
        '2025-12-26 08:10:35',
        '2025-12-26 08:01:08',
        1,
        '2025-12-26 08:00:35',
        '2025-12-26 08:01:08'
    ),
    (
        23,
        2,
        '724936',
        'vendor_auth',
        '2025-12-26 08:15:06',
        '2025-12-26 08:05:22',
        1,
        '2025-12-26 08:05:06',
        '2025-12-26 08:05:22'
    ),
    (
        24,
        2,
        '172158',
        'vendor_auth',
        '2025-12-26 08:21:00',
        '2025-12-26 08:11:14',
        1,
        '2025-12-26 08:11:00',
        '2025-12-26 08:11:14'
    ),
    (
        25,
        2,
        '817681',
        'vendor_auth',
        '2025-12-26 08:41:59',
        '2025-12-26 08:32:32',
        1,
        '2025-12-26 08:31:59',
        '2025-12-26 08:32:32'
    ),
    (
        26,
        2,
        '063130',
        'vendor_auth',
        '2025-12-26 08:43:28',
        '2025-12-26 08:33:40',
        1,
        '2025-12-26 08:33:28',
        '2025-12-26 08:33:40'
    ),
    (
        27,
        2,
        '981110',
        'vendor_auth',
        '2025-12-26 08:47:24',
        '2025-12-26 08:37:42',
        1,
        '2025-12-26 08:37:24',
        '2025-12-26 08:37:42'
    ),
    (
        28,
        2,
        '734336',
        'vendor_auth',
        '2025-12-26 10:12:17',
        '2025-12-26 10:02:35',
        1,
        '2025-12-26 10:02:17',
        '2025-12-26 10:02:35'
    ),
    (
        29,
        2,
        '143584',
        'vendor_auth',
        '2025-12-26 10:41:36',
        '2025-12-26 10:31:55',
        1,
        '2025-12-26 10:31:36',
        '2025-12-26 10:31:55'
    ),
    (
        30,
        2,
        '045451',
        'vendor_auth',
        '2025-12-26 11:42:17',
        '2025-12-26 11:32:54',
        2,
        '2025-12-26 11:32:17',
        '2025-12-26 11:32:54'
    ),
    (
        31,
        2,
        '706058',
        'vendor_auth',
        '2025-12-26 12:07:01',
        '2025-12-26 11:57:21',
        1,
        '2025-12-26 11:57:01',
        '2025-12-26 11:57:21'
    ),
    (
        32,
        2,
        '802899',
        'vendor_auth',
        '2025-12-26 12:43:28',
        '2025-12-26 12:33:46',
        1,
        '2025-12-26 12:33:28',
        '2025-12-26 12:33:46'
    ),
    (
        33,
        2,
        '918625',
        'vendor_auth',
        '2025-12-26 16:42:30',
        '2025-12-26 16:32:50',
        1,
        '2025-12-26 16:32:30',
        '2025-12-26 16:32:50'
    ),
    (
        34,
        2,
        '169741',
        'vendor_auth',
        '2025-12-26 18:25:39',
        '2025-12-26 18:16:21',
        2,
        '2025-12-26 18:15:39',
        '2025-12-26 18:16:21'
    ),
    (
        35,
        2,
        '463497',
        'vendor_auth',
        '2025-12-28 09:02:31',
        '2025-12-28 08:53:13',
        1,
        '2025-12-28 08:52:31',
        '2025-12-28 08:53:13'
    ),
    (
        36,
        2,
        '112053',
        'vendor_auth',
        '2025-12-28 09:42:20',
        '2025-12-28 09:32:38',
        1,
        '2025-12-28 09:32:20',
        '2025-12-28 09:32:38'
    ),
    (
        37,
        2,
        '801800',
        'vendor_auth',
        '2025-12-28 09:58:39',
        '2025-12-28 09:48:54',
        1,
        '2025-12-28 09:48:39',
        '2025-12-28 09:48:54'
    ),
    (
        38,
        2,
        '458245',
        'vendor_auth',
        '2025-12-28 11:14:36',
        '2025-12-28 11:05:17',
        3,
        '2025-12-28 11:04:36',
        '2025-12-28 11:05:17'
    ),
    (
        39,
        2,
        '780354',
        'vendor_auth',
        '2025-12-28 11:50:50',
        '2025-12-28 11:41:15',
        1,
        '2025-12-28 11:40:50',
        '2025-12-28 11:41:15'
    ),
    (
        40,
        4,
        '121759',
        'vendor_auth',
        '2025-12-28 11:54:32',
        '2025-12-28 11:44:58',
        1,
        '2025-12-28 11:44:32',
        '2025-12-28 11:44:58'
    ),
    (
        41,
        2,
        '942615',
        'vendor_auth',
        '2025-12-30 19:58:33',
        '2025-12-30 19:49:21',
        1,
        '2025-12-30 19:48:33',
        '2025-12-30 19:49:21'
    ),
    (
        42,
        2,
        '483020',
        'vendor_auth',
        '2025-12-31 04:21:15',
        '2025-12-31 04:11:37',
        1,
        '2025-12-31 04:11:15',
        '2025-12-31 04:11:37'
    ),
    (
        43,
        7,
        '847575',
        'vendor_auth',
        '2025-12-31 04:58:57',
        '2025-12-31 04:49:11',
        1,
        '2025-12-31 04:48:57',
        '2025-12-31 04:49:11'
    ),
    (
        44,
        2,
        '311807',
        'vendor_auth',
        '2025-12-31 05:12:07',
        '2025-12-31 05:02:49',
        1,
        '2025-12-31 05:02:07',
        '2025-12-31 05:02:49'
    );

INSERT INTO
    `user_provider_balances` (
        `id`,
        `user_id`,
        `provider_id`,
        `balance`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        1,
        5,
        4,
        1003,
        '2025-12-24 09:03:09',
        '2025-12-31 04:52:20'
    ),
    (
        2,
        3,
        2,
        1075,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        3,
        4,
        3,
        800,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        4,
        2,
        1,
        1294,
        '2025-12-24 09:03:09',
        '2025-12-31 05:25:21'
    ),
    (
        5,
        2,
        2,
        1316,
        '2025-12-24 09:03:09',
        '2025-12-31 05:31:41'
    ),
    (
        6,
        6,
        1,
        350,
        '2025-12-24 09:03:09',
        '2025-12-24 09:03:09'
    ),
    (
        7,
        5,
        5,
        954,
        '2025-12-24 09:03:09',
        '2025-12-31 05:31:41'
    ),
    (
        8,
        7,
        3,
        6586,
        '2025-12-31 04:48:43',
        '2025-12-31 05:26:02'
    );

INSERT INTO
    `vendor_user_links` (
        `id`,
        `user_id`,
        `provider_id`,
        `vendor_email`,
        `linked_at`,
        `created_at`,
        `updated_at`
    )
VALUES
    (
        2,
        2,
        1,
        'erastuskirui01@gmail.com',
        '2025-12-24 09:25:56',
        '2025-12-24 09:25:56',
        '2025-12-24 09:25:56'
    ),
    (
        3,
        2,
        2,
        'erastuskirui01@gmail.com',
        '2025-12-24 09:26:44',
        '2025-12-24 09:26:44',
        '2025-12-24 09:26:44'
    ),
    (
        4,
        5,
        4,
        'erastuskirui01@gmail.com',
        '2025-12-24 10:09:06',
        '2025-12-24 10:09:06',
        '2025-12-24 10:09:06'
    ),
    (
        5,
        5,
        5,
        'erastuskirui01@gmail.com',
        '2025-12-24 10:10:57',
        '2025-12-24 10:10:57',
        '2025-12-24 10:10:57'
    ),
    (
        6,
        4,
        3,
        'aminuhussain22@gmail.com',
        '2025-12-25 11:06:09',
        '2025-12-25 11:06:09',
        '2025-12-25 11:06:09'
    ),
    (
        7,
        7,
        3,
        'erastuskirui01@gmail.com',
        '2025-12-31 04:49:12',
        '2025-12-31 04:49:12',
        '2025-12-31 04:49:12'
    );

-- Indices
CREATE INDEX `sessions_user_id_index` ON `sessions` (`user_id`);

CREATE INDEX `sessions_last_activity_index` ON `sessions` (`last_activity`);

-- Indices
CREATE INDEX `jobs_queue_index` ON `jobs` (`queue`);

-- Indices
CREATE UNIQUE INDEX `failed_jobs_uuid_unique` ON `failed_jobs` (`uuid`);

-- Indices
CREATE UNIQUE INDEX `users_email_unique` ON `users` (`email`);

-- Indices
CREATE INDEX `personal_access_tokens_tokenable_type_tokenable_id_index` ON `personal_access_tokens` (`tokenable_type`, `tokenable_id`);

CREATE UNIQUE INDEX `personal_access_tokens_token_unique` ON `personal_access_tokens` (`token`);

CREATE INDEX `personal_access_tokens_expires_at_index` ON `personal_access_tokens` (`expires_at`);

-- Indices
CREATE INDEX `point_transactions_user_id_provider_id_created_at_index` ON `point_transactions` (`user_id`, `provider_id`, `created_at`);

CREATE INDEX `point_transactions_expires_at_index` ON `point_transactions` (`expires_at`);

-- Indices
CREATE INDEX `providers_is_active_index` ON `providers` (`is_active`);

CREATE INDEX `providers_category_index` ON `providers` (`category`);

CREATE UNIQUE INDEX `providers_slug_unique` ON `providers` (`slug`);

-- Indices
CREATE INDEX `otps_user_id_code_purpose_index` ON `otps` (`user_id`, `code`, `purpose`);

CREATE INDEX `otps_expires_at_index` ON `otps` (`expires_at`);

-- Indices
CREATE UNIQUE INDEX `user_provider_balances_user_id_provider_id_unique` ON `user_provider_balances` (`user_id`, `provider_id`);

-- Indices
CREATE UNIQUE INDEX `vendor_user_links_vendor_email_provider_id_unique` ON `vendor_user_links` (`vendor_email`, `provider_id`);

CREATE INDEX `vendor_user_links_user_id_provider_id_index` ON `vendor_user_links` (`user_id`, `provider_id`);

-- Foreign Keys
ALTER TABLE `point_transactions` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `point_transactions` ADD FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

ALTER TABLE `otps` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_provider_balances` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `user_provider_balances` ADD FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

ALTER TABLE `vendor_user_links` ADD FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `vendor_user_links` ADD FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

SET
    FOREIGN_KEY_CHECKS = 1;