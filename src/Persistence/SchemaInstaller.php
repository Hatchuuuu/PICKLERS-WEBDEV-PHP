<?php
declare(strict_types=1);

namespace Picklers\Persistence;

/**
 * Creates and upgrades the base PICKLERS schema and its seed data (Doctrine migrations then add the
 * admin console's tables on top). Runs once per process: on the first request, or `picklers:schema:install`.
 */
final class SchemaInstaller
{
    private bool $ensured = false;

    /** Create or upgrade the schema and seed it; cheap after the first call in a process. */
    public function ensure(): void
    {
        if ($this->ensured) {
            return;
        }
        $this->ensured = true;
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0750, true);
        }
        $this->initialiseSchema();
        $this->seedInitialData();
    }

    private readonly \Doctrine\DBAL\Connection $db;
    private readonly \Picklers\Repositories\PromoRepository $promos;
    private readonly string $dataDir;

    public function __construct(
        ?\Doctrine\DBAL\Connection $db = null,
        ?\Picklers\Repositories\PromoRepository $promos = null,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%picklers.data_dir%')]
        ?string $dataDir = null,
    ) {
        $this->db = $db ?? \Picklers\Core\Database::get()->dbal();
        $this->promos = $promos ?? new \Picklers\Repositories\PromoRepository($this->db);
        $this->dataDir = $dataDir ?? (defined('DATA_PATH') ? DATA_PATH : (getcwd() . '/database/.data'));
    }

    private bool $schemaInitialisedThisBoot = false;

    /**
     * Schema work is expensive (13 DDL round-trips) and DDL causes an implicit
     * commit in MySQL, so it must not run on every request: a stamp file records
     * the schema version already applied.
     */
    private function initialiseSchema(): void {
        if ($this->schemaNeedsInit()) {
            $this->initMySQLSchema();
            $this->markSchemaInitialised();
            $this->schemaInitialisedThisBoot = true;
        } else {
            $this->applyPendingMigrations();
        }
    }

    private function schemaStampPath(): string {
        return $this->dataDir . '/.schema_version';
    }

    /** Current schema revision — bump when initMySQLSchema()/migrations change. */
    private const SCHEMA_VERSION = '8';

    /** Tables initMySQLSchema() creates; all must exist for a stamp to be trusted. */
    public const REQUIRED_TABLES = [
        'users', 'facilities', 'courts', 'staff', 'matches', 'bookings', 'wallet_transactions',
        'feed_posts', 'feed_likes', 'facility_favorites', 'feed_comments', 'direct_messages',
        'notifications', 'owner_applications', 'promo_codes', 'promo_redemptions', 'court_images',
        'amenities', 'facility_amenities', 'sync_versions', 'court_attributes', 'court_attribute_map',
        'payout_requests',
    ];

    private function schemaNeedsInit(): bool {
        $stamp = $this->schemaStampPath();
        $stampVersion = file_exists($stamp) ? trim((string)@file_get_contents($stamp)) : null;
        // The stamp file lives in a directory, not in the database, so it can
        // outlive the tables it describes (a test database dropped and
        // recreated, a different database pointed at the same directory). It
        // is only trusted when the tables it vouches for are really there.
        return !self::schemaStampIsTrustworthy($stampVersion, $this->existingTables());
    }

    /**
     * Pure decision used by schemaNeedsInit(), kept static so the fresh and
     * stale-stamp cases can be tested without a live connection.
     *
     * @param string[] $existingTables
     */
    public static function schemaStampIsTrustworthy(?string $stampVersion, array $existingTables): bool {
        if ($stampVersion !== self::SCHEMA_VERSION) {
            return false;
        }
        $present = array_flip(array_map('strtolower', $existingTables));
        foreach (self::REQUIRED_TABLES as $table) {
            if (!isset($present[$table])) {
                return false;
            }
        }
        return true;
    }

    /** @return string[] Base tables in the connected database. */
    private function existingTables(): array {
        try {
            $stmt = $this->db->executeQuery(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            );
            return $stmt ? array_map('strval', $stmt->fetchFirstColumn()) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function markSchemaInitialised(): void {
        @file_put_contents($this->schemaStampPath(), self::SCHEMA_VERSION);
    }

    private function initMySQLSchema() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS users (
                id VARCHAR(64) PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(120) UNIQUE,
                phone VARCHAR(32),
                password_hash VARCHAR(255),
                role VARCHAR(20) DEFAULT 'player',
                verification_status VARCHAR(20) DEFAULT 'unverified',
                avatar_url TEXT,
                level VARCHAR(20) DEFAULT 'Player',
                gold INT DEFAULT 0,
                silver INT DEFAULT 0,
                bronze INT DEFAULT 0,
                wallet_balance DECIMAL(10,2) DEFAULT 0.00,
                is_admin TINYINT(1) DEFAULT 0,
                is_dev TINYINT(1) DEFAULT 0,
                is_owner TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_users_role (role),
                INDEX idx_users_owner (is_owner)
            )",
            "CREATE TABLE IF NOT EXISTS facilities (
                id INT PRIMARY KEY AUTO_INCREMENT,
                owner_id VARCHAR(64) NULL,
                name VARCHAR(150) NOT NULL,
                location VARCHAR(200) NOT NULL,
                rating DECIMAL(2,1) DEFAULT 4.8,
                reviews INT DEFAULT 100,
                price VARCHAR(50) DEFAULT '₱400',
                price_numeric DECIMAL(10,2) DEFAULT 400.00,
                type VARCHAR(50) DEFAULT 'Indoor · Cushion',
                hours VARCHAR(50) DEFAULT '6am - 11pm',
                transit VARCHAR(100) DEFAULT '🏍 5 min · 🚗 10 min',
                image TEXT,
                courts_count INT DEFAULT 4,
                is_verified TINYINT(1) DEFAULT 1,
                -- Payout destination + accepted-methods, set from the owner
                -- Settings tab. Previously these five fields were saved to
                -- localStorage only (see saveFacilitySettings() in owner.js) —
                -- never reached the server, so a different device/browser (or
                -- clearing site data) silently lost them, and nothing else in
                -- the app could ever actually read them.
                gcash_number VARCHAR(20) NULL,
                maya_number VARCHAR(20) NULL,
                gcash_enabled TINYINT(1) DEFAULT 1,
                maya_enabled TINYINT(1) DEFAULT 1,
                cash_on_site TINYINT(1) DEFAULT 1,
                INDEX idx_facility_owner (owner_id)
            )",
            "CREATE TABLE IF NOT EXISTS courts (
                id VARCHAR(64) PRIMARY KEY,
                facility_id INT,
                name VARCHAR(100),
                surface VARCHAR(50) DEFAULT 'Hard Court',
                type VARCHAR(50) DEFAULT 'Indoor',
                price DECIMAL(10,2) DEFAULT 450.00,
                status VARCHAR(20) DEFAULT 'available',
                occupied_by VARCHAR(100) NULL,
                occupied_until VARCHAR(50) NULL,
                INDEX idx_courts_facility (facility_id)
            )",
            "CREATE TABLE IF NOT EXISTS staff (
                id VARCHAR(64) PRIMARY KEY,
                facility_id INT,
                user_id VARCHAR(64) NULL,
                name VARCHAR(100),
                email VARCHAR(150),
                role VARCHAR(50) DEFAULT 'Front Desk',
                status VARCHAR(20) DEFAULT 'Active',
                date_joined VARCHAR(50),
                INDEX idx_staff_facility (facility_id),
                INDEX idx_staff_user (user_id)
            )",
            "CREATE TABLE IF NOT EXISTS matches (
                id VARCHAR(64) PRIMARY KEY,
                facility_id INT,
                facility_name VARCHAR(150),
                location VARCHAR(200),
                date VARCHAR(50),
                time VARCHAR(50),
                level VARCHAR(30) DEFAULT 'Intermediate',
                current_players INT DEFAULT 2,
                max_players INT DEFAULT 4,
                price DECIMAL(10,2) DEFAULT 150.00,
                type VARCHAR(60) DEFAULT 'Doubles Open Play',
                host VARCHAR(100) DEFAULT 'Coach Marco',
                title VARCHAR(150) NULL,
                INDEX idx_matches_facility (facility_id),
                INDEX idx_matches_level (level)
            )",
            "CREATE TABLE IF NOT EXISTS bookings (
                id VARCHAR(64) PRIMARY KEY,
                user_id VARCHAR(64),
                facility_id INT,
                court_id VARCHAR(64) NULL,
                match_id VARCHAR(64) NULL,
                facility_name VARCHAR(150),
                court_name VARCHAR(100),
                date VARCHAR(50),
                time VARCHAR(50),
                -- Canonical forms of date/time, resolved once at write time from the
                -- (often ambiguous — 'Today', 'Thu Sep 10 2026', 'Thu, Sep 10, 2026'
                -- have all been observed for literally the same real day) display
                -- strings above. `date`/`time` stay exactly as typed for display;
                -- these three back the slot lock and the availability endpoint, and
                -- are the reason two different spellings of the same day now
                -- actually collide instead of sailing past each other.
                booking_date DATE NULL,
                start_min SMALLINT UNSIGNED NULL,
                end_min SMALLINT UNSIGNED NULL,
                duration VARCHAR(30) DEFAULT '1 Hour',
                price DECIMAL(10,2) DEFAULT 450.00,
                payment_method VARCHAR(50) DEFAULT 'Pickle Credits',
                status VARCHAR(30) DEFAULT 'upcoming',
                is_new TINYINT(1) DEFAULT 1,
                -- Set when an owner ends a live session early. Status/price/time
                -- stay exactly as originally booked (the player paid for and was
                -- entitled to the full slot — this isn't a cancellation and
                -- carries no refund), but a booking flagged here is excluded
                -- from the 'currently occupying this court' time-window match,
                -- so the court frees up immediately instead of showing occupied
                -- until the original end time arrives regardless.
                ended_early TINYINT(1) DEFAULT 0,
                -- Set once the 'your time is up' real-time alert has actually
                -- been raised for this booking (see checkAndNotifySessionEnd()),
                -- so a player polling every ~12s doesn't get the alarm fired on
                -- every single tick after their session ends.
                end_alert_sent TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_booking_user (user_id),
                INDEX idx_booking_facility (facility_id),
                -- Backs the slot-collision SELECT ... FOR UPDATE in createBooking().
                -- Without it InnoDB gap-locks every booking for the facility, so two
                -- people booking different courts at the same venue serialise on
                -- each other. With it, the lock narrows to the exact slot.
                INDEX idx_booking_slot (facility_id, court_name, date, time, status),
                -- The lock actually used by createBooking() since the canonical-date
                -- fix: scoped to the resolved calendar date, not the raw display
                -- string, so it narrows correctly regardless of which spelling the
                -- client sent for \"today\".
                INDEX idx_booking_date_lock (facility_id, booking_date, court_id, status)
            )",
            "CREATE TABLE IF NOT EXISTS wallet_transactions (
                id VARCHAR(64) PRIMARY KEY,
                user_id VARCHAR(64),
                type VARCHAR(20), -- credit or debit
                amount DECIMAL(10,2),
                label VARCHAR(150),
                date VARCHAR(50),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wallet_user (user_id),
                INDEX idx_wallet_user_created (user_id, created_at)
            )",
            "CREATE TABLE IF NOT EXISTS feed_posts (
                id VARCHAR(64) PRIMARY KEY,
                author_id VARCHAR(64),
                author_name VARCHAR(120),
                author_avatar TEXT,
                author_level VARCHAR(30),
                content TEXT,
                image_url TEXT NULL,
                post_type VARCHAR(30) DEFAULT 'text',
                like_count INT DEFAULT 0,
                comment_count INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_posts_created (created_at),
                INDEX idx_posts_author (author_id)
            )",
            "CREATE TABLE IF NOT EXISTS feed_likes (
                post_id VARCHAR(64),
                user_id VARCHAR(64),
                PRIMARY KEY (post_id, user_id),
                INDEX idx_likes_user (user_id)
            )",
            "CREATE TABLE IF NOT EXISTS facility_favorites (
                user_id VARCHAR(64),
                facility_id INT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, facility_id),
                INDEX idx_favorites_facility (facility_id)
            )",
            "CREATE TABLE IF NOT EXISTS feed_comments (
                id VARCHAR(64) PRIMARY KEY,
                post_id VARCHAR(64),
                user_id VARCHAR(64),
                author_name VARCHAR(120),
                author_avatar TEXT,
                comment TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_comments_post (post_id, created_at)
            )",
            "CREATE TABLE IF NOT EXISTS direct_messages (
                id VARCHAR(64) PRIMARY KEY,
                sender_id VARCHAR(64),
                receiver_id VARCHAR(64),
                content TEXT,
                is_read TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                -- Conversation lookup is bidirectional (sender OR receiver).
                INDEX idx_dm_thread (sender_id, receiver_id, created_at),
                INDEX idx_dm_receiver (receiver_id, is_read)
            )",
            "CREATE TABLE IF NOT EXISTS notifications (
                id VARCHAR(64) PRIMARY KEY,
                user_id VARCHAR(64),
                title VARCHAR(150),
                body TEXT,
                type VARCHAR(30) DEFAULT 'system',
                is_read TINYINT(1) DEFAULT 0,
                time VARCHAR(50) DEFAULT 'Just now',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                -- Read on EVERY action=me poll; without this it was a full scan.
                INDEX idx_notif_user (user_id, created_at),
                INDEX idx_notif_unread (user_id, is_read)
            )",
            "CREATE TABLE IF NOT EXISTS owner_applications (
                id VARCHAR(64) PRIMARY KEY,
                user_id VARCHAR(64),
                facility_name VARCHAR(150),
                address TEXT,
                latitude DECIMAL(10, 8),
                longitude DECIMAL(11, 8),
                courts_count INT DEFAULT 4,
                court_surface VARCHAR(60),
                operating_hours VARCHAR(100),
                owner_name VARCHAR(120),
                business_email VARCHAR(120),
                phone VARCHAR(50),
                entity_name VARCHAR(150),
                reg_number VARCHAR(100),
                permit_file TEXT NULL,
                gov_id_file TEXT NULL,
                status VARCHAR(50) DEFAULT 'pending_review',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_app_status (status, created_at),
                INDEX idx_app_user (user_id)
            )",
            "CREATE TABLE IF NOT EXISTS promo_codes (
                id VARCHAR(64) PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                discount_type VARCHAR(20) DEFAULT 'fixed',
                discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                min_spend DECIMAL(10,2) DEFAULT 0.00,
                max_discount DECIMAL(10,2) NULL,
                usage_limit INT DEFAULT 0,
                times_used INT DEFAULT 0,
                user_limit INT DEFAULT 1,
                expires_at DATETIME NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_promos_code (code),
                INDEX idx_promos_status (status)
            )",
            "CREATE TABLE IF NOT EXISTS promo_redemptions (
                id VARCHAR(64) PRIMARY KEY,
                promo_id VARCHAR(64) NOT NULL,
                promo_code VARCHAR(50) NOT NULL,
                user_id VARCHAR(64) NOT NULL,
                booking_id VARCHAR(64) NULL,
                discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_redemptions_promo (promo_id),
                INDEX idx_redemptions_user (user_id)
            )",
            "CREATE TABLE IF NOT EXISTS court_images (
                id INT PRIMARY KEY AUTO_INCREMENT,
                facility_id INT NOT NULL,
                url TEXT NOT NULL,
                alt_text VARCHAR(150) DEFAULT 'Court View',
                sort_order INT DEFAULT 0,
                is_primary TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_images_facility (facility_id)
            )",
            "CREATE TABLE IF NOT EXISTS amenities (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                icon VARCHAR(50) NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS facility_amenities (
                facility_id INT NOT NULL,
                amenity_id INT NOT NULL,
                PRIMARY KEY (facility_id, amenity_id),
                INDEX idx_fa_facility (facility_id)
            )",
            // Cheap, cache-friendly change signal. A client polls this tiny
            // endpoint and only re-fetches real data (a facility list, a
            // court list, a booking queue) when the version it already has
            // is stale — the same perceived immediacy as a push channel,
            // without holding a persistent connection per browser tab.
            "CREATE TABLE IF NOT EXISTS sync_versions (
                channel VARCHAR(64) PRIMARY KEY,
                version BIGINT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )",
            // Court-level relational tags (Indoor/Outdoor/Covered/Air
            // Conditioned/...), replacing the free-text facilities.type
            // string that could not represent one court being Indoor while
            // another at the same venue is Outdoor.
            "CREATE TABLE IF NOT EXISTS court_attributes (
                id INT PRIMARY KEY AUTO_INCREMENT,
                slug VARCHAR(40) NOT NULL UNIQUE,
                label VARCHAR(60) NOT NULL,
                icon VARCHAR(50) NOT NULL DEFAULT 'ph-tag',
                kind VARCHAR(20) NOT NULL DEFAULT 'feature',
                sort_order INT NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS court_attribute_map (
                court_id VARCHAR(64) NOT NULL,
                attribute_id INT NOT NULL,
                PRIMARY KEY (court_id, attribute_id),
                INDEX idx_cam_attribute (attribute_id)
            )",
            "CREATE TABLE IF NOT EXISTS payout_requests (
                id VARCHAR(64) PRIMARY KEY,
                owner_id VARCHAR(64) NOT NULL,
                facility_id VARCHAR(64) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                method VARCHAR(50) NOT NULL DEFAULT 'GCash',
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                notes TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_payout_owner (owner_id),
                INDEX idx_payout_facility (facility_id),
                INDEX idx_payout_status (status)
            )"
        ];

        foreach ($queries as $sql) {
            $this->db->executeStatement($sql);
        }

        $this->applyPendingMigrations();
    }

    /**
     * Idempotent forward migrations.
     *
     * CREATE TABLE IF NOT EXISTS silently does nothing on an existing database,
     * so any column or index added after a deployment would never appear on
     * environments that were provisioned earlier. Each statement here is written
     * to be safe to run repeatedly and is skipped when already applied.
     */
    private function applyPendingMigrations(): void {
        $migrations = [
            // Column additions (added after the initial schema shipped)
            ['type' => 'column', 'table' => 'matches', 'name' => 'title',
             'sql'  => "ALTER TABLE matches ADD COLUMN title VARCHAR(150) NULL"],

            // Court identity + canonical date/time on bookings (see the class
            // doc comment on createBooking() for why `date`/`time` alone were
            // never enough to prevent a real double-booking).
            ['type' => 'column', 'table' => 'bookings', 'name' => 'court_id',
             'sql'  => "ALTER TABLE bookings ADD COLUMN court_id VARCHAR(64) NULL AFTER facility_id"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'match_id',
             'sql'  => "ALTER TABLE bookings ADD COLUMN match_id VARCHAR(64) NULL AFTER court_id"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'booking_date',
             'sql'  => "ALTER TABLE bookings ADD COLUMN booking_date DATE NULL AFTER time"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'start_min',
             'sql'  => "ALTER TABLE bookings ADD COLUMN start_min SMALLINT UNSIGNED NULL AFTER booking_date"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'end_min',
             'sql'  => "ALTER TABLE bookings ADD COLUMN end_min SMALLINT UNSIGNED NULL AFTER start_min"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'ended_early',
             'sql'  => "ALTER TABLE bookings ADD COLUMN ended_early TINYINT(1) DEFAULT 0 AFTER is_new"],
            ['type' => 'column', 'table' => 'bookings', 'name' => 'end_alert_sent',
             'sql'  => "ALTER TABLE bookings ADD COLUMN end_alert_sent TINYINT(1) DEFAULT 0 AFTER ended_early"],
            ['type' => 'column', 'table' => 'facilities', 'name' => 'gcash_number',
             'sql'  => "ALTER TABLE facilities ADD COLUMN gcash_number VARCHAR(20) NULL"],
            ['type' => 'column', 'table' => 'facilities', 'name' => 'maya_number',
             'sql'  => "ALTER TABLE facilities ADD COLUMN maya_number VARCHAR(20) NULL"],
            ['type' => 'column', 'table' => 'facilities', 'name' => 'gcash_enabled',
             'sql'  => "ALTER TABLE facilities ADD COLUMN gcash_enabled TINYINT(1) DEFAULT 1"],
            ['type' => 'column', 'table' => 'facilities', 'name' => 'maya_enabled',
             'sql'  => "ALTER TABLE facilities ADD COLUMN maya_enabled TINYINT(1) DEFAULT 1"],
            ['type' => 'column', 'table' => 'facilities', 'name' => 'cash_on_site',
             'sql'  => "ALTER TABLE facilities ADD COLUMN cash_on_site TINYINT(1) DEFAULT 1"],

            ['type' => 'column', 'table' => 'staff', 'name' => 'user_id',
             'sql'  => "ALTER TABLE staff ADD COLUMN user_id VARCHAR(64) NULL AFTER facility_id"],

            // Indexes for tables that already existed before the index was added
            ['type' => 'index', 'table' => 'courts', 'name' => 'idx_courts_facility',
             'sql'  => "ALTER TABLE courts ADD INDEX idx_courts_facility (facility_id)"],
            ['type' => 'index', 'table' => 'staff', 'name' => 'idx_staff_facility',
             'sql'  => "ALTER TABLE staff ADD INDEX idx_staff_facility (facility_id)"],
            ['type' => 'index', 'table' => 'staff', 'name' => 'idx_staff_user',
             'sql'  => "ALTER TABLE staff ADD INDEX idx_staff_user (user_id)"],
            ['type' => 'index', 'table' => 'matches', 'name' => 'idx_matches_facility',
             'sql'  => "ALTER TABLE matches ADD INDEX idx_matches_facility (facility_id)"],
            ['type' => 'index', 'table' => 'bookings', 'name' => 'idx_booking_slot',
             'sql'  => "ALTER TABLE bookings ADD INDEX idx_booking_slot (facility_id, court_name, date, time, status)"],
            ['type' => 'index', 'table' => 'wallet_transactions', 'name' => 'idx_wallet_user',
             'sql'  => "ALTER TABLE wallet_transactions ADD INDEX idx_wallet_user (user_id)"],
            ['type' => 'index', 'table' => 'notifications', 'name' => 'idx_notif_user',
             'sql'  => "ALTER TABLE notifications ADD INDEX idx_notif_user (user_id, created_at)"],
            ['type' => 'index', 'table' => 'feed_comments', 'name' => 'idx_comments_post',
             'sql'  => "ALTER TABLE feed_comments ADD INDEX idx_comments_post (post_id, created_at)"],
            ['type' => 'index', 'table' => 'direct_messages', 'name' => 'idx_dm_thread',
             'sql'  => "ALTER TABLE direct_messages ADD INDEX idx_dm_thread (sender_id, receiver_id, created_at)"],
            ['type' => 'index', 'table' => 'owner_applications', 'name' => 'idx_app_status',
             'sql'  => "ALTER TABLE owner_applications ADD INDEX idx_app_status (status, created_at)"],
            ['type' => 'index', 'table' => 'bookings', 'name' => 'idx_booking_date_lock',
             'sql'  => "ALTER TABLE bookings ADD INDEX idx_booking_date_lock (facility_id, booking_date, court_id, status)"],
        ];

        foreach ($migrations as $m) {
            try {
                if ($m['type'] === 'column' && $this->columnExists($m['table'], $m['name'])) {
                    continue;
                }
                if ($m['type'] === 'index' && $this->indexExists($m['table'], $m['name'])) {
                    continue;
                }
                $this->db->executeStatement($m['sql']);
            } catch (\Throwable $e) {
                // A migration that cannot apply must never take the app down.
                error_log("[PICKLERS Migration] {$m['table']}.{$m['name']} skipped: " . $e->getMessage());
            }
        }

        // New tables added after the initial schema shipped: initMySQLSchema()'s
        // own CREATE TABLE list only ever runs once, on a brand-new database
        // (see schemaNeedsInit() in the constructor) — an already-provisioned
        // database at the current SCHEMA_VERSION never sees it again, so a
        // table added later needs its own CREATE TABLE IF NOT EXISTS here too.
        try {
            $this->db->executeStatement(
                "CREATE TABLE IF NOT EXISTS facility_favorites (
                    user_id VARCHAR(64),
                    facility_id INT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, facility_id),
                    INDEX idx_favorites_facility (facility_id)
                )"
            );
        } catch (\Throwable $e) {
            error_log('[PICKLERS Migration] facility_favorites table skipped: ' . $e->getMessage());
        }

        // Demo-data fixups for local development only. Seed fixtures never run
        // in production, and a dev account's password is set once, never reset.
        if (!self::isProductionEnv()) {
            try {
                $checkStmt = $this->db->executeQuery("SELECT COUNT(DISTINCT price) as distinct_prices FROM courts WHERE facility_id = 1");
                $row = $checkStmt ? $checkStmt->fetchAssociative() : null;
                if ($row && (int)($row['distinct_prices'] ?? 0) <= 1) {
                    $updates = [
                        "crt_1_1" => 250, "crt_1_2" => 280, "crt_1_3" => 300, "crt_1_4" => 320, "crt_1_5" => 350,
                        "crt_2_1" => 200, "crt_2_2" => 220, "crt_2_3" => 250, "crt_2_4" => 280, "crt_2_5" => 300,
                        "crt_3_1" => 220, "crt_3_2" => 250, "crt_3_3" => 280, "crt_3_4" => 310, "crt_3_5" => 340,
                        "crt_4_1" => 180, "crt_4_2" => 200, "crt_4_3" => 220, "crt_4_4" => 240, "crt_4_5" => 260,
                        "crt_5_1" => 200, "crt_5_2" => 230, "crt_5_3" => 260, "crt_5_4" => 280, "crt_5_5" => 300
                    ];
                    $uStmtSql = "UPDATE courts SET price = ? WHERE id = ?";
                    foreach ($updates as $cid => $cprice) {
                        $this->db->executeStatement($uStmtSql, [$cprice, $cid]);
                    }
                }
            } catch (\Throwable $e) {
                // Ignore if court table not existing yet
            }
        }

        // The catalog seeder existed but was never invoked, so any database
        // provisioned from scratch had no attribute rows to map courts onto.
        // INSERT IGNORE on the unique slug keeps this a no-op where present.
        $this->seedCourtAttributeCatalog();
        $this->backfillCourtAttributesFromLegacyFields();

        if (!self::isProductionEnv()) {
            try {
                $this->db->executeStatement(
                    "INSERT IGNORE INTO users (id, name, email, phone, password_hash, role, verification_status, level, wallet_balance, is_admin, is_dev, is_owner)
                     VALUES ('usr_dar', 'Dante Reyes', 'dar@gmail.com', '+63 917 555 4444', ?, 'player', 'verified', 'Intermediate 3.5', 2500.00, 0, 0, 0)",
                    [password_hash('picklers123', PASSWORD_DEFAULT)]
                );
            } catch (\Throwable $e) {
                error_log('[PICKLERS Migration] dev account seed skipped: ' . $e->getMessage());
            }
        }

        // One-time legacy data normalization. It rewrote every match, court
        // and booking row on every single request; the write paths
        // (insertCourt/updateCourt) have normalized names for a long time, so
        // this only ever has legacy rows to fix — once.
        $fixupStamp = $this->dataDir . '/.court_names_normalized';
        if (file_exists($fixupStamp)) {
            return;
        }

        try {
            $this->db->executeStatement("UPDATE notifications SET body = REPLACE(body, 'dar booked', 'Dante Reyes booked') WHERE body LIKE '%dar booked%'");
            $this->db->executeStatement("UPDATE notifications SET body = REPLACE(body, 'How test Arena', 'Flow Test Arena') WHERE body LIKE '%How test Arena%'");
            $this->db->executeStatement("UPDATE notifications SET title = 'New reservation 🎾' WHERE title LIKE '%Now reservation%'");

            // Ensure all matches, courts, and bookings strictly use numbered court names (Court 1, Court 2, Court 3...)
            $mRows = $this->db->executeQuery("SELECT id, type FROM matches")->fetchAllAssociative();
            $uMatchSql = "UPDATE matches SET type = ? WHERE id = ?";
            foreach ($mRows as $mr) {
                $origType = (string)($mr['type'] ?? '');
                $newType = \Picklers\Domain\Schedule::normalizeCourtName($origType);
                if ($newType !== $origType) {
                    $this->db->executeStatement($uMatchSql, [$newType, $mr['id']]);
                }
            }

            $cRows = $this->db->executeQuery("SELECT id, name FROM courts")->fetchAllAssociative();
            $uCourtSql = "UPDATE courts SET name = ? WHERE id = ?";
            foreach ($cRows as $cr) {
                $norm = \Picklers\Domain\Schedule::normalizeCourtName((string)($cr['name'] ?? ''));
                if ($norm !== ($cr['name'] ?? '')) {
                    $this->db->executeStatement($uCourtSql, [$norm, $cr['id']]);
                }
            }

            $bRows = $this->db->executeQuery("SELECT id, court_name FROM bookings")->fetchAllAssociative();
            $uBookingSql = "UPDATE bookings SET court_name = ? WHERE id = ?";
            foreach ($bRows as $br) {
                $norm = \Picklers\Domain\Schedule::normalizeCourtName((string)($br['court_name'] ?? ''));
                if ($norm !== ($br['court_name'] ?? '')) {
                    $this->db->executeStatement($uBookingSql, [$norm, $br['id']]);
                }
            }
            @file_put_contents($fixupStamp, date('c'));
        } catch (\Throwable $e) {
            error_log('[PICKLERS Migration] court name normalization skipped: ' . $e->getMessage());
        }
    }

    /** APP_ENV resolves to a live deployment (unset counts as production — fail closed). */
    public static function isProductionEnv(): bool {
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        return in_array($env, ['production', 'prod', 'live'], true);
    }

    /**
     * The fixed catalog of court-level tags. Idempotent (INSERT IGNORE on a
     * UNIQUE slug), so safe to run on every schema pass rather than gated
     * behind the one-time .seeded flag — this needs to reach the live
     * database too, which was seeded long before this table existed.
     */
    private function seedCourtAttributeCatalog(): void {
        $catalog = [
            ['slug' => 'indoor',          'label' => 'Indoor',            'icon' => 'ph-buildings', 'kind' => 'environment', 'sort_order' => 1],
            ['slug' => 'outdoor',         'label' => 'Outdoor',           'icon' => 'ph-sun',       'kind' => 'environment', 'sort_order' => 2],
            ['slug' => 'covered',         'label' => 'Covered',           'icon' => 'ph-umbrella',  'kind' => 'cover',       'sort_order' => 3],
            ['slug' => 'open-air',        'label' => 'Open Air',          'icon' => 'ph-wind',      'kind' => 'cover',       'sort_order' => 4],
            ['slug' => 'air-conditioned', 'label' => 'Air Conditioned',   'icon' => 'ph-snowflake', 'kind' => 'climate',     'sort_order' => 5],
            ['slug' => 'night-lighting',  'label' => 'Night Lighting',    'icon' => 'ph-lightbulb', 'kind' => 'feature',     'sort_order' => 6],
            ['slug' => 'show-court',      'label' => 'Show Court',        'icon' => 'ph-star',      'kind' => 'feature',     'sort_order' => 7],
        ];

        try {
            $stmtSql = "INSERT IGNORE INTO court_attributes (slug, label, icon, kind, sort_order) VALUES (?,?,?,?,?)";
            foreach ($catalog as $a) {
                $this->db->executeStatement($stmtSql, [$a['slug'], $a['label'], $a['icon'], $a['kind'], $a['sort_order']]);
            }
        } catch (\Throwable $e) {
            error_log('[PICKLERS Migration] seedCourtAttributeCatalog skipped: ' . $e->getMessage());
        }
    }

    /**
     * Best-effort tag assignment for every court that has none yet, derived
     * from the free-text columns (courts.type, courts.surface,
     * facilities.type) that used to be the ONLY place this information
     * lived — as a single string per facility, unable to express one court
     * being Indoor while another at the same venue is Outdoor (facility 6,
     * "Indoor / Outdoor", is exactly this case; facility 2 was typed
     * Outdoor while every one of its own courts is Indoor).
     *
     * This is a starting point for the owner to correct via the attribute
     * picker, not a claim of authority — it runs once per court (only rows
     * with zero existing tags are touched) and is safe to run on every
     * schema pass.
     */
    private function backfillCourtAttributesFromLegacyFields(): void {
        try {
            $untagged = $this->db->executeQuery(
                "SELECT c.id, c.type, c.surface, f.type AS facility_type
                   FROM courts c
                   JOIN facilities f ON f.id = c.facility_id
                  LEFT JOIN court_attribute_map m ON m.court_id = c.id
                  WHERE m.court_id IS NULL"
            )->fetchAllAssociative();
        } catch (\Throwable $e) {
            error_log('[PICKLERS Migration] backfillCourtAttributesFromLegacyFields read skipped: ' . $e->getMessage());
            return;
        }
        if (!$untagged) {
            return;
        }

        $slugIds = [];
        foreach ($this->db->executeQuery("SELECT id, slug FROM court_attributes")->fetchAllAssociative() as $row) {
            $slugIds[$row['slug']] = (int)$row['id'];
        }

        $insSql = "INSERT IGNORE INTO court_attribute_map (court_id, attribute_id) VALUES (?, ?)";

        foreach ($untagged as $c) {
            $slugs = self::deriveCourtAttributeSlugsFromLegacyFields(
                $c['type'] ?? null, $c['surface'] ?? null, $c['facility_type'] ?? null
            );
            foreach ($slugs as $slug) {
                if (isset($slugIds[$slug])) {
                    $this->db->executeStatement($insSql, [$c['id'], $slugIds[$slug]]);
                }
            }
        }
    }

    /**
     * Pure best-effort slug derivation for one court's legacy free-text
     * fields — split out from backfillCourtAttributesFromLegacyFields() so
     * it can be unit tested directly rather than only indirectly through a
     * schema migration.
     *
     * Environment (indoor/outdoor) comes ONLY from an actual location field
     * — the court's own $courtType, falling back to $facilityType when the
     * court's own is empty. $surface is deliberately EXCLUDED from that
     * check: a value like "Outdoor Acrylic" is a material/paint name, not a
     * location (courts.crt_1_4 in the live data is typed Indoor with surface
     * "Outdoor Acrylic" — treating that surface string as a location signal
     * tagged it Outdoor despite its own type column saying otherwise).
     * Climate/cover signals legitimately do live in $surface (a cushioned
     * surface is a real proxy for a climate-controlled facility here) and in
     * the facility's own free-text type.
     *
     * @return string[] slugs, always a subset of the fixed catalog
     */
    public static function deriveCourtAttributeSlugsFromLegacyFields(?string $courtType, ?string $surface, ?string $facilityType): array {
        $slugs = [];

        $envSource = trim((string)$courtType) !== '' ? (string)$courtType : (string)$facilityType;
        $envHaystack = strtolower($envSource);

        if (str_contains($envHaystack, 'outdoor')) {
            $slugs[] = 'outdoor';
        }
        if (str_contains($envHaystack, 'indoor') && !in_array('outdoor', $slugs, true)) {
            $slugs[] = 'indoor';
        }

        $featureHaystack = strtolower(((string)$surface) . ' ' . ((string)$facilityType));
        if (str_contains($featureHaystack, 'air condition') || str_contains($featureHaystack, 'cushion')) {
            $slugs[] = 'air-conditioned';
        }
        if (str_contains($featureHaystack, 'covered')) {
            $slugs[] = 'covered';
        }

        return array_values(array_unique($slugs));
    }

    private function columnExists(string $table, string $column): bool {
        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return (int)($stmt->fetchAssociative()['c'] ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool {
        $stmt = $this->db->executeQuery(
            "SELECT COUNT(*) c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?",
            [$table, $index]
        );
        return (int)($stmt->fetchAssociative()['c'] ?? 0) > 0;
    }

    private function seedInitialData() {
        // Never seed in production — real deployments use migrations, not demo fixtures.
        $env = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        if (in_array($env, ['production', 'prod', 'live'], true)) {
            return;
        }

        $seedFlag = $this->dataDir . '/.seeded';
        // Like the schema stamp, the flag can outlive the rows it describes; a
        // schema that had to be (re)created this boot is re-seeded. Every
        // section below is guarded by its own table being empty.
        if (file_exists($seedFlag) && !$this->schemaInitialisedThisBoot) {
            return;
        }

        // 1. Seed Users
        $defaultUsers = [
            // Dedicated Facility Owner Accounts
            [
                'id' => 'usr_owner_acourts',
                'name' => 'Alexander Cruz',
                'email' => 'alexander.cruz@acourtspickle.ph',
                'phone' => '+63 920 111 0001',
                'password_hash' => password_hash('acourts2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=300&auto=format&fit=crop',
                'level' => 'Advanced 4.5',
                'gold' => 35,
                'silver' => 18,
                'bronze' => 10,
                'wallet_balance' => 18500.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_picklepark',
                'name' => 'Patricia Santos',
                'email' => 'patricia.santos@picklepark.ph',
                'phone' => '+63 920 111 0002',
                'password_hash' => password_hash('picklepark2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=300&auto=format&fit=crop',
                'level' => 'Advanced 4.0',
                'gold' => 20,
                'silver' => 12,
                'bronze' => 5,
                'wallet_balance' => 12000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_incredoball',
                'name' => 'Ignacio Reyes',
                'email' => 'ignacio.reyes@incredoball.ph',
                'phone' => '+63 920 111 0003',
                'password_hash' => password_hash('incredoball2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?q=80&w=300&auto=format&fit=crop',
                'level' => 'Pro 5.0',
                'gold' => 50,
                'silver' => 25,
                'bronze' => 12,
                'wallet_balance' => 22000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_pickleballers',
                'name' => 'Bernard Villanueva',
                'email' => 'bernard.v@pickleballers.ph',
                'phone' => '+63 920 111 0004',
                'password_hash' => password_hash('pickleballers2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?q=80&w=300&auto=format&fit=crop',
                'level' => 'Intermediate 3.5',
                'gold' => 15,
                'silver' => 10,
                'bronze' => 8,
                'wallet_balance' => 9500.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_academy',
                'name' => 'Coach Daniel Teves',
                'email' => 'daniel.teves@dgteacademy.ph',
                'phone' => '+63 920 111 0005',
                'password_hash' => password_hash('dgteacademy2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=300&auto=format&fit=crop',
                'level' => 'Pro 5.0',
                'gold' => 60,
                'silver' => 30,
                'bronze' => 15,
                'wallet_balance' => 31000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_pikolan',
                'name' => 'Paolo Mendoza',
                'email' => 'paolo.mendoza@pikolan.ph',
                'phone' => '+63 920 111 0006',
                'password_hash' => password_hash('pikolan2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=300&auto=format&fit=crop',
                'level' => 'Advanced 4.0',
                'gold' => 25,
                'silver' => 15,
                'bronze' => 10,
                'wallet_balance' => 14000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_cebu',
                'name' => 'Carla Tan',
                'email' => 'carla.tan@cebupickle.ph',
                'phone' => '+63 920 111 0007',
                'password_hash' => password_hash('cebupickle2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1500648767791-00dcc994a43e?q=80&w=300&auto=format&fit=crop',
                'level' => 'Advanced 4.5',
                'gold' => 40,
                'silver' => 20,
                'bronze' => 12,
                'wallet_balance' => 25000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_owner_davao',
                'name' => 'David Flores',
                'email' => 'david.flores@davaopickle.ph',
                'phone' => '+63 920 111 0008',
                'password_hash' => password_hash('davaopickle2026', PASSWORD_DEFAULT),
                'role' => 'owner',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?q=80&w=300&auto=format&fit=crop',
                'level' => 'Advanced 4.0',
                'gold' => 18,
                'silver' => 12,
                'bronze' => 6,
                'wallet_balance' => 11000.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 1
            ],
            // Dev Master Admin & Player Accounts
            [
                'id' => 'usr_dev',
                'name' => 'Picklers Dev',
                'email' => 'picklersdev@gmail.com',
                'phone' => '+63 917 888 9999',
                'password_hash' => password_hash('picklers2026', PASSWORD_DEFAULT),
                'role' => 'player',
                'verification_status' => 'verified',
                'avatar_url' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=300&auto=format&fit=crop',
                'level' => 'Pro 5.0 (Dev)',
                'gold' => 99,
                'silver' => 50,
                'bronze' => 25,
                'wallet_balance' => 9999.00,
                'is_admin' => 1,
                'is_dev' => 1,
                'is_owner' => 1
            ],
            [
                'id' => 'usr_player',
                'name' => 'Picklers Player',
                'email' => 'playeraccount@gmail.com',
                'phone' => '+63 917 222 3333',
                'password_hash' => password_hash('picklers2026', PASSWORD_DEFAULT),
                'role' => 'player',
                'verification_status' => 'verified',
                'avatar_url' => '',
                'level' => 'Intermediate 3.5',
                'gold' => 12,
                'silver' => 8,
                'bronze' => 4,
                'wallet_balance' => 1500.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 0
            ],
            [
                'id' => 'usr_demo_player',
                'name' => 'Alex Mercer',
                'email' => 'demoaccount@gmail.com',
                'phone' => '+63 917 111 2222',
                'password_hash' => password_hash('picklers2026', PASSWORD_DEFAULT),
                'role' => 'player',
                'verification_status' => 'verified',
                'avatar_url' => '',
                'level' => 'Intermediate 3.5',
                'gold' => 14,
                'silver' => 9,
                'bronze' => 6,
                'wallet_balance' => 3800.00,
                'is_admin' => 0,
                'is_dev' => 0,
                'is_owner' => 0
            ]
        ];

        // usr_dar is inserted by applyPendingMigrations() before seeding
        // runs, so it must not count as "already seeded".
        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM users WHERE id <> 'usr_dar'");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO users (id, name, email, phone, password_hash, role, verification_status, avatar_url, level, gold, silver, bronze, wallet_balance, is_admin, is_dev, is_owner) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($defaultUsers as $u) {
                $this->db->executeStatement($insSql, [
                    $u['id'], $u['name'], $u['email'], $u['phone'], $u['password_hash'], $u['role'],
                    $u['verification_status'], $u['avatar_url'], $u['level'], $u['gold'], $u['silver'],
                    $u['bronze'], $u['wallet_balance'], $u['is_admin'], $u['is_dev'], $u['is_owner']
                ]);
            }
        }

        // 2. Seed Facilities
        $defaultFacilities = [
            [
                'id' => 1,
                'owner_id' => 'usr_owner_acourts',
                'name' => 'A-Courts Premium Pickleball & Community',
                'location' => 'Gregorio Del Pilar Ext., Bantayan, Dumaguete City',
                'latitude' => 9.3289,
                'longitude' => 123.3032,
                'rating' => 4.9,
                'reviews' => 142,
                'price' => '₱300',
                'price_numeric' => 300.00,
                'type' => 'Indoor · Air Conditioned',
                'hours' => '6am - 10pm',
                'transit' => '🏍 5 min · 🚗 10 min',
                'image' => 'assets/images/facilities/acourts_dumaguete.jpg',
                'courts_count' => 5,
                'is_verified' => 1
            ],
            [
                'id' => 2,
                'owner_id' => 'usr_owner_picklepark',
                'name' => 'The Pickle Park & Café',
                'location' => 'Hibbard Avenue, Pinero Village, Dumaguete City',
                'latitude' => 9.3200,
                'longitude' => 123.3080,
                'rating' => 4.8,
                'reviews' => 115,
                'price' => '₱250',
                'price_numeric' => 250.00,
                'type' => 'Outdoor · Social & Cafe',
                'hours' => '6am - 10pm',
                'transit' => '🏍 4 min · 🚗 8 min',
                'image' => 'assets/images/facilities/pickle_park_dumaguete.jpg',
                'courts_count' => 4,
                'is_verified' => 1
            ],
            [
                'id' => 3,
                'owner_id' => 'usr_owner_incredoball',
                'name' => 'Incredoball Sports Center',
                'location' => 'Barangay Daro, Dumaguete City',
                'latitude' => 9.3140,
                'longitude' => 123.3000,
                'rating' => 4.8,
                'reviews' => 98,
                'price' => '₱280',
                'price_numeric' => 280.00,
                'type' => 'Indoor · Tournament Hard',
                'hours' => '6am - 11pm',
                'transit' => '🏍 6 min · 🚗 12 min',
                'image' => 'assets/images/facilities/incredoball_dumaguete.jpg',
                'courts_count' => 5,
                'is_verified' => 1
            ],
            [
                'id' => 4,
                'owner_id' => 'usr_owner_pickleballers',
                'name' => 'Pickleballers Sports Center',
                'location' => 'Barangay Balugo, Dumaguete City',
                'latitude' => 9.3080,
                'longitude' => 123.2850,
                'rating' => 4.7,
                'reviews' => 86,
                'price' => '₱220',
                'price_numeric' => 220.00,
                'type' => 'Indoor · Cushion Surface',
                'hours' => '6am - 10pm',
                'transit' => '🏍 8 min · 🚗 15 min',
                'image' => 'assets/images/facilities/pickleballers_dumaguete.jpg',
                'courts_count' => 5,
                'is_verified' => 1
            ],
            [
                'id' => 5,
                'owner_id' => 'usr_owner_academy',
                'name' => 'Dumaguete Pickleball Academy',
                'location' => 'Rizal Boulevard, Dumaguete City',
                'latitude' => 9.3060,
                'longitude' => 123.3090,
                'rating' => 4.9,
                'reviews' => 112,
                'price' => '₱400',
                'price_numeric' => 400.00,
                'type' => 'Indoor · Hard Court',
                'hours' => '6am - 10pm',
                'transit' => '🏍 15 min · 🚗 25 min',
                'image' => 'https://images.unsplash.com/photo-1599586120429-48281b6f0ece?q=80&w=1200&auto=format&fit=crop',
                'courts_count' => 4,
                'is_verified' => 1
            ],
            [
                'id' => 6,
                'owner_id' => 'usr_owner_pikolan',
                'name' => 'Pikolan Dumaguete Club',
                'location' => 'Dumaguete City, Negros Oriental',
                'latitude' => 9.3100,
                'longitude' => 123.3050,
                'rating' => 4.9,
                'reviews' => 128,
                'price' => '₱300',
                'price_numeric' => 300.00,
                'type' => 'Indoor / Outdoor',
                'hours' => '6am - 10pm',
                'transit' => '🏍 5 min · 🚗 8 min',
                'image' => 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?q=80&w=1200&auto=format&fit=crop',
                'courts_count' => 2,
                'is_verified' => 1
            ],
            [
                'id' => 7,
                'owner_id' => 'usr_owner_cebu',
                'name' => 'Cebu IT Park Pickle Center',
                'location' => 'Apas, Cebu City',
                'latitude' => 10.3280,
                'longitude' => 123.9060,
                'rating' => 4.8,
                'reviews' => 92,
                'price' => '₱380',
                'price_numeric' => 380.00,
                'type' => 'Indoor · Air Conditioned',
                'hours' => '24 Hours Open',
                'transit' => '🏍 7 min · 🚗 14 min',
                'image' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1200&auto=format&fit=crop',
                'courts_count' => 1,
                'is_verified' => 1
            ],
            [
                'id' => 8,
                'owner_id' => 'usr_owner_davao',
                'name' => 'Davao Smash & Dink Park',
                'location' => 'Lanang, Davao City',
                'latitude' => 7.0980,
                'longitude' => 125.6320,
                'rating' => 4.7,
                'reviews' => 65,
                'price' => '₱320',
                'price_numeric' => 320.00,
                'type' => 'Outdoor · Acrylic Pro',
                'hours' => '6am - 10pm',
                'transit' => '🏍 10 min · 🚗 18 min',
                'image' => 'https://images.unsplash.com/photo-1541534741688-6078c6bfb5c5?q=80&w=1200&auto=format&fit=crop',
                'courts_count' => 1,
                'is_verified' => 1
            ]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM facilities");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO facilities (id, owner_id, name, location, rating, reviews, price, price_numeric, type, hours, transit, image, courts_count, is_verified) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($defaultFacilities as $f) {
                $this->db->executeStatement($insSql, [
                    $f['id'], $f['owner_id'] ?? null, $f['name'], $f['location'], $f['rating'], $f['reviews'], $f['price'],
                    $f['price_numeric'], $f['type'], $f['hours'], $f['transit'], $f['image'],
                    $f['courts_count'], $f['is_verified']
                ]);
            }
        }

        // 3. Seed Courts
        $defaultCourts = [
            // Facility 1: Pickleball Dumaguete HQ
            ['id' => 'crt_1_1', 'facility_id' => 1, 'name' => 'Court 1', 'surface' => 'Hard Court Pro', 'type' => 'Indoor', 'price' => 250.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_1_2', 'facility_id' => 1, 'name' => 'Court 2', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 280.00, 'status' => 'occupied', 'occupied_by' => 'Dumaguete Dinking Crew', 'occupied_until' => '7:00 PM'],
            ['id' => 'crt_1_3', 'facility_id' => 1, 'name' => 'Court 3', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 300.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_1_4', 'facility_id' => 1, 'name' => 'Court 4', 'surface' => 'Outdoor Acrylic', 'type' => 'Indoor', 'price' => 320.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_1_5', 'facility_id' => 1, 'name' => 'Court 5', 'surface' => 'Hard Court Pro', 'type' => 'Indoor', 'price' => 350.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],

            // Facility 2: Dumaguete Smash & Dink Club
            ['id' => 'crt_2_1', 'facility_id' => 2, 'name' => 'Court 1', 'surface' => 'Championship Wood', 'type' => 'Indoor', 'price' => 200.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_2_2', 'facility_id' => 2, 'name' => 'Court 2', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 220.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_2_3', 'facility_id' => 2, 'name' => 'Court 3', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 250.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_2_4', 'facility_id' => 2, 'name' => 'Court 4', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 280.00, 'status' => 'maintenance', 'occupied_by' => null, 'occupied_until' => null],

            // Facility 3: Negros Pickleball Arena
            ['id' => 'crt_3_1', 'facility_id' => 3, 'name' => 'Court 1', 'surface' => 'Hard Court Pro', 'type' => 'Indoor', 'price' => 220.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_3_2', 'facility_id' => 3, 'name' => 'Court 2', 'surface' => 'Outdoor Acrylic', 'type' => 'Indoor', 'price' => 250.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_3_3', 'facility_id' => 3, 'name' => 'Court 3', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 280.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_3_4', 'facility_id' => 3, 'name' => 'Court 4', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 310.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_3_5', 'facility_id' => 3, 'name' => 'Court 5', 'surface' => 'Outdoor Acrylic', 'type' => 'Outdoor', 'price' => 340.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],

            // Facility 4: Pickleballers Sports Center
            ['id' => 'crt_4_1', 'facility_id' => 4, 'name' => 'Court 1', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 200.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_4_2', 'facility_id' => 4, 'name' => 'Court 2', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 220.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_4_3', 'facility_id' => 4, 'name' => 'Court 3', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 240.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_4_4', 'facility_id' => 4, 'name' => 'Court 4', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 260.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_4_5', 'facility_id' => 4, 'name' => 'Court 5', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 280.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],

            // Facility 5: Dumaguete Pickleball Academy
            ['id' => 'crt_5_1', 'facility_id' => 5, 'name' => 'Court 1', 'surface' => 'Hard Court Pro', 'type' => 'Indoor', 'price' => 350.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_5_2', 'facility_id' => 5, 'name' => 'Court 2', 'surface' => 'Hard Court Pro', 'type' => 'Indoor', 'price' => 380.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_5_3', 'facility_id' => 5, 'name' => 'Court 3', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 400.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_5_4', 'facility_id' => 5, 'name' => 'Court 4', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 420.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],

            // Facilities 6, 7, 8
            ['id' => 'crt_6_1', 'facility_id' => 6, 'name' => 'Court 1', 'surface' => 'Hard Court', 'type' => 'Indoor', 'price' => 300.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_6_2', 'facility_id' => 6, 'name' => 'Court 2', 'surface' => 'Outdoor Acrylic', 'type' => 'Outdoor', 'price' => 300.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_7_1', 'facility_id' => 7, 'name' => 'Court 1', 'surface' => 'Cushion Acrylic', 'type' => 'Indoor', 'price' => 380.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null],
            ['id' => 'crt_8_1', 'facility_id' => 8, 'name' => 'Court 1', 'surface' => 'Outdoor Acrylic', 'type' => 'Outdoor', 'price' => 320.00, 'status' => 'available', 'occupied_by' => null, 'occupied_until' => null]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM courts");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO courts (id, facility_id, name, surface, type, price, status, occupied_by, occupied_until) VALUES (?,?,?,?,?,?,?,?,?)";
            foreach ($defaultCourts as $c) {
                $this->db->executeStatement($insSql, [
                    $c['id'], $c['facility_id'], $c['name'], $c['surface'], $c['type'],
                    $c['price'], $c['status'], $c['occupied_by'], $c['occupied_until']
                ]);
            }
        } else {
            try {
                $cRows = $this->db->executeQuery("SELECT id, name FROM courts")->fetchAllAssociative();
                $updSql = "UPDATE courts SET name = ? WHERE id = ?";
                foreach ($cRows as $cr) {
                    $norm = \Picklers\Domain\Schedule::normalizeCourtName((string)($cr['name'] ?? ''));
                    if ($norm !== ($cr['name'] ?? '')) {
                        $this->db->executeStatement($updSql, [$norm, $cr['id']]);
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 4. Seed Open Play Matches
        $defaultMatches = [
            [
                'id' => 'match_1',
                'facility_id' => 1,
                'facility_name' => 'A-Courts Premium Pickleball & Community',
                'location' => 'Gregorio Del Pilar Ext., Bantayan, Dumaguete City',
                'date' => date('Y-m-d'),
                'time' => '6:00 PM - 8:00 PM',
                'level' => 'Intermediate',
                'current_players' => 3,
                'max_players' => 4,
                'price' => 150.00,
                'type' => 'Doubles Open Play',
                'host' => 'Coach Marco'
            ],
            [
                'id' => 'match_2',
                'facility_id' => 2,
                'facility_name' => 'Dumaguete Smash & Dink Club',
                'location' => 'Sibatulan, Dumaguete City',
                'date' => date('Y-m-d'),
                'time' => '7:30 PM - 9:30 PM',
                'level' => 'Advanced',
                'current_players' => 2,
                'max_players' => 4,
                'price' => 200.00,
                'type' => 'King of the Court (Rating 4.0+)',
                'host' => 'Patricia Tan'
            ],
            [
                'id' => 'match_3',
                'facility_id' => 3,
                'facility_name' => 'Negros Pickleball Arena',
                'location' => 'Downtown, Dumaguete City',
                'date' => date('Y-m-d', strtotime('+1 day')),
                'time' => '5:00 PM - 7:00 PM',
                'level' => 'Beginner',
                'current_players' => 2,
                'max_players' => 6,
                'price' => 120.00,
                'type' => 'Welcome Clinics & Social Dink',
                'host' => 'Coach Dave'
            ],
            [
                'id' => 'match_4',
                'facility_id' => 4,
                'facility_name' => 'Pickleballers Sports Center',
                'location' => 'Barangay Balugo, Dumaguete City',
                'date' => date('Y-m-d', strtotime('+1 day')),
                'time' => '6:00 PM - 8:00 PM',
                'level' => 'Intermediate',
                'current_players' => 4,
                'max_players' => 4,
                'price' => 160.00,
                'type' => 'Dumaguete Dinking League',
                'host' => 'Joey Ramos'
            ],
            [
                'id' => 'match_5',
                'facility_id' => 6,
                'facility_name' => 'Pikolan Dumaguete Club',
                'location' => 'Dumaguete City, Negros Oriental',
                'date' => date('Y-m-d', strtotime('next friday')),
                'time' => '5:30 PM - 7:30 PM',
                'level' => 'All Levels',
                'current_players' => 5,
                'max_players' => 8,
                'price' => 100.00,
                'type' => 'Friday Sunset Dink & Chill',
                'host' => 'Kuya Neil'
            ],
            [
                'id' => 'match_6',
                'facility_id' => 7,
                'facility_name' => 'Cebu IT Park Pickle Center',
                'location' => 'Apas, Cebu City',
                'date' => date('Y-m-d', strtotime('next saturday')),
                'time' => '8:00 PM - 10:00 PM',
                'level' => 'Advanced',
                'current_players' => 3,
                'max_players' => 4,
                'price' => 180.00,
                'type' => 'Court 1',
                'title' => 'Queen City Saturday Night Smash',
                'host' => 'Vince Teves'
            ]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM matches");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host, title) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($defaultMatches as $m) {
                $this->db->executeStatement($insSql, [
                    $m['id'], $m['facility_id'], $m['facility_name'], $m['location'], $m['date'],
                    $m['time'], $m['level'], $m['current_players'], $m['max_players'], $m['price'],
                    $m['type'], $m['host'], $m['title'] ?? null
                ]);
            }
        }

        // 5. Seed Bookings (Empty default)
        $defaultBookings = [];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM bookings");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO bookings (id, user_id, facility_id, facility_name, court_name, date, time, duration, price, payment_method, status, is_new, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($defaultBookings as $b) {
                $this->db->executeStatement($insSql, [
                    $b['id'], $b['user_id'], $b['facility_id'], $b['facility_name'], $b['court_name'],
                    $b['date'], $b['time'], $b['duration'], $b['price'], $b['payment_method'],
                    $b['status'], $b['is_new'], $b['created_at']
                ]);
            }
        }

        // 6. Seed Wallet Transactions
        $defaultTx = [
            [
                'id' => 'tx_1',
                'user_id' => 'usr_player',
                'type' => 'credit',
                'amount' => 1000.00,
                'label' => 'GCash',
                'date' => 'Sep 4, 2026',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'id' => 'tx_2',
                'user_id' => 'usr_player',
                'type' => 'debit',
                'amount' => 450.00,
                'label' => 'Booking #PKL-8F92A1 BGC Court 1',
                'date' => 'Sep 4, 2026',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ],
            [
                'id' => 'tx_3',
                'user_id' => 'usr_player',
                'type' => 'credit',
                'amount' => 1300.00,
                'label' => 'Maya Welcome Bonus Top-Up',
                'date' => 'Sep 1, 2026',
                'created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))
            ]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM wallet_transactions");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO wallet_transactions (id, user_id, type, amount, label, date, created_at) VALUES (?,?,?,?,?,?,?)";
            foreach ($defaultTx as $t) {
                $this->db->executeStatement($insSql, [$t['id'], $t['user_id'], $t['type'], $t['amount'], $t['label'], $t['date'], $t['created_at']]);
            }
        }

        // 7. Seed Feed Posts
        $defaultPosts = [
            [
                'id' => 'post_1',
                'author_id' => 'usr_admin',
                'author_name' => 'Picklers Super Admin',
                'author_avatar' => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?q=80&w=300&auto=format&fit=crop',
                'author_level' => 'Pro 5.0',
                'content' => '🇵🇭 Welcome to PICKLERS 2026! Over 50+ verified courts nationwide are now live for instant booking and split payments. Join our BGC Open Play this weekend!',
                'image_url' => 'https://images.unsplash.com/photo-1622228399564-946d849b28b7?q=80&w=1200&auto=format&fit=crop',
                'post_type' => 'highlight',
                'like_count' => 24,
                'comment_count' => 5,
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'id' => 'post_2',
                'author_id' => 'usr_owner',
                'author_name' => 'BGC Arena Manager',
                'author_avatar' => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?q=80&w=300&auto=format&fit=crop',
                'author_level' => 'Advanced 4.0',
                'content' => 'Clean cushioned surfaces repainted today at BGC Hub! Fast dinks, zero knee strain. Courts 1 through 4 open until 11 PM tonight.',
                'image_url' => 'https://images.unsplash.com/photo-1626245564883-99931ef106df?q=80&w=1200&auto=format&fit=crop',
                'post_type' => 'text',
                'like_count' => 18,
                'comment_count' => 2,
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 hours'))
            ]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM feed_posts");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO feed_posts (id, author_id, author_name, author_avatar, author_level, content, image_url, post_type, like_count, comment_count, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)";
            foreach ($defaultPosts as $p) {
                $this->db->executeStatement($insSql, [
                    $p['id'], $p['author_id'], $p['author_name'], $p['author_avatar'],
                    $p['author_level'], $p['content'], $p['image_url'], $p['post_type'],
                    $p['like_count'], $p['comment_count'], $p['created_at']
                ]);
            }
        }

        // 8. Seed Notifications
        $defaultNotifs = [
            [
                'id' => 'notif_1',
                'user_id' => 'usr_player',
                'title' => 'Booking Confirmed! 🎾',
                'body' => 'Your court at BGC Pickleball Hub (Court 1) is reserved for Tomorrow at 6:00 PM.',
                'type' => 'booking',
                'is_read' => 0,
                'time' => '10 mins ago',
                'created_at' => date('Y-m-d H:i:s')
            ],
            [
                'id' => 'notif_2',
                'user_id' => 'usr_player',
                'title' => 'Open Play Joined! 🔥',
                'body' => 'You joined the King of the Court open play session at Makati Sports Club.',
                'type' => 'community',
                'is_read' => 1,
                'time' => '2 hours ago',
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))
            ],
            [
                'id' => 'notif_3',
                'user_id' => 'usr_player',
                'title' => 'Wallet Top-Up Successful 💳',
                'body' => '₱1,000.00 Pickle Credits added to your balance via GCash.',
                'type' => 'system',
                'is_read' => 1,
                'time' => 'Yesterday',
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))
            ]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM notifications");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO notifications (id, user_id, title, body, type, is_read, time, created_at) VALUES (?,?,?,?,?,?,?,?)";
            foreach ($defaultNotifs as $n) {
                $this->db->executeStatement($insSql, [$n['id'], $n['user_id'], $n['title'], $n['body'], $n['type'], $n['is_read'], $n['time'], $n['created_at']]);
            }
        }

        // 8b. Seed Staff
        $defaultStaff = [
            ['id' => 'st_1', 'facility_id' => '1', 'name' => 'Maria Santos', 'email' => 'maria@picklersbgc.com', 'role' => 'Front Desk Receptionist', 'status' => 'Active', 'date_joined' => 'Jan 2026'],
            ['id' => 'st_2', 'facility_id' => '1', 'name' => 'Carlos Reyes', 'email' => 'carlos@picklersbgc.com', 'role' => 'Court Manager', 'status' => 'Active', 'date_joined' => 'Nov 2025'],
            ['id' => 'st_3', 'facility_id' => '1', 'name' => 'Juan dela Cruz', 'email' => 'juan@picklersbgc.com', 'role' => 'Tournament Coordinator', 'status' => 'Active', 'date_joined' => 'Mar 2026']
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM staff");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO staff (id, facility_id, name, email, role, status, date_joined) VALUES (?,?,?,?,?,?,?)";
            foreach ($defaultStaff as $st) {
                $this->db->executeStatement($insSql, [$st['id'], $st['facility_id'], $st['name'], $st['email'], $st['role'], $st['status'], $st['date_joined']]);
            }
        }

        // 9. Seed Court Images
        $defaultCourtImages = [
            ['facility_id' => 1, 'url' => 'assets/images/facilities/acourts_dumaguete.jpg', 'alt_text' => 'Pickleball Dumaguete Main Arena', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 1, 'url' => 'https://images.unsplash.com/photo-1599586120429-48281b6f0ece?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Championship Pro Court 1', 'sort_order' => 2, 'is_primary' => 0],
            ['facility_id' => 1, 'url' => 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Standard Indoor Court', 'sort_order' => 3, 'is_primary' => 0],
            ['facility_id' => 1, 'url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Night Play Under Lights', 'sort_order' => 4, 'is_primary' => 0],

            // Only images that exist under assets/images/facilities are referenced.
            ['facility_id' => 2, 'url' => 'assets/images/facilities/pickle_park_dumaguete.jpg', 'alt_text' => 'The Pickle Park & Café — main entrance', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 2, 'url' => 'https://images.unsplash.com/photo-1541534741688-6078c6bfb5c5?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Glass Wall Pro Court', 'sort_order' => 2, 'is_primary' => 0],
            ['facility_id' => 2, 'url' => 'https://images.unsplash.com/photo-1626248801379-51a0748a5f96?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Double Dink Alley', 'sort_order' => 3, 'is_primary' => 0],

            // Was 'assets/images/facilities/negros_dumaguete.jpg' — same
            // defect, also never on disk.
            ['facility_id' => 3, 'url' => 'assets/images/facilities/incredoball_dumaguete.jpg', 'alt_text' => 'Incredoball Sports Center — centre court', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 3, 'url' => 'https://images.unsplash.com/photo-1599586120429-48281b6f0ece?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'North Wing Indoor', 'sort_order' => 2, 'is_primary' => 0],
            ['facility_id' => 3, 'url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Covered Outdoor Court', 'sort_order' => 3, 'is_primary' => 0],

            ['facility_id' => 4, 'url' => 'assets/images/facilities/pickleballers_dumaguete.jpg', 'alt_text' => 'Pickleballers Center Court', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 4, 'url' => 'https://images.unsplash.com/photo-1541534741688-6078c6bfb5c5?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Indoor Cushion Court', 'sort_order' => 2, 'is_primary' => 0],

            // Facilities 5-8 get one image each (the venue's own
            // facilities.image); more require the owner to upload photos.
            ['facility_id' => 5, 'url' => 'https://images.unsplash.com/photo-1599586120429-48281b6f0ece?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Dumaguete Pickleball Academy — venue view', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 6, 'url' => 'https://images.unsplash.com/photo-1554068865-24cecd4e34b8?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Pikolan Dumaguete Club — venue view', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 7, 'url' => 'https://images.unsplash.com/photo-1534438327276-14e5300c3a48?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Cebu IT Park Pickle Center — venue view', 'sort_order' => 1, 'is_primary' => 1],
            ['facility_id' => 8, 'url' => 'https://images.unsplash.com/photo-1541534741688-6078c6bfb5c5?q=80&w=1200&auto=format&fit=crop', 'alt_text' => 'Davao Smash & Dink Park — venue view', 'sort_order' => 1, 'is_primary' => 1],
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM court_images");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO court_images (facility_id, url, alt_text, sort_order, is_primary) VALUES (?,?,?,?,?)";
            foreach ($defaultCourtImages as $img) {
                $this->db->executeStatement($insSql, [$img['facility_id'], $img['url'], $img['alt_text'], $img['sort_order'], $img['is_primary']]);
            }
        }

        // 10. Seed Amenities
        $defaultAmenities = [
            ['id' => 1, 'name' => 'Air Conditioned', 'icon' => 'ph-snowflake'],
            ['id' => 2, 'name' => 'Pro Shop & Gear Rental', 'icon' => 'ph-bag'],
            ['id' => 3, 'name' => 'Night Lighting', 'icon' => 'ph-lightbulb'],
            ['id' => 4, 'name' => 'Locker Rooms & Showers', 'icon' => 'ph-shower'],
            ['id' => 5, 'name' => 'Free High-Speed WiFi', 'icon' => 'ph-wifi-high'],
            ['id' => 6, 'name' => 'On-site Cafe & Refreshments', 'icon' => 'ph-coffee'],
            ['id' => 7, 'name' => 'Free Parking', 'icon' => 'ph-car'],
            ['id' => 8, 'name' => 'Spectator Seating', 'icon' => 'ph-armchair'],
            ['id' => 9, 'name' => 'Certified Coaches On-Duty', 'icon' => 'ph-user-check'],
            ['id' => 10, 'name' => 'Chill Lounge Area', 'icon' => 'ph-couch']
        ];

        $defaultFacilityAmenities = [
            ['facility_id' => 1, 'amenity_id' => 1], ['facility_id' => 1, 'amenity_id' => 2], ['facility_id' => 1, 'amenity_id' => 3], ['facility_id' => 1, 'amenity_id' => 4], ['facility_id' => 1, 'amenity_id' => 5], ['facility_id' => 1, 'amenity_id' => 6], ['facility_id' => 1, 'amenity_id' => 7], ['facility_id' => 1, 'amenity_id' => 8], ['facility_id' => 1, 'amenity_id' => 9], ['facility_id' => 1, 'amenity_id' => 10],
            ['facility_id' => 2, 'amenity_id' => 1], ['facility_id' => 2, 'amenity_id' => 3], ['facility_id' => 2, 'amenity_id' => 4], ['facility_id' => 2, 'amenity_id' => 5], ['facility_id' => 2, 'amenity_id' => 6], ['facility_id' => 2, 'amenity_id' => 7], ['facility_id' => 2, 'amenity_id' => 8],
            ['facility_id' => 3, 'amenity_id' => 2], ['facility_id' => 3, 'amenity_id' => 3], ['facility_id' => 3, 'amenity_id' => 5], ['facility_id' => 3, 'amenity_id' => 6], ['facility_id' => 3, 'amenity_id' => 7], ['facility_id' => 3, 'amenity_id' => 8], ['facility_id' => 3, 'amenity_id' => 10],
            ['facility_id' => 4, 'amenity_id' => 1], ['facility_id' => 4, 'amenity_id' => 3], ['facility_id' => 4, 'amenity_id' => 4], ['facility_id' => 4, 'amenity_id' => 5], ['facility_id' => 4, 'amenity_id' => 7], ['facility_id' => 4, 'amenity_id' => 9]
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM amenities");
        if ($stmt->fetchAssociative()['c'] == 0) {
            $insSql = "INSERT INTO amenities (id, name, icon) VALUES (?,?,?)";
            foreach ($defaultAmenities as $a) {
                $this->db->executeStatement($insSql, [$a['id'], $a['name'], $a['icon']]);
            }
        }
        $stmtFa = $this->db->executeQuery("SELECT COUNT(*) as c FROM facility_amenities");
        if ($stmtFa->fetchAssociative()['c'] == 0) {
            $insFaSql = "INSERT INTO facility_amenities (facility_id, amenity_id) VALUES (?,?)";
            foreach ($defaultFacilityAmenities as $fa) {
                $this->db->executeStatement($insFaSql, [$fa['facility_id'], $fa['amenity_id']]);
            }
        }

        // 11. Seed Promo Codes
        // Honest defaults: real usage counters start at zero and accrue only
        // from real redemptions (see recordPromoRedemption()). Every code
        // starts active — a promo that can never be redeemed is not useful
        // sample data, and this is the only place a fresh install's promo
        // catalog should come from.
        $defaultPromos = [
            [
                'id' => 'prm_welcome100', 'code' => 'WELCOME100',
                'discount_type' => 'fixed', 'discount_value' => 100.00,
                'min_spend' => 0.00, 'max_discount' => null,
                'usage_limit' => 500, 'times_used' => 0, 'user_limit' => 1,
                'expires_at' => null, 'status' => 'active',
                'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            ],
            [
                'id' => 'prm_pickle20', 'code' => 'PICKLE20',
                'discount_type' => 'percentage', 'discount_value' => 20.00,
                'min_spend' => 300.00, 'max_discount' => 500.00,
                'usage_limit' => 100, 'times_used' => 0, 'user_limit' => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+60 days')),
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            ],
            [
                'id' => 'prm_dinkfree', 'code' => 'DINKFREE',
                'discount_type' => 'fixed', 'discount_value' => 150.00,
                'min_spend' => 400.00, 'max_discount' => null,
                'usage_limit' => 50, 'times_used' => 0, 'user_limit' => 1,
                'expires_at' => null, 'status' => 'active',
                'created_at' => date('Y-m-d H:i:s', strtotime('-20 days')),
            ],
        ];

        $stmt = $this->db->executeQuery("SELECT COUNT(*) as c FROM promo_codes");
        if ($stmt->fetchAssociative()['c'] == 0) {
            foreach ($defaultPromos as $p) {
                $this->promos->createPromoCode($p);
            }
        }

        @file_put_contents($seedFlag, date('c'));
    }

    /** @var array<string,bool> */
    private array $columnCache = [];

    /** Whether a column exists (memoised per request). Lets new columns stay optional until migrated. */
    public function hasColumn(string $table, string $column): bool {
        $key = $table . '.' . $column;
        return $this->columnCache[$key] ??= $this->columnExists($table, $column);
    }
}
