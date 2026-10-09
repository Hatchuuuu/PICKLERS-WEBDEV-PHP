<?php
declare(strict_types=1);

namespace Picklers\Core;

use PDO;
use Exception;

// ==============================================================================
// PICKLERS — Database & Persistence Engine (Pure PHP 8.x)
// Dual-Mode: MySQL PDO with automatic failover to Zero-Config JSON file storage
// ==============================================================================

class Database {
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    private bool $isMySQL = false;
    private string $dataDir;
    private bool $schemaInitialisedThisBoot = false;

    /**
     * Request-scoped read cache for facility/court lookups.
     *
     * These are read many times per request (listing, detail, and once per
     * booking via PricingService) but change rarely; in JSON mode each read
     * would otherwise re-parse the whole file. Scoping the cache to a single
     * request keeps it from serving stale data across requests, and any write
     * invalidates it immediately.
     *
     * @var array<string,mixed>
     */
    private array $readCache = [];

    /**
     * PDO::MYSQL_ATTR_INIT_COMMAND was moved to Pdo\Mysql::ATTR_INIT_COMMAND
     * in PHP 8.4 and the old constant is deprecated (though still functional)
     * from PHP 8.5 on. The project's floor is PHP 8.1, so both constants must
     * be supported; this picks whichever the running PHP version actually has.
     */
    private static function pdoMysqlInitCommandAttr(): int {
        return defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
            ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
            : PDO::MYSQL_ATTR_INIT_COMMAND;
    }

    /** Doctrine's connection, when running inside the Symfony kernel (see Kernel::boot()). */
    private static ?\Closure $connectionProvider = null;

    /**
     * Run on Doctrine's connection: the kernel registers it at boot, so Symfony
     * code, the shared rules and this class share one MySQL session per request.
     *
     * @param \Closure(): \Doctrine\DBAL\Connection $provider
     */
    public static function useConnection(\Closure $provider): void {
        self::$connectionProvider = $provider;
    }

    private function __construct() {
        $configFile = defined('CONFIG_PATH') ? (CONFIG_PATH . '/database.php') : (dirname(__DIR__, 2) . '/config/database.php');
        $config = file_exists($configFile) ? require $configFile : [];

        // Schema stamps, seed flags and throttle counters (never data: MySQL is required).
        $this->dataDir = $config['json']['path'] ?? (defined('DATA_PATH') ? DATA_PATH : (dirname(__DIR__, 2) . '/database'));

        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0750, true);
        }

        if (self::$connectionProvider !== null) {
            $this->dbal = (self::$connectionProvider)();
            $this->pdo = $this->dbal->getNativeConnection();
            // The queries below read rows as associative arrays; Doctrine always names its fetch mode.
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->isMySQL = true;
            $this->initialiseSchema();
            $this->seedInitialData();
            return;
        }

        // Outside the kernel (CLI scripts, the service-level test suite): its own
        // connection, creating the database on first use. There is no file
        // fallback — bookings and payments must never land anywhere but MySQL.
        $mysql = $config['connections']['mysql'] ?? [];
        $host = $mysql['host'] ?? '127.0.0.1';
        $user = $mysql['username'] ?? 'root';
        $pass = $mysql['password'] ?? '';
        $dbname = $mysql['database'] ?? 'picklers_db';

        // First connect without db name to ensure picklers_db exists
        $rootPdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $this->pdo = new PDO("mysql:host=$host;dbname={$dbname};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // STRICT_TRANS_TABLES turns an out-of-type value (e.g. a string
            // written to an INT facility_id) into an error instead of a
            // silent coercion to 0, so write paths must handle PDOException.
            self::pdoMysqlInitCommandAttr() => "SET SESSION sql_mode = CONCAT(@@sql_mode, ',STRICT_TRANS_TABLES,NO_ZERO_DATE')",
        ]);
        $this->isMySQL = true;
        $this->initialiseSchema();
        $this->seedInitialData();
    }

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
            $stmt = $this->pdo->query(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
            );
            return $stmt ? array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function markSchemaInitialised(): void {
        @file_put_contents($this->schemaStampPath(), self::SCHEMA_VERSION);
    }

    public static function get(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getInstance(): self {
        return self::get();
    }

    public function isUsingMySQL() {
        return $this->isMySQL;
    }

    /** The live MySQL handle, or null in JSON fallback mode. */
    public function pdo(): ?PDO {
        return $this->isMySQL ? $this->pdo : null;
    }

    // Shared rules live in Picklers\Domain (used as-is by the Symfony kernel); the
    // legacy layer reaches them through a DBAL connection over its own handle.
    private ?\Doctrine\DBAL\Connection $dbal = null;
    private ?\Picklers\Domain\Notifier $notifier = null;
    private ?\Picklers\Domain\WalletLedger $ledger = null;
    private ?\Picklers\Domain\BookingRules $bookingRules = null;

    public function dbal(): \Doctrine\DBAL\Connection {
        return $this->dbal ??= \Doctrine\DBAL\DriverManager::getConnection(['driverClass' => LegacyPdoDriver::class, 'serverVersion' => '10.4.32-MariaDB']);
    }

    public function notifier(): \Picklers\Domain\Notifier {
        return $this->notifier ??= new \Picklers\Domain\Notifier($this->dbal());
    }

    public function ledger(): \Picklers\Domain\WalletLedger {
        return $this->ledger ??= new \Picklers\Domain\WalletLedger($this->dbal());
    }

    public function bookingRules(): \Picklers\Domain\BookingRules {
        return $this->bookingRules ??= new \Picklers\Domain\BookingRules($this->dbal(), $this->notifier(), $this->ledger());
    }

    /** Drop cached facility/court reads after any write that could change them. */
    public function invalidateReadCache(): void {
        $this->readCache = [];
    }

    /**
     * Bump one or more sync channels after a write another surface needs to
     * notice without a manual refresh ('courts', 'facilities', 'bookings', …).
     * See Picklers\Domain\Notifier::bumpSync(); never throws.
     */
    public function bumpSync(string ...$channels): void {
        if ($this->isMySQL && $channels !== []) {
            $this->notifier()->bumpSync(...$channels);
        }
    }

    /** @return array<string,int> channel => current version */
    public function getSyncVersions(): array {
        try {
            $rows = $this->pdo->query("SELECT channel, version FROM sync_versions")->fetchAll();
            return array_map('intval', array_column($rows, 'version', 'channel'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // --------------------------------------------------------------------------
    // Schema Initialization
    // --------------------------------------------------------------------------
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
            $this->pdo->exec($sql);
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
                $this->pdo->exec($m['sql']);
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
            $this->pdo->exec(
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
                $checkStmt = $this->pdo->query("SELECT COUNT(DISTINCT price) as distinct_prices FROM courts WHERE facility_id = 1");
                $row = $checkStmt ? $checkStmt->fetch() : null;
                if ($row && (int)($row['distinct_prices'] ?? 0) <= 1) {
                    $updates = [
                        "crt_1_1" => 250, "crt_1_2" => 280, "crt_1_3" => 300, "crt_1_4" => 320, "crt_1_5" => 350,
                        "crt_2_1" => 200, "crt_2_2" => 220, "crt_2_3" => 250, "crt_2_4" => 280, "crt_2_5" => 300,
                        "crt_3_1" => 220, "crt_3_2" => 250, "crt_3_3" => 280, "crt_3_4" => 310, "crt_3_5" => 340,
                        "crt_4_1" => 180, "crt_4_2" => 200, "crt_4_3" => 220, "crt_4_4" => 240, "crt_4_5" => 260,
                        "crt_5_1" => 200, "crt_5_2" => 230, "crt_5_3" => 260, "crt_5_4" => 280, "crt_5_5" => 300
                    ];
                    $uStmt = $this->pdo->prepare("UPDATE courts SET price = ? WHERE id = ?");
                    foreach ($updates as $cid => $cprice) {
                        $uStmt->execute([$cprice, $cid]);
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
                $devUser = $this->pdo->prepare(
                    "INSERT IGNORE INTO users (id, name, email, phone, password_hash, role, verification_status, level, wallet_balance, is_admin, is_dev, is_owner)
                     VALUES ('usr_dar', 'Dante Reyes', 'dar@gmail.com', '+63 917 555 4444', ?, 'player', 'verified', 'Intermediate 3.5', 2500.00, 0, 0, 0)"
                );
                $devUser->execute([password_hash('picklers123', PASSWORD_DEFAULT)]);
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
            $this->pdo->exec("UPDATE notifications SET body = REPLACE(body, 'dar booked', 'Dante Reyes booked') WHERE body LIKE '%dar booked%'");
            $this->pdo->exec("UPDATE notifications SET body = REPLACE(body, 'How test Arena', 'Flow Test Arena') WHERE body LIKE '%How test Arena%'");
            $this->pdo->exec("UPDATE notifications SET title = 'New reservation 🎾' WHERE title LIKE '%Now reservation%'");

            // Ensure all matches, courts, and bookings strictly use numbered court names (Court 1, Court 2, Court 3...)
            $mRows = $this->pdo->query("SELECT id, type FROM matches")->fetchAll();
            $uMatch = $this->pdo->prepare("UPDATE matches SET type = ? WHERE id = ?");
            foreach ($mRows as $mr) {
                $origType = (string)($mr['type'] ?? '');
                $newType = self::normalizeCourtName($origType);
                if ($newType !== $origType) {
                    $uMatch->execute([$newType, $mr['id']]);
                }
            }

            $cRows = $this->pdo->query("SELECT id, name FROM courts")->fetchAll();
            $uCourt = $this->pdo->prepare("UPDATE courts SET name = ? WHERE id = ?");
            foreach ($cRows as $cr) {
                $norm = self::normalizeCourtName((string)($cr['name'] ?? ''));
                if ($norm !== ($cr['name'] ?? '')) {
                    $uCourt->execute([$norm, $cr['id']]);
                }
            }

            $bRows = $this->pdo->query("SELECT id, court_name FROM bookings")->fetchAll();
            $uBooking = $this->pdo->prepare("UPDATE bookings SET court_name = ? WHERE id = ?");
            foreach ($bRows as $br) {
                $norm = self::normalizeCourtName((string)($br['court_name'] ?? ''));
                if ($norm !== ($br['court_name'] ?? '')) {
                    $uBooking->execute([$norm, $br['id']]);
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
            $stmt = $this->pdo->prepare(
                "INSERT IGNORE INTO court_attributes (slug, label, icon, kind, sort_order) VALUES (?,?,?,?,?)"
            );
            foreach ($catalog as $a) {
                $stmt->execute([$a['slug'], $a['label'], $a['icon'], $a['kind'], $a['sort_order']]);
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
            $untagged = $this->pdo->query(
                "SELECT c.id, c.type, c.surface, f.type AS facility_type
                   FROM courts c
                   JOIN facilities f ON f.id = c.facility_id
                  LEFT JOIN court_attribute_map m ON m.court_id = c.id
                  WHERE m.court_id IS NULL"
            )->fetchAll();
        } catch (\Throwable $e) {
            error_log('[PICKLERS Migration] backfillCourtAttributesFromLegacyFields read skipped: ' . $e->getMessage());
            return;
        }
        if (!$untagged) {
            return;
        }

        $slugIds = [];
        foreach ($this->pdo->query("SELECT id, slug FROM court_attributes")->fetchAll() as $row) {
            $slugIds[$row['slug']] = (int)$row['id'];
        }

        $ins = $this->pdo->prepare("INSERT IGNORE INTO court_attribute_map (court_id, attribute_id) VALUES (?, ?)");

        foreach ($untagged as $c) {
            $slugs = self::deriveCourtAttributeSlugsFromLegacyFields(
                $c['type'] ?? null, $c['surface'] ?? null, $c['facility_type'] ?? null
            );
            foreach ($slugs as $slug) {
                if (isset($slugIds[$slug])) {
                    $ins->execute([$c['id'], $slugIds[$slug]]);
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

    /**
     * Populate the canonical booking_date/start_min/end_min/court_id columns
     * added above for every row that predates them. Safe to run on every
     * request: the WHERE clause only ever matches rows that still have
     * booking_date IS NULL, so once a row is backfilled this is a no-op scan
     * against it. Capped at 500 rows per call as a defensive limit — this
     * project's whole `bookings` table has never had more than a few dozen.
     */
    private function backfillBookingCanonicalFields(): void {
        $stmt = $this->pdo->query(
            "SELECT id, facility_id, court_name, date, time
               FROM bookings
              WHERE booking_date IS NULL
              LIMIT 500"
        );
        $rows = $stmt ? $stmt->fetchAll() : [];
        if (!$rows) {
            return;
        }

        $update = $this->pdo->prepare(
            "UPDATE bookings SET court_id = ?, booking_date = ?, start_min = ?, end_min = ? WHERE id = ?"
        );

        // Cache each facility's courts across the whole backfill instead of
        // re-querying per row.
        $courtsByFacility = [];

        foreach ($rows as $row) {
            $facilityId = (int)($row['facility_id'] ?? 0);
            if (!array_key_exists($facilityId, $courtsByFacility)) {
                $courtsByFacility[$facilityId] = $this->getCourtsByFacilityUncached($facilityId);
            }

            // Best-effort court_id match: exact name first, then a prefix
            // match either direction (recovers the specific corruption this
            // migration exists for — a stored "Court 1" against the real
            // "Court 1 · Central Show Court" row). A row that still can't be
            // matched keeps court_id NULL; it is most likely an Open Play
            // session (see the PKL-OP- id prefix) rather than a real court
            // reservation, and was never identified by a court row at all.
            $courtId = null;
            $needle  = strtolower(trim((string)($row['court_name'] ?? '')));
            if ($needle !== '') {
                foreach ($courtsByFacility[$facilityId] as $c) {
                    if (strtolower(trim((string)($c['name'] ?? ''))) === $needle) {
                        $courtId = $c['id'];
                        break;
                    }
                }
                if ($courtId === null) {
                    foreach ($courtsByFacility[$facilityId] as $c) {
                        $cName = strtolower(trim((string)($c['name'] ?? '')));
                        if ($cName !== '' && (str_starts_with($cName, $needle) || str_starts_with($needle, $cName))) {
                            $courtId = $c['id'];
                            break;
                        }
                    }
                }
            }

            $bookingDate = $this->resolveDisplayDate((string)($row['date'] ?? ''));
            $range = $this->parseTimeRange((string)($row['time'] ?? ''));

            $update->execute([
                $courtId,
                $bookingDate,
                $range[0] ?? null,
                $range[1] ?? null,
                $row['id'],
            ]);
        }
    }

    private function columnExists(string $table, string $column): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
        );
        $stmt->execute([$table, $column]);
        return (int)($stmt->fetch()['c'] ?? 0) > 0;
    }

    private function indexExists(string $table, string $index): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) c FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?"
        );
        $stmt->execute([$table, $index]);
        return (int)($stmt->fetch()['c'] ?? 0) > 0;
    }

    private function initJSONSchema() {
        $files = ['users', 'facilities', 'courts', 'staff', 'matches', 'bookings', 'wallet_transactions', 'feed_posts', 'feed_likes', 'feed_comments', 'direct_messages', 'notifications', 'owner_applications', 'promo_codes', 'promo_redemptions', 'court_images', 'amenities', 'facility_amenities', 'facility_favorites'];
        foreach ($files as $f) {
            $path = $this->dataDir . '/' . $f . '.json';
            if (!file_exists($path)) {
                file_put_contents($path, json_encode([], JSON_PRETTY_PRINT));
            }
        }
    }

    public function getJSONData($file) {
        $path = $this->dataDir . '/' . $file . '.json';
        if (!file_exists($path)) return [];
        $fp = @fopen($path, 'r');
        if (!$fp) return [];
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return json_decode($content, true) ?: [];
    }

    public function saveJSONData($file, $data) {
        $path = $this->dataDir . '/' . $file . '.json';
        $tempPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $payload = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($tempPath, $payload, LOCK_EX) !== false) {
            rename($tempPath, $path);
        } else {
            file_put_contents($path, $payload, LOCK_EX);
        }
    }

    /**
     * Atomically read → transform → write a JSON flat-file under an exclusive
     * flock so no two concurrent requests can interleave their read-modify-write
     * cycles. The callable receives the current array and must return the new
     * array; use a closure with `&$result` to surface a side-channel value.
     */
    public function lockedJSONUpdate(string $file, callable $fn): void
    {
        $path = $this->dataDir . '/' . $file . '.json';
        $fp = @fopen($path, 'c+');
        if (!$fp) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $raw  = stream_get_contents($fp);
            $data = json_decode((string)$raw, true);
            $data = is_array($data) ? $data : [];

            $updated = $fn($data);
            if (!is_array($updated)) {
                return;
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(array_values($updated), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fp);
        } finally {
            if (is_resource($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
    }

    // --------------------------------------------------------------------------
    // Seed Demo Data
    // --------------------------------------------------------------------------
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
        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM users WHERE id <> 'usr_dar'");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO users (id, name, email, phone, password_hash, role, verification_status, avatar_url, level, gold, silver, bronze, wallet_balance, is_admin, is_dev, is_owner) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($defaultUsers as $u) {
                $ins->execute([
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM facilities");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO facilities (id, owner_id, name, location, rating, reviews, price, price_numeric, type, hours, transit, image, courts_count, is_verified) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($defaultFacilities as $f) {
                $ins->execute([
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM courts");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO courts (id, facility_id, name, surface, type, price, status, occupied_by, occupied_until) VALUES (?,?,?,?,?,?,?,?,?)");
            foreach ($defaultCourts as $c) {
                $ins->execute([
                    $c['id'], $c['facility_id'], $c['name'], $c['surface'], $c['type'],
                    $c['price'], $c['status'], $c['occupied_by'], $c['occupied_until']
                ]);
            }
        } else {
            try {
                $cRows = $this->pdo->query("SELECT id, name FROM courts")->fetchAll();
                $upd = $this->pdo->prepare("UPDATE courts SET name = ? WHERE id = ?");
                foreach ($cRows as $cr) {
                    $norm = self::normalizeCourtName((string)($cr['name'] ?? ''));
                    if ($norm !== ($cr['name'] ?? '')) {
                        $upd->execute([$norm, $cr['id']]);
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM matches");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host, title) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($defaultMatches as $m) {
                $ins->execute([
                    $m['id'], $m['facility_id'], $m['facility_name'], $m['location'], $m['date'],
                    $m['time'], $m['level'], $m['current_players'], $m['max_players'], $m['price'],
                    $m['type'], $m['host'], $m['title'] ?? null
                ]);
            }
        }

        // 5. Seed Bookings (Empty default)
        $defaultBookings = [];

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM bookings");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO bookings (id, user_id, facility_id, facility_name, court_name, date, time, duration, price, payment_method, status, is_new, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($defaultBookings as $b) {
                $ins->execute([
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM wallet_transactions");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO wallet_transactions (id, user_id, type, amount, label, date, created_at) VALUES (?,?,?,?,?,?,?)");
            foreach ($defaultTx as $t) {
                $ins->execute([$t['id'], $t['user_id'], $t['type'], $t['amount'], $t['label'], $t['date'], $t['created_at']]);
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM feed_posts");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO feed_posts (id, author_id, author_name, author_avatar, author_level, content, image_url, post_type, like_count, comment_count, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($defaultPosts as $p) {
                $ins->execute([
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM notifications");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO notifications (id, user_id, title, body, type, is_read, time, created_at) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($defaultNotifs as $n) {
                $ins->execute([$n['id'], $n['user_id'], $n['title'], $n['body'], $n['type'], $n['is_read'], $n['time'], $n['created_at']]);
            }
        }

        // 8b. Seed Staff
        $defaultStaff = [
            ['id' => 'st_1', 'facility_id' => '1', 'name' => 'Maria Santos', 'email' => 'maria@picklersbgc.com', 'role' => 'Front Desk Receptionist', 'status' => 'Active', 'date_joined' => 'Jan 2026'],
            ['id' => 'st_2', 'facility_id' => '1', 'name' => 'Carlos Reyes', 'email' => 'carlos@picklersbgc.com', 'role' => 'Court Manager', 'status' => 'Active', 'date_joined' => 'Nov 2025'],
            ['id' => 'st_3', 'facility_id' => '1', 'name' => 'Juan dela Cruz', 'email' => 'juan@picklersbgc.com', 'role' => 'Tournament Coordinator', 'status' => 'Active', 'date_joined' => 'Mar 2026']
        ];

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM staff");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO staff (id, facility_id, name, email, role, status, date_joined) VALUES (?,?,?,?,?,?,?)");
            foreach ($defaultStaff as $st) {
                $ins->execute([$st['id'], $st['facility_id'], $st['name'], $st['email'], $st['role'], $st['status'], $st['date_joined']]);
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM court_images");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO court_images (facility_id, url, alt_text, sort_order, is_primary) VALUES (?,?,?,?,?)");
            foreach ($defaultCourtImages as $img) {
                $ins->execute([$img['facility_id'], $img['url'], $img['alt_text'], $img['sort_order'], $img['is_primary']]);
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM amenities");
        if ($stmt->fetch()['c'] == 0) {
            $ins = $this->pdo->prepare("INSERT INTO amenities (id, name, icon) VALUES (?,?,?)");
            foreach ($defaultAmenities as $a) {
                $ins->execute([$a['id'], $a['name'], $a['icon']]);
            }
        }
        $stmtFa = $this->pdo->query("SELECT COUNT(*) as c FROM facility_amenities");
        if ($stmtFa->fetch()['c'] == 0) {
            $insFa = $this->pdo->prepare("INSERT INTO facility_amenities (facility_id, amenity_id) VALUES (?,?)");
            foreach ($defaultFacilityAmenities as $fa) {
                $insFa->execute([$fa['facility_id'], $fa['amenity_id']]);
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

        $stmt = $this->pdo->query("SELECT COUNT(*) as c FROM promo_codes");
        if ($stmt->fetch()['c'] == 0) {
            foreach ($defaultPromos as $p) {
                $this->createPromoCode($p);
            }
        }

        @file_put_contents($seedFlag, date('c'));
    }

    // ==========================================================================
    // Public Data Access & Business Logic
    // ==========================================================================

    public function getCourtImagesByFacility($facilityId): array {
        $cacheKey = 'images:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->pdo->prepare("SELECT * FROM court_images WHERE facility_id = ? ORDER BY is_primary DESC, sort_order ASC, id ASC");
        $stmt->execute([$facilityId]);
        $result = $stmt->fetchAll();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    public function getFacilityAmenities($facilityId): array {
        $cacheKey = 'amenities:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.* FROM amenities a
             JOIN facility_amenities fa ON a.id = fa.amenity_id
             WHERE fa.facility_id = ?
             ORDER BY a.id ASC"
        );
        $stmt->execute([$facilityId]);
        $result = $stmt->fetchAll();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /** The fixed catalog every court attribute is drawn from, in display order. */
    public function getCourtAttributeCatalog(): array {
        $cacheKey = 'court_attribute_catalog';
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->pdo->query("SELECT * FROM court_attributes ORDER BY sort_order ASC")->fetchAll();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /** A single court's own attribute rows (slug/label/icon/kind), in display order. */
    public function getCourtAttributes(string $courtId): array {
        $cacheKey = 'court_attrs:' . $courtId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $stmt = $this->pdo->prepare(
            "SELECT a.slug, a.label, a.icon, a.kind FROM court_attributes a
             JOIN court_attribute_map m ON m.attribute_id = a.id
             WHERE m.court_id = ?
             ORDER BY a.sort_order ASC"
        );
        $stmt->execute([$courtId]);
        $result = $stmt->fetchAll();
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Every court at a facility, each with its own attribute rows attached
     * (as 'attributes' and a flat 'attribute_slugs' list) — one query for
     * the courts, one for every attribute across all of them, never one
     * query per court.
     */
    public function getCourtsWithAttributes(int|string $facilityId): array {
        $courts = $this->getCourtsByFacility($facilityId);
        if ($courts === []) {
            return [];
        }

        $ids = array_column($courts, 'id');
        $byCourt = [];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT m.court_id, a.slug, a.label, a.icon, a.kind FROM court_attribute_map m
             JOIN court_attributes a ON a.id = m.attribute_id
             WHERE m.court_id IN ($placeholders)
             ORDER BY a.sort_order ASC"
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) {
            $byCourt[$row['court_id']][] = ['slug' => $row['slug'], 'label' => $row['label'], 'icon' => $row['icon'], 'kind' => $row['kind']];
        }

        foreach ($courts as &$c) {
            $c['attributes'] = $byCourt[$c['id']] ?? [];
            $c['attribute_slugs'] = array_column($c['attributes'], 'slug');
        }
        unset($c);

        usort($courts, function($a, $b) {
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $courts;
    }

    /**
     * Replace a court's attribute set atomically. Unknown slugs are
     * silently ignored rather than erroring — a stale client sending a slug
     * this deployment's catalog doesn't recognise should not break the rest
     * of a legitimate edit.
     *
     * @param string[] $slugs
     */
    public function setCourtAttributes(string $courtId, array $slugs): void {
        $this->invalidateReadCache();
        $this->bumpSync('courts', 'facilities'); // attribute chips render on Discover's facility card

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("DELETE FROM court_attribute_map WHERE court_id = ?")->execute([$courtId]);

            if ($slugs !== []) {
                $placeholders = implode(',', array_fill(0, count($slugs), '?'));
                $stmt = $this->pdo->prepare("SELECT id FROM court_attributes WHERE slug IN ($placeholders)");
                $stmt->execute(array_values($slugs));
                $attrIds = $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);

                $ins = $this->pdo->prepare("INSERT IGNORE INTO court_attribute_map (court_id, attribute_id) VALUES (?, ?)");
                foreach ($attrIds as $attrId) {
                    $ins->execute([$courtId, $attrId]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Discover-tab filtering by court attributes. HAVING COUNT(DISTINCT ...)
     * = count($slugs) gives AND semantics — a facility must have a court
     * carrying EVERY requested tag (not necessarily the SAME court for all
     * of them, which matches how the filter UI presents this: "show venues
     * with an Outdoor court and a Covered court", not "one court that is
     * both").
     *
     * @param string[] $slugs
     */
    public function getFacilitiesByAttributes(array $slugs, string $search = ''): array {
        $slugs = array_values(array_unique(array_filter($slugs)));
        if ($slugs === []) {
            return $this->getFacilities($search);
        }

        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $params = $slugs;

        $sql = "SELECT f.*,
                       COUNT(DISTINCT c.id) AS real_courts_count,
                       COALESCE(MIN(c.price), f.price_numeric) AS min_price,
                       COALESCE(MAX(c.price), f.price_numeric) AS max_price
                  FROM facilities f
                  JOIN courts c              ON c.facility_id = f.id
                  JOIN court_attribute_map m ON m.court_id = c.id
                  JOIN court_attributes a    ON a.id = m.attribute_id
                 WHERE a.slug IN ($placeholders)";

        if ($search !== '') {
            $sql .= " AND (f.name LIKE ? OR f.location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY f.id HAVING COUNT(DISTINCT a.slug) = ? ORDER BY f.rating DESC, f.id ASC";
        $params[] = count($slugs);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (isset($r['real_courts_count']) && (int)$r['real_courts_count'] > 0) {
                $r['courts_count'] = (int)$r['real_courts_count'];
            }
        }
        unset($r);
        return $rows;
    }

    // Users
    public function getUserById($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getUserByEmailOrPhone($identifier) {
        $identifier = (string)$identifier;
        // Phones are stored as "+63 9xx xxx xxxx" (what the app's inputs
        // produce). The identifier's canonical form is matched as well, so any
        // typing of the same number finds the account.
        $phones = [$identifier];
        $canonicalPhone = \Picklers\Helpers\Format::phMobile($identifier);
        if ($canonicalPhone !== null && $canonicalPhone !== $identifier) {
            $phones[] = $canonicalPhone;
        }
        $phonePlaceholders = implode(',', array_fill(0, count($phones), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE email = ? OR phone IN ($phonePlaceholders) LIMIT 1");
        $stmt->execute(array_merge([$identifier], $phones));
        return $stmt->fetch() ?: null;
    }

    public function createUser($data) {
        $id = $data['id'] ?? ('usr_' . bin2hex(random_bytes(6)));
        $name = $data['name'] ?? 'Pickler';
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $password = $data['password_hash'] ?? (isset($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null);
        $role = $data['role'] ?? 'player';
        // New accounts are NOT trusted by default. Verification is granted by an
        // administrator (or requested by the user), never auto-issued at signup —
        // otherwise the verified badge carries no meaning at all.
        $verification = 'unverified';
        $avatar = $data['avatar_url'] ?? '';
        $level = 'Beginner 2.5';
        $gold = 0;
        $silver = 0;
        $bronze = 0;
        // Sign-up bonus is real spending power — never minted in production regardless
        // of SIGNUP_BONUS_CREDITS, mirroring the same fail-closed gate top_up uses.
        $env    = strtolower((string)($_ENV['APP_ENV'] ?? 'production'));
        $isProd = in_array($env, ['production', 'prod', 'live'], true);
        $wallet = $isProd ? 0.0 : max(0.0, min(500.0, (float)($_ENV['SIGNUP_BONUS_CREDITS'] ?? 0)));
        $isAdmin = ($role === 'admin') ? 1 : 0;
        $isDev = ($role === 'dev' || $role === 'admin') ? 1 : 0;
        $isOwner = ($role === 'owner') ? 1 : 0;

        $stmt = $this->pdo->prepare("INSERT INTO users (id, name, email, phone, password_hash, role, verification_status, avatar_url, level, gold, silver, bronze, wallet_balance, is_admin, is_dev, is_owner) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$id, $name, $email, $phone, $password, $role, $verification, $avatar, $level, $gold, $silver, $bronze, $wallet, $isAdmin, $isDev, $isOwner]);

        return $this->getUserById($id);
    }

    public function updateUser($id, $fields) {
        $allowedColumns = [
            'name', 'email', 'phone', 'password_hash', 'role', 'verification_status',
            'avatar_url', 'level', 'gold', 'silver', 'bronze', 'wallet_balance',
            'is_admin', 'is_dev', 'is_owner', 'password_changed_at'
        ];
        $cleanFields = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowedColumns, true)) {
                $cleanFields[$k] = $v;
            }
        }
        // Added by a migration; ignored until that migration has run.
        if (array_key_exists('password_changed_at', $cleanFields) && !$this->hasColumn('users', 'password_changed_at')) {
            unset($cleanFields['password_changed_at']);
        }
        if (empty($cleanFields)) {
            return $this->getUserById($id);
        }

        $set = [];
        $vals = [];
        foreach ($cleanFields as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $id;
        $sql = "UPDATE users SET " . implode(", ", $set) . " WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($vals);


        return $this->getUserById($id);
    }

    public function deleteUser($id) {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);


        return true;
    }

    public function getAllUsers() {
        $stmt = $this->pdo->query("SELECT id, name, email, phone, role, verification_status, avatar_url, level, wallet_balance, is_admin, is_dev, is_owner, created_at FROM users ORDER BY created_at DESC");
        return $stmt->fetchAll();
    }

    public function getUsers() {
        return $this->getAllUsers();
    }

    // Facilities & Courts
    public function getFacilities($search = '', $type = 'All', $sort = 'recommended') {
        $cacheKey = 'facilities:' . $search . '|' . $type . '|' . $sort;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getFacilitiesUncached($search, $type, $sort);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    private function getFacilitiesUncached($search = '', $type = 'All', $sort = 'recommended') {
        $sql = "SELECT f.*, 
                       COUNT(DISTINCT c.id) as real_courts_count,
                       COALESCE(MIN(c.price), f.price_numeric) as min_price, 
                       COALESCE(MAX(c.price), f.price_numeric) as max_price,
                       COALESCE(AVG(c.price), f.price_numeric) as avg_price
                FROM facilities f
                LEFT JOIN courts c ON f.id = c.facility_id
                WHERE f.is_verified = 1 AND f.image IS NOT NULL AND f.image != ''"
                // Facilities suspended by Picklers admins are not offered to players.
                . ($this->hasColumn('facilities', 'operating_status') ? " AND f.operating_status <> 'suspended'" : '');
        $params = [];
        if (!empty($search)) {
            $sql .= " AND (f.name LIKE ? OR f.location LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($type !== 'All') {
            $sql .= " AND f.type LIKE ?";
            $params[] = "%$type%";
        }
        $sql .= " GROUP BY f.id";
        if ($sort === 'price_asc') {
            $sql .= " ORDER BY min_price ASC";
        } elseif ($sort === 'rating_desc') {
            $sql .= " ORDER BY f.rating DESC";
        } else {
            $sql .= " ORDER BY f.id ASC";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (isset($r['real_courts_count']) && (int)$r['real_courts_count'] > 0) {
                $r['courts_count'] = (int)$r['real_courts_count'];
            }
        }
        unset($r);
        return $rows;
    }

    public function getFacility($id) {
        $cacheKey = 'facility:' . (string)$id;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getFacilityUncached($id);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    private function getFacilityUncached($id) {
        $stmt = $this->pdo->prepare("SELECT f.*, 
                       COALESCE(MIN(c.price), f.price_numeric) as min_price, 
                       COALESCE(MAX(c.price), f.price_numeric) as max_price
                FROM facilities f
                LEFT JOIN courts c ON f.id = c.facility_id
                WHERE f.id = ?
                GROUP BY f.id");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function updateFacility(int|string $facilityId, array $fields): bool {
        $allowed = [
            'name', 'location', 'hours', 'type', 'transit', 'image', 'price', 'price_numeric',
            'gcash_number', 'maya_number', 'gcash_enabled', 'maya_enabled', 'cash_on_site',
        ];
        $clean = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $clean[$k] = $v;
            }
        }
        if (empty($clean)) {
            return false;
        }

        $this->invalidateReadCache();
        $this->bumpSync('facilities');
        $facilityId = (int)$facilityId;

        $set = [];
        $vals = [];
        foreach ($clean as $k => $v) {
            $set[] = "`$k` = ?";
            $vals[] = $v;
        }
        $vals[] = $facilityId;
        $stmt = $this->pdo->prepare("UPDATE facilities SET " . implode(", ", $set) . " WHERE id = ?");
        return $stmt->execute($vals);
    }

    public function getCourtsByFacility($facilityId) {
        $cacheKey = 'courts:' . (string)$facilityId;
        if (array_key_exists($cacheKey, $this->readCache)) {
            return $this->readCache[$cacheKey];
        }
        $result = $this->getCourtsByFacilityUncached($facilityId);
        $this->readCache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Strictly normalizes court names to "Court 1", "Court 2", "Court 3", etc.
     * Strips any legacy sub-titles or extra appended names (e.g. "Court 1 · Main Arena" -> "Court 1").
     */
    public static function normalizeCourtName(?string $name): string {
        if ($name === null || trim($name) === '') return 'Court 1';
        $trimmed = trim($name);
        $clean = trim(preg_replace('/\s*[\-·•].*$/', '', $trimmed));
        if (preg_match('/\b\d+\b/', $clean, $matches)) {
            return "Court " . (int)$matches[0];
        }
        if (preg_match('/court\s*([a-z])\b/i', $clean, $matches)) {
            $num = ord(strtoupper($matches[1])) - 64;
            if ($num >= 1 && $num <= 26) {
                return "Court " . $num;
            }
        }
        if (preg_match('/^[a-z]$/i', $clean, $matches)) {
            $num = ord(strtoupper($matches[0])) - 64;
            if ($num >= 1 && $num <= 26) {
                return "Court " . $num;
            }
        }
        return 'Court 1';
    }

    private function getCourtsByFacilityUncached($facilityId) {
        $stmt = $this->pdo->prepare("SELECT * FROM courts WHERE facility_id = ?");
        $stmt->execute([$facilityId]);
        $courts = $stmt->fetchAll();

        $todayStr = date('Y-m-d');
        $nowMin = ((int)date('G') * 60) + (int)date('i');

        // Fetch active confirmed bookings for this facility today (excluding pending requests)
        $activeBookingsToday = [];
        $bStmt = $this->pdo->prepare(
            "SELECT b.*, u.name as user_name, u.avatar_url as user_avatar FROM bookings b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.facility_id = ? AND b.booking_date = ?
               AND b.status IN ('confirmed', 'completed', 'upcoming')
               AND (b.ended_early = 0 OR b.ended_early IS NULL)"
        );
        $bStmt->execute([$facilityId, $todayStr]);
        $activeBookingsToday = $bStmt->fetchAll();

        $matches = $this->getMatchesByFacility($facilityId);

        foreach ($courts as &$c) {
            $cId = (string)($c['id'] ?? '');
            $cName = trim((string)($c['name'] ?? ''));
            $cStatusInDb = strtolower(trim((string)($c['status'] ?? 'available')));

            // Respect manual owner/admin overrides ('maintenance', 'unavailable')
            if (in_array($cStatusInDb, ['maintenance', 'unavailable'], true)) {
                $c['status'] = $cStatusInDb;
                $c['occupied_by'] = null;
                $c['occupied_until'] = null;
                continue;
            }

            $currentBooking = null;
            $nextBooking = null;
            $nextBookingStart = 99999;
            $upcomingList = [];
            $completedList = [];

            foreach ($activeBookingsToday as $b) {
                $sameCourt = false;
                if ($cId !== '' && !empty($b['court_id']) && (string)$b['court_id'] === $cId) {
                    $sameCourt = true;
                } elseif ($cName !== '' && !empty($b['court_name']) && strcasecmp(trim((string)$b['court_name']), $cName) === 0) {
                    $sameCourt = true;
                }

                if ($sameCourt) {
                    $sMin = isset($b['start_min']) && $b['start_min'] !== null ? (int)$b['start_min'] : null;
                    $eMin = isset($b['end_min']) && $b['end_min'] !== null ? (int)$b['end_min'] : null;
                    if ($sMin === null || $eMin === null) {
                        $range = $this->parseTimeRange((string)($b['time'] ?? ''));
                        if ($range) {
                            $sMin = $range[0];
                            $eMin = $range[1];
                        }
                    }

                    if ($sMin !== null && $eMin !== null) {
                        if ($nowMin >= $sMin && $nowMin < $eMin) {
                            $currentBooking = $b;
                            $currentBooking['_start_min'] = $sMin;
                            $currentBooking['_end_min'] = $eMin;
                        } elseif ($sMin > $nowMin) {
                            $upcomingList[] = [
                                'user_name' => !empty($b['user_name']) ? $b['user_name'] : ($b['author_name'] ?? 'Player'),
                                'user_avatar' => !empty($b['user_avatar']) ? $b['user_avatar'] : ($b['avatar_url'] ?? ($b['avatar'] ?? null)),
                                'time' => (string)($b['time'] ?? ''),
                                'status' => (string)($b['status'] ?? 'confirmed')
                            ];
                            if ($sMin < $nextBookingStart) {
                                $nextBooking = $b;
                                $nextBookingStart = $sMin;
                            }
                        } elseif ($eMin <= $nowMin) {
                            $completedList[] = [
                                'user_name' => !empty($b['user_name']) ? $b['user_name'] : ($b['author_name'] ?? 'Player'),
                                'user_avatar' => !empty($b['user_avatar']) ? $b['user_avatar'] : ($b['avatar_url'] ?? ($b['avatar'] ?? null)),
                                'time' => (string)($b['time'] ?? ''),
                                'status' => 'completed'
                            ];
                        }
                    }
                }
            }

            $c['upcoming_bookings'] = $upcomingList;
            $c['completed_bookings'] = $completedList;

            if ($nextBooking) {
                $c['next_booking'] = [
                    'user_name' => !empty($nextBooking['user_name']) ? $nextBooking['user_name'] : ($nextBooking['author_name'] ?? 'Player'),
                    'time' => (string)($nextBooking['time'] ?? ''),
                    'status' => (string)($nextBooking['status'] ?? 'confirmed')
                ];
            } else {
                $c['next_booking'] = null;
            }

            // Check if there is an unexpired Open Play session active on this court
            $currentMatch = null;
            if (!empty($matches)) {
                foreach ($matches as $m) {
                    if (self::isMatchExpired($m)) continue;
                    $mType = trim((string)($m['type'] ?? ''));
                    $mCourtId = trim((string)($m['court_id'] ?? ''));
                    $mCourtName = trim((string)($m['court_name'] ?? ''));

                    $isMatchForCourt = false;
                    if ($cId !== '' && $mCourtId !== '' && $mCourtId === $cId) {
                        $isMatchForCourt = true;
                    } elseif ($cName !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $cName) === 0) {
                        $isMatchForCourt = true;
                    } elseif ($cName !== '' && strcasecmp($mType, $cName) === 0) {
                        $isMatchForCourt = true;
                    } elseif (count($courts) === 1) {
                        $isMatchForCourt = true;
                    }

                    if ($isMatchForCourt) {
                        $currentMatch = $m;
                        break;
                    }
                }
            }

            if ($currentMatch) {
                $c['status'] = 'occupied';
                $c['occupied_by'] = 'Hosted Open Play';
                $c['occupied_until'] = (string)($currentMatch['time'] ?? null);
                $c['player_avatar'] = !empty($currentMatch['user_avatar']) ? $currentMatch['user_avatar'] : ($currentMatch['host_avatar'] ?? ($currentMatch['avatar_url'] ?? ($currentMatch['avatar'] ?? null)));
                $c['has_open_play'] = true;
                $c['open_play_match'] = $currentMatch;
                $c['open_play_id'] = $currentMatch['id'] ?? null;
                continue;
            }

            if ($currentBooking) {
                $c['status'] = 'occupied';
                $c['occupied_by'] = !empty($currentBooking['user_name']) ? $currentBooking['user_name'] : ($c['occupied_by'] ?? 'Reserved');
                $c['occupied_until'] = (string)($currentBooking['time'] ?? $this->minutesToLabel($currentBooking['_end_min']));
                $c['player_avatar'] = !empty($currentBooking['user_avatar']) ? $currentBooking['user_avatar'] : ($currentBooking['avatar_url'] ?? ($currentBooking['avatar'] ?? null));
                $c['start_min'] = $currentBooking['_start_min'] ?? null;
                $c['end_min'] = $currentBooking['_end_min'] ?? null;
                continue;
            }

            // Default to available if no active booking or match applies at the current time
            $c['status'] = 'available';
            $c['occupied_by'] = null;
            $c['occupied_until'] = null;
        }
        unset($c);

        usort($courts, function($a, $b) {
            return strnatcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        });

        return $courts;
    }

    public function getAllCourts() {
        $stmt = $this->pdo->query("SELECT c.*, f.name as facility_name FROM courts c JOIN facilities f ON c.facility_id = f.id");
        return $stmt->fetchAll();
    }

    /**
     * Does this owner actually hold the facility a court belongs to?
     *
     * A facility with no owner set matches nobody.
     */
    public function verifyCourtOwner(string $courtId, string $ownerUserId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as c FROM courts c
             JOIN facilities f ON c.facility_id = f.id
             WHERE c.id = ? AND f.owner_id = ?"
        );
        $stmt->execute([$courtId, $ownerUserId]);
        return (int)($stmt->fetch()['c'] ?? 0) > 0;
    }

    /**
     * The one and only vocabulary a court's status may hold. Three different
     * call sites once wrote three different dialects into this same column
     * (uppercase 'AVAILABLE'/'UNAVAILABLE' from the owner toggle, lowercase
     * 'available'/'occupied'/'maintenance' from Admin/API, and a player-side
     * reader that only ever recognised lowercase 'available') — so an owner
     * re-enabling a court could make it permanently unbookable to players.
     * Normalizing here, at the single choke point every write passes
     * through, closes that regardless of what casing any caller uses.
     */
    public const COURT_STATUSES = ['available', 'occupied', 'maintenance', 'unavailable'];

    public function normalizeCourtStatus(string $status): string {
        $s = strtolower(trim($status));
        return in_array($s, self::COURT_STATUSES, true) ? $s : 'unavailable';
    }

    public function updateCourtStatus($courtId, string $status): bool {
        $status = $this->normalizeCourtStatus($status);
        $this->invalidateReadCache();
        $this->bumpSync('courts');
        $stmt = $this->pdo->prepare("UPDATE courts SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $courtId]);
    }

    public function updateCourtStatusByNameOrId($facilityId, string $courtName, string $status = 'available'): bool {
        $status = $this->normalizeCourtStatus($status);
        $this->invalidateReadCache();
        $this->bumpSync('courts');
        $facilityId = (int)$facilityId;
        $stmt = $this->pdo->prepare("UPDATE courts SET status = ? WHERE facility_id = ? AND (name = ? OR id = ?)");
        return $stmt->execute([$status, $facilityId, $courtName, $courtName]);
    }

    public function occupyCourt($facilityId, $courtIdOrName, string $playerName, ?string $timeRange = null): bool {
        $status = 'OCCUPIED';
        $this->invalidateReadCache();
        $this->bumpSync('courts');
        $facilityId = (int)$facilityId;
        $stmt = $this->pdo->prepare("UPDATE courts SET status = ?, occupied_by = ?, occupied_until = ? WHERE (id = ? OR (facility_id = ? AND name = ?))");
        return $stmt->execute([$status, $playerName, $timeRange, $courtIdOrName, $facilityId, $courtIdOrName]);
    }

    public function clearCourtSession($courtId, $facilityId = null): bool {
        $status = 'AVAILABLE';
        $this->invalidateReadCache();
        $this->bumpSync('courts');
        $stmt = $this->pdo->prepare("UPDATE courts SET status = ?, occupied_by = NULL, occupied_until = NULL WHERE id = ? OR (facility_id = ? AND name = ?)");
        return $stmt->execute([$status, $courtId, (int)$facilityId, $courtId]);
    }

    /**
     * Ends whichever real, timed booking is currently occupying a court,
     * right now, without waiting for its original end time.
     *
     * getCourtsByFacilityUncached() derives "occupied" purely from a
     * booking's own start/end time window on every read — toggling the
     * court's own status row (the old "End Session Early" implementation)
     * touched a field nothing here ever reads for this case, so the court
     * silently went right back to "occupied" on the very next dashboard
     * load. This instead flags the actual active booking itself
     * (ended_early = 1, see the column's doc comment on the CREATE TABLE)
     * so it's excluded from that time-window match from now on. Status,
     * price, and the original time range are left untouched — the player
     * paid for and was entitled to the full slot; this is not a
     * cancellation and carries no refund.
     *
     * @return array{success:bool, message:string, booking?:array}
     */
    public function endCourtSessionEarly(int|string $facilityId, string $courtId, string $courtName): array {
        $this->invalidateReadCache();
        $todayStr = date('Y-m-d');
        $nowMin = ((int)date('G') * 60) + (int)date('i');

        $findActive = function (array $rows) use ($nowMin): ?array {
            foreach ($rows as $row) {
                $sMin = isset($row['start_min']) && $row['start_min'] !== null ? (int)$row['start_min'] : null;
                $eMin = isset($row['end_min']) && $row['end_min'] !== null ? (int)$row['end_min'] : null;
                if ($sMin === null || $eMin === null) {
                    $range = $this->parseTimeRange((string)($row['time'] ?? ''));
                    if ($range) {
                        $sMin = $range[0];
                        $eMin = $range[1];
                    }
                }
                if ($sMin !== null && $eMin !== null && $nowMin >= $sMin && $nowMin < $eMin) {
                    return $row;
                }
            }
            return null;
        };

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                "SELECT * FROM bookings
                 WHERE facility_id = ? AND booking_date = ? AND status IN ('pending', 'confirmed')
                   AND (ended_early = 0 OR ended_early IS NULL)
                   AND (court_id = ? OR court_name = ?)
                 FOR UPDATE"
            );
            $stmt->execute([$facilityId, $todayStr, $courtId, $courtName]);
            $target = $findActive($stmt->fetchAll());

            if ($target === null) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'No active session found for this court right now.'];
            }

            $this->pdo->prepare("UPDATE bookings SET ended_early = 1 WHERE id = ?")->execute([$target['id']]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[DB Error] endCourtSessionEarly failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Could not end this session. Please try again.'];
        }

        $this->bumpSync('courts', 'bookings');
        return [
            'success' => true,
            'message' => 'Session ended. Court is free for a new booking.',
            'booking' => $target,
        ];
    }

    /**
     * Whether a booking's play window has already elapsed. Nothing in this
     * codebase ever writes status='completed' on a booking — updateBookingStatus()
     * is only ever called with 'confirmed' or 'cancelled' — so the player's own
     * "Completed" Bookings tab (views/partials/app/_tab-bookings.php, filtering
     * on that exact string) has always been empty, and a booking from weeks ago
     * sits in "Upcoming" forever. This computes it the same way isMatchExpired()
     * does for Open Play sessions, so a booking moves to Completed the moment
     * its window ends — including immediately, for one flagged ended_early.
     */
    public function isBookingPast(array $booking): bool {
        return \Picklers\Domain\BookingRules::isBookingPast($booking);
    }

    /**
     * Real-time "your time is up" alert for one player.
     *
     * There is no cron/daemon in this app (see the class doc comment on
     * dual-mode storage — it's a plain request/response PHP app), so this is
     * checked on every 'sync' poll (see ApiController) for whichever player
     * is actively polling. Since the player app polls every ~12s while open
     * (ux-core.js's PickSync), this surfaces within seconds of a booking's
     * actual end time without needing a scheduled task — as long as the
     * player has the app open somewhere. A real background push (rings even
     * with the app fully closed) needs Web Push infrastructure — HTTPS, a
     * service worker, VAPID keys, and something to trigger it independent of
     * any open tab — which is a separate, larger piece of work.
     *
     * Fires exactly once per booking (end_alert_sent), both as a normal
     * notification (so it's there later even if missed) and as a returned
     * payload the client uses to actually ring/vibrate/alert immediately —
     * see 'ended_sessions' in the sync response and PickSync.onSessionEnded()
     * in ux-core.js.
     *
     * Scoped to today's bookings only so shipping this feature doesn't
     * suddenly "end-alert" every confirmed booking in history the first time
     * an existing user polls.
     *
     * @return array<int,array{booking_id:string,court_name:string,facility_name:string}>
     */
    public function checkAndNotifySessionEnd(string $userId): array {
        if ($userId === '') {
            return [];
        }
        $todayStr = date('Y-m-d');
        $alerts = [];

        $stmt = $this->pdo->prepare(
            "SELECT * FROM bookings
             WHERE user_id = ? AND booking_date = ? AND status = 'confirmed'
               AND (end_alert_sent = 0 OR end_alert_sent IS NULL)"
        );
        $stmt->execute([$userId, $todayStr]);
        $candidates = $stmt->fetchAll();

        foreach ($candidates as $b) {
            if (!$this->isBookingPast($b)) {
                continue;
            }
            $bookingId = (string)($b['id'] ?? '');
            if ($bookingId === '') {
                continue;
            }

            $this->pdo->prepare("UPDATE bookings SET end_alert_sent = 1 WHERE id = ?")->execute([$bookingId]);

            $courtName = (string)($b['court_name'] ?? 'your court');
            $facilityName = (string)($b['facility_name'] ?? 'the facility');
            $this->addNotification(
                $userId,
                '⏰ Your Time Is Up!',
                "Your session at {$courtName}, {$facilityName} has ended. Thanks for playing — see you again soon!",
                'session_ended'
            );

            $alerts[] = [
                'booking_id' => $bookingId,
                'court_name' => $courtName,
                'facility_name' => $facilityName,
            ];
        }

        return $alerts;
    }

    public function insertCourt(array $courtData): array {
        $this->invalidateReadCache();
        // 'facilities' too: Discover's facility card shows courts_count and
        // a min-max price range derived from the court list, so a new court
        // changes what that card should say without the facility row itself
        // changing.
        $this->bumpSync('courts', 'facilities');
        $id = $courtData['id'] ?? ('crt_' . bin2hex(random_bytes(4)));

        $court = [
            'id' => $id,
            'facility_id' => $courtData['facility_id'],
            'name' => self::normalizeCourtName((string)($courtData['name'] ?? '')),
            'surface' => $courtData['surface'] ?? 'Hard Court',
            'type' => $courtData['type'] ?? 'Indoor',
            'price' => $courtData['price'] ?? 450.00,
            'status' => $this->normalizeCourtStatus((string)($courtData['status'] ?? 'available')),
            'occupied_by' => $courtData['occupied_by'] ?? null,
            'occupied_until' => $courtData['occupied_until'] ?? null
        ];

        $stmt = $this->pdo->prepare(
            "INSERT INTO courts (id, facility_id, name, surface, type, price, status, occupied_by, occupied_until) VALUES (?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $court['id'], $court['facility_id'], $court['name'], $court['surface'],
            $court['type'], $court['price'], $court['status'], $court['occupied_by'], $court['occupied_until']
        ]);
        return $court;
    }

    public function updateCourt(string $courtId, array $fields): ?array {
        $this->invalidateReadCache();
        $this->bumpSync('courts', 'facilities'); // price/name changes affect the Discover card too
        $allowed = ['name', 'surface', 'type', 'price'];
        $updates = array_intersect_key($fields, array_flip($allowed));
        if (isset($updates['name'])) {
            $updates['name'] = self::normalizeCourtName((string)$updates['name']);
        }

        if (!empty($updates)) {
            $setParts = [];
            $params = [];
            foreach ($updates as $col => $val) {
                $setParts[] = "{$col} = ?";
                $params[] = $val;
            }
            $params[] = $courtId;
            $stmt = $this->pdo->prepare("UPDATE courts SET " . implode(', ', $setParts) . " WHERE id = ?");
            $stmt->execute($params);
        }
        $stmt = $this->pdo->prepare("SELECT * FROM courts WHERE id = ?");
        $stmt->execute([$courtId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getCourtById(string $courtId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM courts WHERE id = ?");
        $stmt->execute([$courtId]);
        return $stmt->fetch() ?: null;
    }

    /** Pending/confirmed bookings on one court that haven't been played yet. */
    public function countUpcomingCourtBookings(int|string $facilityId, string $courtId, string $courtName): int {
        $today = date('Y-m-d');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM bookings
              WHERE facility_id = ? AND status IN ('pending', 'confirmed')
                AND (booking_date IS NULL OR booking_date >= ?)
                AND (court_id = ? OR court_name = ?)"
        );
        $stmt->execute([$facilityId, $today, $courtId, $courtName]);
        $rows = $stmt->fetchAll();
        $count = 0;
        foreach ($rows as $row) {
            if (!$this->isBookingPast($row)) {
                $count++;
            }
        }
        return $count;
    }

    public function deleteCourt(string $courtId): bool {
        // Every other court write invalidates the read cache and bumps the
        // sync channels; a deleted court stayed on Discover until a reload.
        $this->invalidateReadCache();
        $this->bumpSync('courts', 'facilities');
        $stmt = $this->pdo->prepare("DELETE FROM courts WHERE id = ?");
        $stmt->execute([$courtId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->pdo->prepare("DELETE FROM court_attribute_map WHERE court_id = ?")->execute([$courtId]);
        }
        return $deleted;
    }

    // --------------------------------------------------------------------------
    // Staff Management
    // --------------------------------------------------------------------------
    public function getStaffByFacility(int|string $facilityId): array {
        $stmt = $this->pdo->prepare("
            SELECT s.*, COALESCE(u.avatar_url, '') as avatar_url 
            FROM staff s 
            LEFT JOIN users u ON ((s.user_id IS NOT NULL AND s.user_id != '' AND s.user_id = u.id) OR (LOWER(s.email) = LOWER(u.email) AND s.email != '')) 
            WHERE s.facility_id = ?
        ");
        $stmt->execute([$facilityId]);
        return $stmt->fetchAll();
    }

    public function getStaffFacilityIdsForUser(string $userId, string $email): array {
        $userId = trim($userId);
        $email = strtolower(trim($email));
        if ($userId === '' && $email === '') {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT facility_id 
            FROM staff 
            WHERE (user_id IS NOT NULL AND user_id != '' AND user_id = ?) 
               OR (email IS NOT NULL AND email != '' AND LOWER(email) = ?)
        ");
        $stmt->execute([$userId, $email]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'facility_id');
    }

    public function verifyStaffOwner(string $staffId, string $ownerUserId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as c FROM staff s
             JOIN facilities f ON s.facility_id = f.id
             WHERE s.id = ? AND f.owner_id = ?"
        );
        $stmt->execute([$staffId, $ownerUserId]);
        return (int)($stmt->fetch()['c'] ?? 0) > 0;
    }

    public function insertStaff(array $staffData): array {
        $id = $staffData['id'] ?? ('stf_' . bin2hex(random_bytes(4)));

        $staff = [
            'id' => $id,
            'facility_id' => $staffData['facility_id'],
            'name' => $staffData['name'] ?? $staffData['full_name'] ?? 'Staff Member',
            'email' => $staffData['email'] ?? 'staff@facility.com',
            'role' => $staffData['role'] ?? 'Front Desk',
            'status' => $staffData['status'] ?? 'Active',
            'date_joined' => $staffData['date_joined'] ?? date('M Y')
        ];

        $stmt = $this->pdo->prepare(
            "INSERT INTO staff (id, facility_id, name, email, role, status, date_joined) VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $staff['id'], $staff['facility_id'], $staff['name'], $staff['email'],
            $staff['role'], $staff['status'], $staff['date_joined']
        ]);
        return $staff;
    }

    public function deleteStaff(string $staffId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM staff WHERE id = ?");
        $stmt->execute([$staffId]);
        return $stmt->rowCount() > 0;
    }

    public static function getMatchTargetDate(array $match): string {
        return \Picklers\Domain\BookingRules::matchTargetDate($match);
    }

    public static function getMatchDisplayDate(array $match): string {
        $targetDate = self::getMatchTargetDate($match);
        if (!empty($targetDate) && strtotime($targetDate) !== false) {
            return date('F j, Y', strtotime($targetDate));
        }

        $dateStr = trim((string)($match['date'] ?? ''));
        if (!empty($dateStr) && strtotime($dateStr) !== false) {
            return date('F j, Y', strtotime($dateStr));
        }

        return $dateStr !== '' ? $dateStr : date('F j, Y');
    }

    public static function isMatchExpired(array $match): bool {
        $dateStr = trim((string)($match['date'] ?? ''));
        $timeStr = trim((string)($match['time'] ?? ''));

        if ($dateStr === '' || $timeStr === '') {
            return false;
        }

        $isEveryday = strcasecmp($dateStr, 'everyday') === 0 || strcasecmp($dateStr, 'daily') === 0
            || str_contains(strtolower($dateStr), 'everyday') || str_contains(strtolower($dateStr), 'daily');

        if ($isEveryday) {
            return false;
        }

        $ts = strtotime($dateStr);
        if ($ts === false) {
            return false;
        }
        $sessionDateStr = date('Y-m-d', $ts);

        $startTimeStr = '';
        $endTimeStr = '';
        if (str_contains($timeStr, '-')) {
            $parts = explode('-', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } elseif (str_contains($timeStr, '–')) {
            $parts = explode('–', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } elseif (str_contains($timeStr, 'to')) {
            $parts = explode('to', $timeStr);
            $startTimeStr = trim($parts[0]);
            $endTimeStr = trim(end($parts));
        } else {
            $endTimeStr = $timeStr;
        }

        $endTs = $endTimeStr !== '' ? strtotime($sessionDateStr . ' ' . $endTimeStr) : false;
        if ($endTs === false) {
            return $sessionDateStr < date('Y-m-d');
        }

        // A session running past midnight ("8:00 PM – 12:00 AM") ends on the
        // following day. Reading its end as the session day's own 12:00 AM
        // marked it expired the moment it was published, and a session from
        // late last night as over while it was still being played.
        $startTs = $startTimeStr !== '' ? strtotime($sessionDateStr . ' ' . $startTimeStr) : false;
        if ($startTs !== false && $endTs <= $startTs) {
            $endTs = strtotime('+1 day', $endTs);
        }

        return time() >= $endTs;
    }
    public function getMatchesByFacility(int|string $facilityId): array {
        $stmt = $this->pdo->prepare("SELECT * FROM matches WHERE facility_id = ?");
        $stmt->execute([$facilityId]);
        return $stmt->fetchAll();
    }

    public function insertMatch(array $matchData): array {
        $id = $matchData['id'] ?? ('op_' . bin2hex(random_bytes(4)));

        $match = [
            'id' => $id,
            'facility_id' => $matchData['facility_id'] ?? null,
            'facility_name' => $matchData['facility_name'] ?? '',
            'location' => $matchData['location'] ?? '',
            'date' => $matchData['date'] ?? '',
            'time' => $matchData['time'] ?? '',
            'level' => $matchData['level'] ?? 'Intermediate',
            'current_players' => $matchData['current_players'] ?? 0,
            'max_players' => $matchData['max_players'] ?? 4,
            'price' => $matchData['price'] ?? 150.00,
            'type' => $matchData['type'] ?? 'Doubles Open Play',
            'host' => trim((string)($matchData['host'] ?? '')) !== '' ? $matchData['host'] : 'Facility Staff',
            'title' => $matchData['title'] ?? null
        ];

        $stmt = $this->pdo->prepare(
            "INSERT INTO matches (id, facility_id, facility_name, location, date, time, level, current_players, max_players, price, type, host, title) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $match['id'], $match['facility_id'], $match['facility_name'], $match['location'],
            $match['date'], $match['time'], $match['level'], $match['current_players'],
            $match['max_players'], $match['price'], $match['type'], $match['host'], $match['title']
        ]);
        return $match;
    }

    public function getMatchById(string $matchId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM matches WHERE id = ?");
        $stmt->execute([$matchId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cancel Open Play session(s) at a facility. Boolean wrapper kept for
     * existing callers; see cancelOpenPlaySessions().
     */
    public function deleteMatch(string $matchId, int|string $facilityId, string $courtName = ''): bool {
        return $this->cancelOpenPlaySessions($matchId, $facilityId, $courtName)['deleted'];
    }

    /**
     * Remove Open Play session(s) and settle the players who joined them.
     *
     * Targets a session by id or title ($matchRef) and/or every session on one
     * court ($courtName), compared EXACTLY (case-insensitive). The previous
     * matcher used substring containment in both directions, so cancelling
     * "Court 1" also removed "Court 10"'s session (and an empty type matched
     * everything). It then cancelled EVERY non-cancelled booking whose
     * court_name matched — ordinary court reservations on any date included —
     * with no refund and no notice to anyone.
     *
     * Now only the removed sessions' own join bookings are touched, and only
     * the ones not yet played: each is cancelled, a Pickle Credits payment is
     * refunded to the wallet, and the player is notified.
     *
     * @return array{deleted:bool, sessions:int, cancelled_bookings:int, refunded_amount:float}
     */
    public function cancelOpenPlaySessions(string $matchRef, int|string $facilityId, string $courtName = ''): array {
        $matchRef  = trim($matchRef);
        $courtName = trim($courtName);
        $result = ['deleted' => false, 'sessions' => 0, 'cancelled_bookings' => 0, 'refunded_amount' => 0.0];
        if ($matchRef === '' && $courtName === '') {
            return $result;
        }

        $targets = [];
        foreach ($this->getMatchesByFacility($facilityId) as $m) {
            $mId    = (string)($m['id'] ?? '');
            $mTitle = trim((string)($m['title'] ?? ''));
            $mCourt = trim((string)($m['court_name'] ?? $m['type'] ?? ''));
            $byRef   = $matchRef !== '' && ($mId === $matchRef || ($mTitle !== '' && strcasecmp($mTitle, $matchRef) === 0));
            $byCourt = $courtName !== '' && $mCourt !== '' && strcasecmp($mCourt, $courtName) === 0;
            if ($mId !== '' && ($byRef || $byCourt)) {
                $targets[$mId] = $m;
            }
        }

        $courtsToReset = [];
        if ($courtName !== '') {
            $courtsToReset[strtolower($courtName)] = $courtName;
        }
        foreach ($targets as $m) {
            $cn = trim((string)($m['type'] ?? ''));
            if ($cn !== '') {
                $courtsToReset[strtolower($cn)] = $cn;
            }
        }

        if ($targets === []) {
            foreach ($courtsToReset as $cn) {
                $this->updateCourtStatusByNameOrId((int)$facilityId, $cn, 'available');
            }
            return $result;
        }

        $matchIds = array_keys($targets);
        $sessionCourts = array_values(array_filter(array_map(fn($m) => trim((string)($m['type'] ?? '')), $targets)));
        $settled = []; // bookings actually cancelled, for notifications after commit

        $settle = function (array $booking) use (&$result, &$settled): ?array {
            if ($this->isBookingPast($booking)) {
                return null; // already played — history stays as it was
            }
            $price = (float)($booking['price'] ?? 0);
            $refund = (($booking['payment_method'] ?? '') === 'Pickle Credits' && $price > 0) ? $price : 0.0;
            $result['cancelled_bookings']++;
            $result['refunded_amount'] = round($result['refunded_amount'] + $refund, 2);
            $settled[] = ['booking' => $booking, 'refund' => $refund];
            return ['refund' => $refund];
        };

        $this->pdo->beginTransaction();
        try {
            $idPh = implode(',', array_fill(0, count($matchIds), '?'));
            $sql = "SELECT * FROM bookings WHERE facility_id = ? AND status IN ('pending', 'confirmed')
                      AND (match_id IN ($idPh)";
            $params = array_merge([$facilityId], $matchIds);
            if ($sessionCourts !== []) {
                // Legacy join rows written before bookings carried match_id.
                $cPh = implode(',', array_fill(0, count($sessionCourts), '?'));
                $sql .= " OR (match_id IS NULL AND id LIKE 'PKL-OP-%' AND court_name IN ($cPh))";
                $params = array_merge($params, $sessionCourts);
            }
            $sql .= ") FOR UPDATE";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $cancel = $this->pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
            foreach ($stmt->fetchAll() as $booking) {
                $outcome = $settle($booking);
                if ($outcome === null) {
                    continue;
                }
                $cancel->execute([$booking['id']]);
                if ($outcome['refund'] > 0) {
                    $this->creditWalletOnce(
                        (string)$booking['user_id'],
                        number_format((float)$outcome['refund'], 2, '.', ''),
                        "Refund: Open Play #{$booking['id']} cancelled by facility",
                        'refund:booking:' . $booking['id'],
                        ['entry_kind' => 'refund', 'booking_id' => (string)$booking['id']]
                    );
                }
            }

            $del = $this->pdo->prepare("DELETE FROM matches WHERE facility_id = ? AND id IN ($idPh)");
            $del->execute(array_merge([$facilityId], $matchIds));
            $result['sessions'] = $del->rowCount();

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[DB Error] cancelOpenPlaySessions failed: ' . $e->getMessage());
            throw $e;
        }

        $result['deleted'] = $result['sessions'] > 0;

        foreach ($settled as $s) {
            $b = $s['booking'];
            $body = sprintf(
                'The Open Play session %s at %s (%s) was cancelled by the facility.',
                $b['court_name'] ?? '',
                $b['facility_name'] ?? 'the facility',
                trim(($b['booking_date'] ?? $b['date'] ?? '') . ' ' . ($b['time'] ?? ''))
            );
            $body .= $s['refund'] > 0
                ? ' ₱' . number_format($s['refund'], 2) . ' has been refunded to your Pickle Credits.'
                : ' Please contact the facility about your ' . ($b['payment_method'] ?? '') . ' payment.';
            $this->addNotification((string)$b['user_id'], 'Open Play Cancelled', $body, 'booking');
        }

        foreach ($courtsToReset as $cn) {
            $this->updateCourtStatusByNameOrId((int)$facilityId, $cn, 'available');
        }

        $this->invalidateReadCache();
        $this->bumpSync('matches', 'bookings', 'courts');

        return $result;
    }

    public function getOpenPlayRoster(int|string $facilityId, string $courtName, string $courtId = ''): array {
        $courtName = trim($courtName);
        $courtId = trim($courtId);

        // The roster is the join requests of the session currently running on
        // this exact court, aligned with getCourtsByFacilityUncached matching rules.
        $session = null;
        $allCourts = $this->getCourtsByFacility($facilityId);
        foreach ($this->getMatchesByFacility($facilityId) as $m) {
            if (self::isMatchExpired($m)) continue;
            $mType = trim((string)($m['type'] ?? ''));
            $mCourtId = trim((string)($m['court_id'] ?? ''));
            $mCourtName = trim((string)($m['court_name'] ?? ''));

            $isMatchForCourt = false;
            if ($courtId !== '' && $mCourtId !== '' && $mCourtId === $courtId) {
                $isMatchForCourt = true;
            } elseif ($courtName !== '' && $mCourtName !== '' && strcasecmp($mCourtName, $courtName) === 0) {
                $isMatchForCourt = true;
            } elseif ($courtName !== '' && $mType !== '' && strcasecmp($mType, $courtName) === 0) {
                $isMatchForCourt = true;
            } elseif (count($allCourts) === 1) {
                $isMatchForCourt = true;
            }

            if ($isMatchForCourt) {
                $session = $m;
                break;
            }
        }
        if ($session === null) {
            return [];
        }

        $sessionId = (string)($session['id'] ?? '');
        $sessionCourt = trim((string)($session['court_name'] ?? $session['type'] ?? $courtName));
        $sessionTitle = trim((string)($session['title'] ?? $session['type'] ?? ''));
        $sessionDate = strtolower(trim((string)($session['date'] ?? '')));
        $isRecurring = str_contains($sessionDate, 'everyday') || str_contains($sessionDate, 'daily');
        $targetDate = self::getMatchTargetDate($session);

        $stmt = $this->pdo->prepare(
            "SELECT b.id, b.user_id, b.status, b.booking_date, b.payment_method, b.price, b.created_at, b.court_name, b.date,
                    u.name, u.email, u.level, u.avatar_url
               FROM bookings b
          LEFT JOIN users u ON b.user_id = u.id
              WHERE b.facility_id = ? AND b.status IN ('pending', 'confirmed', 'completed')
                AND (
                     (b.match_id IS NOT NULL AND b.match_id != '' AND b.match_id = ?)
                  OR (b.id LIKE 'PKL-OP-%' AND (b.court_name = ? OR b.court_name = ? OR b.court_name = ?))
                )
           ORDER BY b.created_at DESC"
        );
        $stmt->execute([$facilityId, $sessionId, $sessionCourt, $courtName, $sessionTitle]);
        $rows = $stmt->fetchAll();

        $roster = [];
        $seenPlayers = [];
        foreach ($rows as $r) {
            // A recurring session is never recreated day to day: only the
            // current occurrence's players belong on today's roster.
            if ($isRecurring) {
                $rawDate = (string)($r['booking_date'] ?? $r['date'] ?? '');
                $day = '';
                if ($rawDate !== '') {
                    $day = $this->resolveDisplayDate($rawDate) ?? (strtotime($rawDate) !== false ? date('Y-m-d', strtotime($rawDate)) : '');
                } elseif (!empty($r['created_at'])) {
                    $day = date('Y-m-d', strtotime((string)$r['created_at']));
                }
                if ($day !== '' && $day !== $targetDate) continue;
            }

            $playerKey = (string)($r['user_id'] ?? '') !== '' ? (string)$r['user_id'] : strtolower((string)($r['email'] ?? $r['name'] ?? ''));
            if ($playerKey === '' || isset($seenPlayers[$playerKey])) continue;
            $seenPlayers[$playerKey] = true;

            $method = (string)($r['payment_method'] ?? '');
            $roster[] = [
                'id' => $r['id'],
                'name' => !empty($r['name']) ? $r['name'] : 'Registered Player',
                'email' => $r['email'] ?? '',
                'level' => !empty($r['level']) ? $r['level'] : 'Player',
                'avatar_url' => !empty($r['avatar_url']) ? $r['avatar_url'] : ($r['avatar'] ?? null),
                'status' => (string)($r['status'] ?? 'pending'),
                'payment_status' => strcasecmp($method, 'Pay at Venue') === 0 ? 'Pay at Venue' : 'Paid via ' . ($method !== '' ? $method : 'GCash'),
                'fee' => (float)($r['price'] ?? 0),
                'time_ago' => !empty($r['created_at']) ? date('M j, g:i A', strtotime((string)$r['created_at'])) : 'Recent',
            ];
        }

        // Fallback for session host / initial registered spot when current_players > 0
        if (empty($roster) && (!empty($session['host']) || (int)($session['current_players'] ?? 0) > 0)) {
            $hostName = !empty($session['host']) ? trim((string)$session['host']) : 'Session Host';
            $hostKey = strtolower($hostName);
            if (!isset($seenPlayers[$hostKey])) {
                $hostAvatar = !empty($session['host_avatar']) ? $session['host_avatar'] : ($session['user_avatar'] ?? ($session['avatar_url'] ?? ($session['avatar'] ?? null)));
                $hostLevel = !empty($session['level']) ? $session['level'] : 'Host / Organizer';
                $roster[] = [
                    'id' => 'host_' . $sessionId,
                    'name' => $hostName,
                    'email' => '',
                    'level' => $hostLevel,
                    'avatar_url' => $hostAvatar,
                    'status' => 'confirmed',
                    'payment_status' => 'Session Host',
                    'fee' => (float)($session['price'] ?? 0),
                    'time_ago' => 'Session Host',
                ];
            }
        }

        return $roster;
    }
    public function verifyBookingOwner(string $bookingId, string $ownerUserId): bool {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) as c FROM bookings b 
             JOIN facilities f ON b.facility_id = f.id 
             WHERE b.id = ? AND f.owner_id = ?"
        );
        $stmt->execute([$bookingId, $ownerUserId]);
        return (int)($stmt->fetch()['c'] ?? 0) > 0;
    }

    public function createOwnerApplication(array $appData): bool {
        $stmt = $this->pdo->prepare(
            "INSERT INTO owner_applications (id, user_id, facility_name, address, latitude, longitude, courts_count, court_surface, operating_hours, owner_name, business_email, phone, entity_name, reg_number, permit_file, gov_id_file, status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        return $stmt->execute([
            $appData['id'], $appData['user_id'], $appData['facility_name'], $appData['address'],
            $appData['latitude'] ?? null, $appData['longitude'] ?? null, $appData['courts_count'] ?? 1,
            $appData['court_surface'] ?? 'Indoor Hard', $appData['operating_hours'] ?? '6:00 AM – 11:00 PM',
            $appData['owner_name'] ?? '', $appData['business_email'] ?? '', $appData['phone'] ?? '',
            $appData['entity_name'] ?? '', $appData['reg_number'] ?? '', $appData['permit_file'] ?? '',
            $appData['gov_id_file'] ?? '', $appData['status'] ?? 'pending_review', $appData['created_at'] ?? date('Y-m-d H:i:s')
        ]);
    }

    public function getOwnerApplications(?string $status = null): array {
        $sql = "SELECT * FROM owner_applications WHERE 1=1";
        $params = [];
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getOwnerApplicationById(string $appId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM owner_applications WHERE id = ?");
        $stmt->execute([$appId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * The most recent application a user has ever submitted, regardless of
     * status. Used as a fallback when an admin approves/rejects without an
     * explicit application_id (legacy callers, or a stale queue row).
     */
    public function getLatestApplicationForUser(string $userId): ?array {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM owner_applications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Promote an approved owner application into a real, listed facility.
     *
     * Idempotent: re-approving the same application (a double click, or a
     * retried request) finds the facility it already created by owner_id +
     * name and returns that id rather than creating a duplicate.
     *
     * @return int The new (or already-existing) facility's id.
     * @throws \Throwable if the facility cannot be created — callers must
     *         not report success to the applicant when this throws.
     */
    public function createFacilityFromApplication(array $app): int {
        $ownerId = (string)($app['user_id'] ?? '');
        $name    = trim((string)($app['facility_name'] ?? ''));
        if ($ownerId === '' || $name === '') {
            throw new \InvalidArgumentException('Application is missing a user_id or facility_name.');
        }

        $facilityId = (new \Picklers\Domain\FacilityProvisioner($this->dbal(), $this->notifier()))->createFromApplication($app);
        $this->invalidateReadCache();
        return $facilityId;
    }

    public function updateOwnerApplicationStatus(string $appId, string $status): bool {
        $stmt = $this->pdo->prepare("UPDATE owner_applications SET status = ? WHERE id = ?");
        return $stmt->execute([$status, $appId]);
    }

    public function getBookingById(string $bookingId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        return $stmt->fetch() ?: null;
    }

    public function updateBookingStatus(string $bookingId, string $status): bool {
        $this->bumpSync('bookings');
        $this->pdo->beginTransaction();
        try {
            $check = $this->pdo->prepare("SELECT status FROM bookings WHERE id = ? FOR UPDATE");
            $check->execute([$bookingId]);
            $row = $check->fetch();
            if (!$row) {
                $this->pdo->rollBack();
                return false;
            }
            if ((string)$row['status'] === $status) {
                $this->pdo->commit();
                return true; // idempotent — already in target state
            }
            $stmt = $this->pdo->prepare("UPDATE bookings SET status = ? WHERE id = ?");
            $result = $stmt->execute([$status, $bookingId]);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[DB Error] updateBookingStatus failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Move a booking to $to only if it is currently in one of $from, as one
     * atomic compare-and-set.
     *
     * updateBookingStatus() treats "already in the target state" as success,
     * so two approvals of the same request (two tabs, a double-click) both got
     * true back — both incremented the Open Play seat count and both sent the
     * player a confirmation. Callers that attach side effects to a transition
     * use this and act only when it returns true.
     *
     * @param string[] $from
     */
    public function transitionBookingStatus(string $bookingId, array $from, string $to): bool {
        if ($from === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $stmt = $this->pdo->prepare("UPDATE bookings SET status = ? WHERE id = ? AND status IN ($placeholders)");
        $stmt->execute(array_merge([$to, $bookingId], array_values($from)));
        $changed = $stmt->rowCount() > 0;
        if ($changed) {
            $this->invalidateReadCache();
            $this->bumpSync('bookings', 'courts');
        }
        return $changed;
    }

    public function refundUserWallet(string $userId, float $amount, string $label): bool {
        $stmt = $this->pdo->prepare("UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id = ?");
        $ok = $stmt->execute([$amount, $userId]);
        if ($ok) {
            $this->addTransaction($userId, 'credit', $amount, $label);
            return true;
        }
        return false;
    }

    // --------------------------------------------------------------------------
    // Shared booking / wallet rules (used by the owner portal, the player app and
    // the Symfony admin console — one implementation of each rule).
    // --------------------------------------------------------------------------

    /** @var array<string,bool> */
    private array $columnCache = [];

    /** Whether a column exists (memoised per request). Lets new columns stay optional until migrated. */
    public function hasColumn(string $table, string $column): bool {
        $key = $table . '.' . $column;
        return $this->columnCache[$key] ??= $this->columnExists($table, $column);
    }

    // The shared booking/wallet rules below are implemented once, in
    // Picklers\Domain, and used as-is by the Symfony admin console.

    /** The Open Play session a booking belongs to, including legacy rows without match_id. */
    public function resolveBookingMatchId(array $booking): ?string {
        return $this->isMySQL
            ? $this->bookingRules()->resolveMatchId($booking)
            : \Picklers\Domain\BookingRules::matchIdFor($booking, $this->getMatchesByFacility($booking['facility_id'] ?? 0));
    }

    /**
     * Why a NEW booking cannot be taken at this facility/court right now, or null.
     * Every booking channel (court booking, Open Play join, owner-hosted session)
     * calls this so admin suspension and court maintenance apply everywhere.
     */
    public function bookingBlockReason(int|string $facilityId, ?string $courtId = null, ?string $courtName = null): ?string {
        return $this->bookingRules()->blockReason($facilityId, $courtId, $courtName);
    }

    /** Credit a wallet at most once per idempotency key; see WalletLedger::creditOnce(). MySQL only. */
    public function creditWalletOnce(string $userId, string $amountDecimal, string $label, string $idempotencyKey, array $meta = []): bool {
        return $this->ledger()->creditOnce($userId, $amountDecimal, $label, $idempotencyKey, $meta);
    }

    /**
     * Accept a pending/upcoming booking request (owner approval or admin
     * confirmation); see BookingRules::confirmRequest(). MySQL only.
     *
     * @return array{ok:bool,code:int,message:string,status:string,already:bool,booking:?array}
     */
    public function confirmBookingRequest(string $bookingId, array $opts = []): array {
        $result = $this->bookingRules()->confirmRequest($bookingId, $opts);
        $this->invalidateReadCache();
        return $result;
    }

    /**
     * Cancel a booking on behalf of the venue or the platform (owner decline,
     * admin cancellation); see BookingRules::cancelAsStaff(). MySQL only.
     *
     * @return array{ok:bool,code:int,message:string,previous_status:?string,refunded:bool,refund_amount:string,external_payment:bool,booking:?array}
     */
    public function cancelBookingAsStaff(string $bookingId, array $opts = []): array {
        $result = $this->bookingRules()->cancelAsStaff($bookingId, $opts);
        $this->invalidateReadCache();
        return $result;
    }

    public function declineBookingAtomically(string $bookingId, string $userId, float $price, string $paymentMethod, string $facilityName): bool {
        $refunded = false;
        $this->bumpSync('bookings');
        // One shared rule with the admin cancellation path (refund once,
        // release the seat, notify) — see cancelBookingAsStaff().
        $res = $this->cancelBookingAsStaff($bookingId, [
            'notify_lead' => "Your reservation (#{$bookingId}) at {$facilityName} was declined.",
        ]);
        return $res['refunded'];
    }

    public function getMatches($level = 'All', $facilitySearch = '') {
        $sql = "SELECT m.*, COALESCE(NULLIF(f.name, ''), m.facility_name) as facility_name
                FROM matches m
                LEFT JOIN facilities f ON m.facility_id = f.id
                WHERE 1=1";
        $params = [];
        if ($level !== 'All') {
            $sql .= " AND m.level = ?";
            $params[] = $level;
        }
        if (!empty($facilitySearch)) {
            $sql .= " AND (m.facility_name LIKE ? OR f.name LIKE ? OR m.location LIKE ?)";
            $params[] = "%$facilitySearch%";
            $params[] = "%$facilitySearch%";
            $params[] = "%$facilitySearch%";
        }
        $sql .= " ORDER BY m.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Filter expired matches and deduplicate by match ID & facility/court combo
        $active = array_values(array_filter($rows, fn($m) => !self::isMatchExpired($m)));
        $seen = [];
        $unique = [];
        foreach ($active as $m) {
            $mId = (string)($m['id'] ?? '');
            $facId = (string)($m['facility_id'] ?? '');
            $cName = self::normalizeCourtName($m['court_name'] ?? $m['type'] ?? '');
            $key = $mId !== '' ? $mId : ($facId . '_' . $cName);

            if (!isset($seen[$key])) {
                $seen[$key] = true;

                $rawDate = trim((string)($m['date'] ?? ''));
                $targetDate = self::getMatchTargetDate($m);
                $displayDate = self::getMatchDisplayDate($m);
                $m['date'] = $displayDate;
                $m['target_date'] = $targetDate;

                $isEveryday = strcasecmp($rawDate, 'everyday') === 0 || strcasecmp($rawDate, 'daily') === 0 || str_contains(strtolower($rawDate), 'everyday') || str_contains(strtolower($rawDate), 'daily');

                $activeCount = $this->countConfirmedMatchBookings($mId, $targetDate);
                if ($isEveryday) {
                    $m['current_players'] = $activeCount;
                } else {
                    $m['current_players'] = max((int)($m['current_players'] ?? 0), $activeCount);
                }

                $unique[] = $m;
            }
        }

        // Ensure newly created / hosted matches (e.g. op_...) appear at the top of the feed
        usort($unique, function($a, $b) {
            $aId = (string)($a['id'] ?? '');
            $bId = (string)($b['id'] ?? '');
            $aIsOp = str_starts_with($aId, 'op_') ? 1 : 0;
            $bIsOp = str_starts_with($bId, 'op_') ? 1 : 0;
            if ($aIsOp !== $bIsOp) {
                return $bIsOp <=> $aIsOp;
            }
            return strcmp($bId, $aId);
        });

        return $unique;
    }

    public function joinMatch($matchId, $userId, $paymentMethod = 'GCash', $promoCode = null) {
        $match = null;
        $pricing = new \Picklers\Services\PricingService($this);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT * FROM matches WHERE id = ? FOR UPDATE");
            $stmt->execute([$matchId]);
            $match = $stmt->fetch();
            if (!$match) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Match not found'];
            }
            // Discover hides ended sessions, but the id is still valid —
            // a stale tab or a direct request could pay into one.
            if (self::isMatchExpired($match)) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'This Open Play session has already ended.'];
            }
            // matches.type carries the court name the session is hosted on.
            if (($blocked = $this->bookingBlockReason($match['facility_id'] ?? 0, null, (string)($match['type'] ?? ''))) !== null) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => $blocked];
            }
            // Gate on pending + confirmed requests, not just approved seats.
            // The FOR UPDATE lock above serializes concurrent joinMatch() calls
            // for this match, so the count is atomic relative to them.
            $targetDate = self::getMatchTargetDate($match);
            if ($this->countActiveMatchBookings((string)$matchId, $targetDate) >= (int)$match['max_players']) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'This open play match is already full!'];
            }

            $alreadyChk = $this->pdo->prepare("SELECT id FROM bookings WHERE user_id = ? AND facility_id = ? AND court_name = ? AND (date = ? OR date LIKE ? OR booking_date = ?) AND time = ? AND status != 'cancelled'");
            $alreadyChk->execute([$userId, $match['facility_id'], $match['type'], $targetDate, "%$targetDate%", $targetDate, $match['time']]);
            if ($alreadyChk->fetch()) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'You have already joined this Open Play session!'];
            }

            // Entry fee is derived from the match record, never from the client.
            $quote = $pricing->quoteMatchJoin($match, $promoCode, $userId);
            if (empty($quote['success'])) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => (string)($quote['message'] ?? 'Unable to price this session.')];
            }
            $finalPrice = (float)$quote['total'];

            // Charge the wallet atomically when paying with Pickle Credits.
            if ($paymentMethod === 'Pickle Credits' && $finalPrice > 0) {
                $walletStmt = $this->pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
                $walletStmt->execute([$userId]);
                $walletRow = $walletStmt->fetch();
                if (!$walletRow || (float)($walletRow['wallet_balance'] ?? 0) < $finalPrice) {
                    $this->pdo->rollBack();
                    return ['success' => false, 'message' => 'Insufficient Pickle Credits. Please top up your wallet!'];
                }
                $this->pdo->prepare(
                    "UPDATE users SET wallet_balance = ROUND(wallet_balance - ?, 2) WHERE id = ?"
                )->execute([$finalPrice, $userId]);
            }

            $bookingId = 'PKL-OP-' . strtoupper(bin2hex(random_bytes(3)));
            $isEveryday = strcasecmp($match['date'] ?? '', 'everyday') === 0 || strcasecmp($match['date'] ?? '', 'daily') === 0;
            $bookingDateDisplay = $isEveryday ? date('D, M j, Y', strtotime($targetDate)) : $match['date'];
            $booking = [
                'id' => $bookingId,
                'user_id' => $userId,
                'facility_id' => $match['facility_id'],
                'facility_name' => $match['facility_name'],
                'court_name' => $match['type'] ?? 'Open Play Session',
                'date' => $bookingDateDisplay,
                'booking_date' => $targetDate,
                'time' => $match['time'],
                'duration' => '2 Hours',
                'price' => $finalPrice,
                'payment_method' => $paymentMethod ?? 'GCash',
                'status' => 'pending',
                'is_new' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'match_id' => $matchId
            ];
            $this->insertBooking($booking);

            // NOTE: For MySQL, current_players is incremented by the owner
            // when they confirm the booking (see ApiController::confirm_booking).
            // This preserves the pending-approval flow where seats are only
            // officially counted after owner acceptance.

            // Record promo usage so per-user and total limits are enforced.
            // Signature: (code, user, booking, discount).
            if ($promoCode !== null && $promoCode !== '' && (float)($quote['discount'] ?? 0) > 0) {
                $this->recordPromoRedemption(
                    strtoupper(trim($promoCode)),
                    (string)$userId,
                    $bookingId,
                    (float)$quote['discount']
                );
            }

            if ($paymentMethod === 'Pickle Credits' && $finalPrice > 0) {
                $this->addTransaction(
                    $userId,
                    'debit',
                    $finalPrice,
                        "Open Play #$bookingId at " . ($match['facility_name'] ?? 'Pickleball Facility'),
                    ['entry_kind' => 'booking_payment', 'booking_id' => $bookingId]
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \Picklers\Exceptions\PromoUnavailableException) {
                return ['success' => false, 'message' => $e->getMessage() . ' Nothing was charged — please join again without it.'];
            }
            error_log('[DB Error] joinMatch transaction failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'System transaction error. Please try again.'];
        }

        // Notify user — this is a REQUEST, not a confirmed seat, until the
        // facility owner approves it (see 'approve_booking' in ApiController,
        // which sends the actual "Booking Confirmed!" notification).
        $this->addNotification(
            $userId,
            'Open Play Request Sent 🔥',
            'Your request to join ' . ($match['type'] ?? 'Open Play') . ' at ' . ($match['facility_name'] ?? 'Facility') . ' is awaiting the facility\'s confirmation. We\'ll notify you once it\'s approved.',
            'community'
        );

        // createBooking() tells the owner about a new court request; an Open
        // Play join request needs the same owner approval but never did.
        $ownerId = $this->getFacilityOwnerId($match['facility_id'] ?? 0);
        if ($ownerId !== null && $ownerId !== (string)$userId) {
            $joinerName = $this->getUserById((string)$userId)['name'] ?? 'A player';
            $this->addNotification(
                $ownerId,
                'New Open Play request 🔥',
                sprintf(
                    '%s requested to join %s at %s (%s). Review it in your Requests queue.',
                    $joinerName,
                    $match['type'] ?? 'Open Play',
                    $match['facility_name'] ?? 'your facility',
                    $match['time'] ?? ''
                ),
                'booking'
            );
        }

        return [
            'success' => true,
            'message' => 'Join request sent to facility owner for approval!',
            'booking' => $booking
        ];
    }

    /**
     * How many seats on this match are actually spoken for — pending requests
     * included, not just owner-approved ones. This is the number joinMatch()
     * must compare against max_players; current_players alone only tracks
     * approved seats and under-counts demand while requests await review.
     */
    public function countActiveMatchBookings(string $matchId, ?string $targetDate = null): int {
        if ($targetDate !== null && $targetDate !== '') {
            return $this->bookingRules()->countActiveMatchBookings($matchId, $targetDate);
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('pending', 'confirmed')"
        );
        $stmt->execute([$matchId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Count only owner-accepted (confirmed/completed) players for an Open Play session.
     * Pending requests awaiting owner approval in the Requests queue do not count
     * as joined players on the court card until the owner clicks Accept.
     */
    public function countConfirmedMatchBookings(string $matchId, ?string $targetDate = null): int {
        if ($targetDate !== null && $targetDate !== '') {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('confirmed', 'completed')
                   AND (booking_date = ? OR (booking_date IS NULL AND (date = ? OR date LIKE ? OR created_at LIKE ?)))"
            );
            $stmt->execute([$matchId, $targetDate, $targetDate, "%$targetDate%", "$targetDate%"]);
            return (int)$stmt->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE match_id = ? AND status IN ('confirmed', 'completed')"
        );
        $stmt->execute([$matchId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Adjust a match's reserved seat count (JSON mode compensating action).
     * Used to release a seat when payment fails after the seat was taken.
     */
    public function adjustMatchPlayerCount(string $matchId, int $delta): bool {
        $this->bookingRules()->adjustMatchPlayerCount($matchId, $delta);
        return true;
    }

    // --------------------------------------------------------------------------
    // Slot collision helpers
    //
    // `time` is stored as a display string ("6:00 PM - 8:00 PM"), so the original
    // collision check compared it with `=`. That let "6:00 PM - 8:00 PM" and
    // "7:00 PM - 9:00 PM" both succeed on the same court — a real double-booking.
    // These parse the range so overlap is evaluated properly.
    // --------------------------------------------------------------------------

    /**
     * Parse a display time range into [startMinute, endMinute] from midnight.
     * Accepts -, – and — as separators. Ranges crossing midnight are unwrapped.
     */
    public function parseTimeRange(string $range): ?array {
        return \Picklers\Domain\BookingRules::parseTimeRange($range);
    }

    /** Half-open overlap test: [aStart,aEnd) intersects [bStart,bEnd). */
    public function rangesOverlap(array $a, array $b): bool {
        return $a[0] < $b[1] && $b[0] < $a[1];
    }

    /**
     * Does $requestedTime clash with any of $existingTimes on the same court/date?
     * Falls back to exact string equality when either side cannot be parsed, so an
     * unrecognised format never silently permits a clash.
     *
     * @param string[] $existingTimes
     * @return string|null The conflicting time string, or null when the slot is free.
     */
    public function findSlotConflict(string $requestedTime, array $existingTimes): ?string {
        $requested = $this->parseTimeRange($requestedTime);

        foreach ($existingTimes as $taken) {
            $taken = (string)$taken;
            $takenRange = $this->parseTimeRange($taken);

            if ($requested === null || $takenRange === null) {
                if ($taken === $requestedTime) {
                    return $taken;
                }
                continue;
            }
            if ($this->rangesOverlap($requested, $takenRange)) {
                return $taken;
            }
        }
        return null;
    }

    // Bookings
    public function getBookings($userId = null, $status = null) {
        $sql = "SELECT * FROM bookings WHERE 1=1";
        $params = [];
        if ($userId) {
            $sql .= " AND user_id = ?";
            $params[] = $userId;
        }
        if ($status) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * The owner Dashboard's "Requests" queue, shaped exactly as
     * views/partials/owner/_tab-dashboard.php's request-card-v2 needs it.
     * Shared by OwnerController::index() (first page render) and the
     * 'get_pending_requests' API action (live re-render after a PickSync
     * 'bookings' change) so the two can never drift apart.
     */
    public function getPendingBookingRequests(int|string $facilityId): array {
        $facilityIdStr = (string)$facilityId;
        // Scoped to this facility in the query itself.
        $stmt = $this->pdo->prepare(
            "SELECT b.*, u.name AS player_name, u.avatar_url AS player_avatar FROM bookings b
               LEFT JOIN users u ON u.id = b.user_id
              WHERE b.facility_id = ? AND b.status = 'pending'
              ORDER BY b.created_at DESC"
        );
        $stmt->execute([$facilityId]);
        $pending = $stmt->fetchAll();

        $requests = [];
        foreach ($pending as $b) {
            $playerName = $b['player_name'] ?? ($b['author_name'] ?? 'Player ' . substr((string)($b['user_id'] ?? ''), -4));
            $playerAvatar = $b['player_avatar'] ?? ($b['avatar_url'] ?? ($b['avatar'] ?? null));
            $rawPm = trim((string)($b['payment_method'] ?? 'GCASH'));
            if (strcasecmp($rawPm, 'Pay at Venue') === 0 || strcasecmp($rawPm, 'payatvenue') === 0) {
                $badge = 'PAY AT VENUE';
            } elseif (strcasecmp($rawPm, 'Pickle Credits') === 0 || strcasecmp($rawPm, 'picklecredits') === 0) {
                $badge = 'CREDITS';
            } else {
                $badge = strtoupper($rawPm);
            }
            $feeNum = (float)($b['price'] ?? 0);
            $dur = (string)($b['duration'] ?? '1');
            $durFormatted = is_numeric($dur) ? ($dur . ' ' . ((int)$dur === 1 ? 'hr' : 'hrs')) : $dur;
            $bId = (string)($b['id'] ?? '');
            $rawCourt = trim((string)($b['court_name'] ?? ''));
            $isOP = (isset($b['type']) && $b['type'] === 'open_play')
                 || (isset($b['booking_type']) && $b['booking_type'] === 'open_play')
                 || (strpos($bId, 'PKL-OP-') === 0)
                 || (strpos($bId, 'op_') === 0)
                 || (stripos($rawCourt, 'open play') !== false)
                 || (stripos($rawCourt, 'king of the court') !== false)
                 || (stripos($rawCourt, 'match') !== false);

            $requests[] = [
                'id' => (string)$b['id'],
                'name' => $playerName,
                'player_avatar' => $playerAvatar,
                'badge' => $badge,
                'court_name' => (string)($b['court_name'] ?? 'Court 1'),
                'schedule' => ($b['date'] ?? 'Upcoming') . ' at ' . ($b['time'] ?? 'TBD') . ' (' . $durFormatted . ')',
                'fee' => '₱' . number_format($feeNum),
                'fee_numeric' => $feeNum,
                'is_open_play' => $isOP,
                'booking_type' => $isOP ? 'open_play' : 'court_reservation',
                'type_label' => $isOP ? 'Open Play' : 'Court Reservation',
            ];
        }
        return $requests;
    }

    public function insertBooking($b) {
        // Every other mutating method here invalidates the courts read-cache;
        // this one didn't, so a request that reads court occupancy (it's
        // computed live from each court's bookings — see
        // getCourtsByFacilityUncached()) both before AND after inserting a
        // booking within the same request could see the pre-insert, stale
        // result the second time.
        $this->invalidateReadCache();
        // booking_date/start_min/end_min are optional here: joinMatch()'s Open
        // Play bookings don't need them (that occupancy path is driven by the
        // `matches` table, not a booking's own time window), but a caller
        // constructing a real timed-slot booking outside createBooking()'s
        // own validation (e.g. a test fixture) can supply them directly.
        $stmt = $this->pdo->prepare("INSERT INTO bookings (id, user_id, facility_id, court_id, match_id, facility_name, court_name, date, time, booking_date, start_min, end_min, duration, price, payment_method, status, is_new, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $b['id'], $b['user_id'], $b['facility_id'], $b['court_id'] ?? null, $b['match_id'] ?? null, $b['facility_name'], $b['court_name'],
            $b['date'], $b['time'], $b['booking_date'] ?? null, $b['start_min'] ?? null, $b['end_min'] ?? null,
            $b['duration'], $b['price'], $b['payment_method'],
            $b['status'], $b['is_new'] ?? 1, $b['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        return $b;
    }

    /**
     * Resolve a display date string ("Today", "Tomorrow", "Thu Sep 10 2026",
     * "Thu, Sep 10, 2026", "Friday", "Last Week", ...) to a canonical
     * 'Y-m-d'. Pure resolution — no future/past judgment, so it is safe to
     * use both for validating a NEW booking (validateBookingSlot() layers a
     * future-only check on top) and for backfilling historical rows, which
     * are — by definition — always in the past by the time a migration runs.
     */
    private function resolveDisplayDate(string $date): ?string {
        return \Picklers\Domain\BookingRules::resolveDisplayDate($date);
    }

    public function isFacilityOpen(?string $hoursStr): bool {
        if (empty($hoursStr)) {
            return true;
        }
        $raw = strtolower(trim($hoursStr));
        if (strpos($raw, '24') !== false) {
            return true;
        }

        $raw = str_replace(['–', '—', '?'], '-', $raw);
        $parts = explode('-', $raw);
        if (count($parts) < 2) {
            return true;
        }

        $parseMinutes = function(string $s): ?int {
            $s = trim($s);
            $isPM = (strpos($s, 'pm') !== false);
            $isAM = (strpos($s, 'am') !== false);
            if (!preg_match('/(\d{1,2})(?::(\d{2}))?/', $s, $m)) {
                return null;
            }
            $h = (int)$m[1];
            $mins = isset($m[2]) ? (int)$m[2] : 0;
            if ($isPM && $h < 12) $h += 12;
            if ($isAM && $h === 12) $h = 0;
            return $h * 60 + $mins;
        };

        $openMin = $parseMinutes($parts[0]);
        $closeMin = $parseMinutes($parts[1]);

        if ($openMin === null || $closeMin === null) {
            return true;
        }

        $nowH = (int)date('G');
        $nowM = (int)date('i');
        $currentMin = $nowH * 60 + $nowM;

        if ($closeMin > $openMin) {
            return $currentMin >= $openMin && $currentMin < $closeMin;
        } elseif ($closeMin < $openMin) {
            return $currentMin >= $openMin || $currentMin < $closeMin;
        }

        return true;
    }

    private function validateBookingSlot(string $date, string $time, int $duration): array {
        if ($duration < 1 || $duration > 12) {
            return ['valid' => false, 'message' => 'Duration must be between 1 and 12 hours.'];
        }

        $resolvedDate = $this->resolveDisplayDate($date);
        if (!$resolvedDate) {
            return ['valid' => false, 'message' => 'Invalid booking date format.'];
        }

        $timeParts = preg_split('/\s*[-–—]\s*/u', $time);
        $timePart = $timeParts[0] ?? '';
        $bookingDt = strtotime($resolvedDate . ' ' . $timePart);

        // Allow 5 minutes grace period for network latency when booking immediate slot
        if ($bookingDt === false || $bookingDt < (time() - 300)) {
            return ['valid' => false, 'message' => 'Booking must be for a future time slot.'];
        }

        return ['valid' => true, 'resolved_date' => $resolvedDate];
    }

    /** Unix timestamp a booking's play window starts, or null when it can't be resolved. */
    private function bookingStartTimestamp(array $booking): ?int {
        $resolvedDate = (string)($booking['booking_date'] ?? '');
        if ($resolvedDate === '') {
            $resolvedDate = (string)($this->resolveDisplayDate((string)($booking['date'] ?? '')) ?? '');
        }
        if ($resolvedDate === '') {
            return null;
        }

        $startMin = isset($booking['start_min']) && $booking['start_min'] !== null ? (int)$booking['start_min'] : null;
        if ($startMin === null) {
            $timeStr = (string)($booking['time'] ?? '');
            $range = $this->parseTimeRange($timeStr);
            if ($range === null) {
                $first = preg_split('/\s*[-–—]\s*/u', $timeStr)[0] ?? '';
                $ts = $first !== '' ? strtotime($resolvedDate . ' ' . $first) : false;
                return $ts === false ? null : $ts;
            }
            $startMin = $range[0];
        }

        $dayStart = strtotime($resolvedDate . ' 00:00:00');
        return $dayStart === false ? null : $dayStart + $startMin * 60;
    }

    private function hasBookingStarted(array $booking): bool {
        if (!empty($booking['ended_early'])) {
            return true;
        }
        $start = $this->bookingStartTimestamp($booking);
        return $start !== null && time() >= $start;
    }

    /**
     * Whether cancelling this booking right now earns the automatic full
     * refund cancelBooking() issues: paid with Pickle Credits, and at least
     * 24 hours before play. Lets the cancel dialog state the real outcome.
     */
    public function isFullRefundEligible(array $booking): bool {
        return ($booking['payment_method'] ?? '') === 'Pickle Credits'
            && (float)($booking['price'] ?? 0) > 0
            && (($booking['status'] ?? '') === 'pending' || $this->isWithin24HourWindow($booking));
    }

    private function isWithin24HourWindow(array $booking): bool {
        // Resolved from booking_date first. An Open Play join's display date is
        // "Everyday (2026-09-14)", which never parsed, so those players were
        // refused the full refund the cancellation policy promises.
        $start = $this->bookingStartTimestamp($booking);
        return $start !== null && ($start - time()) >= 86400;
    }
    /** Booking grid: 7:00 AM to 10:00 PM, one hour per slot. */
    private const AVAILABILITY_DAY_START_MIN = 420;
    private const AVAILABILITY_DAY_END_MIN   = 1320;
    private const AVAILABILITY_SLOT_MINUTES  = 60;

    /**
     * Which of a court's fixed hourly slots on a given day are actually free.
     *
     * The server has always correctly rejected an overlapping booking at the
     * point of charge (see createBooking()'s pessimistic lock); this is what
     * lets a client show that BEFORE the player picks a time and pays,
     * instead of the previous approach of hardcoding a single fake "occupied"
     * slot and discovering any real conflict only after submitting.
     *
     * @return array<int,array{start_min:int,end_min:int,label:string,available:bool,reason:?string}>
     */
    public function getSlotAvailability(int|string $facilityId, string $courtId, string $date): array {
        $taken = [];
        if ($courtId !== '') {
            // Include legacy rows that pre-date court_id (stored by court_name only).
            $courtName = '';
            $cnStmt = $this->pdo->prepare("SELECT name FROM courts WHERE id = ? LIMIT 1");
            $cnStmt->execute([$courtId]);
            $cnRow = $cnStmt->fetch();
            if ($cnRow) {
                $courtName = (string)($cnRow['name'] ?? '');
            }
            $stmt = $this->pdo->prepare(
                "SELECT start_min, end_min FROM bookings
                 WHERE facility_id = ? AND booking_date = ?
                   AND (court_id = ? OR (? != '' AND court_name = ?))
                   AND status NOT IN ('cancelled', 'declined')
                   AND start_min IS NOT NULL AND end_min IS NOT NULL"
            );
            $stmt->execute([$facilityId, $date, $courtId, $courtName, $courtName]);
            $taken = $stmt->fetchAll();
        }

        $isToday = ($date === date('Y-m-d'));
        $nowMin  = ((int)date('G') * 60) + (int)date('i');

        $slots = [];
        for ($m = self::AVAILABILITY_DAY_START_MIN; $m < self::AVAILABILITY_DAY_END_MIN; $m += self::AVAILABILITY_SLOT_MINUTES) {
            $end = $m + self::AVAILABILITY_SLOT_MINUTES;

            $booked = false;
            foreach ($taken as $t) {
                if ($this->rangesOverlap([$m, $end], [(int)$t['start_min'], (int)$t['end_min']])) {
                    $booked = true;
                    break;
                }
            }
            $past = $isToday && $m <= $nowMin;

            $slots[] = [
                'start_min' => $m,
                'end_min'   => $end,
                'label'     => $this->minutesToLabel($m) . ' – ' . $this->minutesToLabel($end),
                'available' => !$booked && !$past,
                'reason'    => $booked ? 'booked' : ($past ? 'past' : null),
            ];
        }

        return $slots;
    }

    private function minutesToLabel(int $m): string {
        $h = intdiv($m, 60) % 24;
        $i = $m % 60;
        $suffix = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12;
        if ($h12 === 0) $h12 = 12;
        return sprintf('%d:%02d %s', $h12, $i, $suffix);
    }

    /**
     * @param string $courtId Optional. When the caller can supply it (the
     *        court came from a real courts-table lookup, not a client-typed
     *        name), it becomes the row's stable identity and the canonical
     *        court name from that row is stored instead of whatever string
     *        the caller passed — this is what stops a mangled/truncated
     *        display name from ever reaching the ledger. Leave empty for
     *        legacy callers; the slot lock still works correctly by name.
     * @param string|null $promoCode Recorded as a redemption INSIDE this same
     *        transaction when both this and $promoDiscount are given, so a
     *        discount can never be granted (the booking commits) without
     *        also being counted (the redemption row exists) or vice versa.
     */
    public function createBooking(
        $userId, $facilityId, $courtName, $date, $time, $duration, $price, $paymentMethod,
        string $courtId = '', ?string $promoCode = null, float $promoDiscount = 0.0
    ) {
        $val = $this->validateBookingSlot((string)$date, (string)$time, (int)$duration);
        if (!$val['valid']) {
            return ['success' => false, 'message' => $val['message']];
        }
        $this->bumpSync('bookings');
        // Canonical Y-m-d, resolved once and used for the lock itself, so
        // "Today", "Thu Sep 10 2026" and "Thu, Sep 10, 2026" collide as the
        // same day.
        $bookingDate = $val['resolved_date'];
        $range = $this->parseTimeRange((string)$time);
        // validateBookingSlot() only ever checked the START of the range, so
        // "8:00 AM - <anything>" was accepted: the booking stored no end
        // minute (never blocking the slot for anyone else), fell back to
        // string-equality collision checks, and carried arbitrary text into
        // every screen that renders the time. A real slot has a real end.
        if ($range === null) {
            return ['success' => false, 'message' => 'Please choose a valid time slot (for example "6:00 PM - 7:00 PM").'];
        }
        $startMin = $range[0];
        $endMin   = $range[1];

        $facility = $this->getFacility($facilityId);
        $facilityName = $facility ? $facility['name'] : 'Pickleball Facility';

        // If an id was supplied, it is authoritative: use the court's own
        // stored name rather than trust whatever string the caller sent.
        if ($courtId !== '') {
            foreach ($this->getCourtsByFacility($facilityId) as $c) {
                if ((string)($c['id'] ?? '') === $courtId) {
                    $courtName = (string)($c['name'] ?? $courtName);
                    break;
                }
            }
        }

        $booking = null;

        try {
            $this->pdo->beginTransaction();

            // Admin suspension / court maintenance applies to every channel.
            if (($blocked = $this->bookingBlockReason($facilityId, $courtId !== '' ? $courtId : null, (string)$courtName)) !== null) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => $blocked];
            }

            // Pessimistic lock scoped to the CANONICAL date (not whatever
            // display string the client happened to send), then evaluate
            // real time-range overlap. Matches by court_id OR court_name
            // so a legacy row that predates court_id (still NULL — see
            // the migration backfill) is never skipped and a real
            // conflict never goes undetected; idx_booking_date_lock keeps
            // this narrow either way.
            if ($courtId !== '') {
                $chk = $this->pdo->prepare(
                    "SELECT time, start_min, end_min FROM bookings
                     WHERE facility_id = ? AND booking_date = ?
                       AND (court_id = ? OR court_name = ?)
                       AND status NOT IN ('cancelled', 'declined')
                     FOR UPDATE"
                );
                $chk->execute([$facilityId, $bookingDate, $courtId, $courtName]);
            } else {
                $chk = $this->pdo->prepare(
                    "SELECT time, start_min, end_min FROM bookings
                     WHERE facility_id = ? AND booking_date = ? AND court_name = ?
                       AND status NOT IN ('cancelled', 'declined')
                     FOR UPDATE"
                );
                $chk->execute([$facilityId, $bookingDate, $courtName]);
            }
            $existingRows = $chk->fetchAll();

            $conflict = null;
            foreach ($existingRows as $row) {
                $rowStart = $row['start_min'] !== null ? (int)$row['start_min'] : null;
                $rowEnd   = $row['end_min']   !== null ? (int)$row['end_min']   : null;
                if ($startMin !== null && $endMin !== null && $rowStart !== null && $rowEnd !== null) {
                    if ($this->rangesOverlap([$startMin, $endMin], [$rowStart, $rowEnd])) {
                        $conflict = (string)$row['time'];
                        break;
                    }
                    continue;
                }
                // Either side didn't parse into minutes (an unusual time
                // format) — fall back to the original string-overlap
                // check for just that one row rather than assume clear.
                if ($this->findSlotConflict((string)$time, [(string)$row['time']]) !== null) {
                    $conflict = (string)$row['time'];
                    break;
                }
            }

            if ($conflict !== null) {
                $this->pdo->rollBack();
                return [
                    'success' => false,
                    'message' => "That court is already booked for {$conflict}. Please choose a different time."
                ];
            }

            // Atomic wallet check and deduction if Pickle Credits
            if ($paymentMethod === 'Pickle Credits') {
                $userStmt = $this->pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
                $userStmt->execute([$userId]);
                $userRow = $userStmt->fetch();
                if (!$userRow || (float)($userRow['wallet_balance'] ?? 0) < (float)$price) {
                    $this->pdo->rollBack();
                    return ['success' => false, 'message' => 'Insufficient Pickle Credits. Please top up your wallet!'];
                }
                $this->pdo->prepare(
                    "UPDATE users SET wallet_balance = ROUND(wallet_balance - ?, 2) WHERE id = ?"
                )->execute([$price, $userId]);
            }

            $bookingId = 'PKL-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $this->pdo->prepare(
                "INSERT INTO bookings
                   (id, user_id, facility_id, court_id, facility_name, court_name,
                    date, time, booking_date, start_min, end_min, duration, price,
                    payment_method, status, is_new, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $stmt->execute([
                $bookingId, $userId, (int)$facilityId, ($courtId !== '' ? $courtId : null),
                $facilityName, $courtName, $date, $time, $bookingDate, $startMin, $endMin,
                $duration, (float)$price, $paymentMethod, 'pending', 1, date('Y-m-d H:i:s')
            ]);

            if ($paymentMethod === 'Pickle Credits') {
                $payStr = !empty($paymentMethod) ? " via $paymentMethod" : '';
                $this->addTransaction($userId, 'debit', $price, "Booking #$bookingId at $facilityName$payStr", ['entry_kind' => 'booking_payment', 'booking_id' => $bookingId]);
            }

            // Recorded in the SAME transaction as the booking itself: a
            // promo discount that was granted (the row below commits)
            // can never end up uncounted (no redemption row), and a
            // redemption can never be recorded against a booking that
            // ultimately didn't happen (the whole transaction rolls back
            // together on any failure above).
            if ($promoCode !== null && $promoCode !== '' && $promoDiscount > 0) {
                $this->recordPromoRedemption($promoCode, $userId, $bookingId, $promoDiscount);
            }

            $this->pdo->commit();

            $booking = [
                'id' => $bookingId,
                'user_id' => $userId,
                'facility_id' => (int)$facilityId,
                'court_id' => $courtId !== '' ? $courtId : null,
                'facility_name' => $facilityName,
                'court_name' => $courtName,
                'date' => $date,
                'time' => $time,
                'booking_date' => $bookingDate,
                'start_min' => $startMin,
                'end_min' => $endMin,
                'duration' => $duration,
                'price' => (float)$price,
                'payment_method' => $paymentMethod,
                'status' => 'pending',
                'is_new' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($e instanceof \Picklers\Exceptions\PromoUnavailableException) {
                return ['success' => false, 'message' => $e->getMessage() . ' Nothing was charged — please book again without it.'];
            }
            error_log('[DB Error] createBooking failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Reservation failed due to a system error. Please try again.'];
        }

        // This is a REQUEST awaiting the facility owner's approval, not a
        // confirmed reservation yet — the actual "Booking Confirmed!"
        // notification is sent from 'approve_booking' in ApiController once
        // the owner accepts it. Telling the player it's "confirmed" here,
        // before anyone at the facility has reviewed it, is misleading.
        $this->addNotification(
            $userId,
            'Reservation Requested ⏳',
            "Your request for $courtName at $facilityName on $date ($time) has been sent to the facility for confirmation. We'll notify you once it's approved.",
            'booking'
        );

        // The player has always been told a booking landed. The facility
        // owner never was — their only way to learn about a new reservation
        // was noticing it in the dashboard's request queue on their own.
        $ownerId = $this->getFacilityOwnerId($facilityId);
        if ($ownerId !== null && $ownerId !== $userId) {
            $bookerName = $this->getUserById($userId)['name'] ?? 'A player';
            $this->addNotification(
                $ownerId,
                'New reservation 🎾',
                sprintf(
                    '%s booked %s at %s for %s (%s) — ₱%s.',
                    $bookerName, $courtName, $facilityName, $date, $time, number_format((float)$price, 2)
                ),
                'booking'
            );
        }

        return ['success' => true, 'booking' => $booking, 'message' => 'Court booked successfully!'];
    }

    /** The user id that owns a facility, or null if unassigned/not found. */
    public function getFacilityOwnerId(int|string $facilityId): ?string {
        $facility = $this->getFacility($facilityId);
        $ownerId = $facility['owner_id'] ?? null;
        return ($ownerId !== null && $ownerId !== '') ? (string)$ownerId : null;
    }

    public function cancelBooking($bookingId, $userId) {
        $refundAmount = 0;
        $this->bumpSync('bookings');
        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT * FROM bookings WHERE id = ? FOR UPDATE");
            $stmt->execute([$bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Booking not found'];
            }
            if ((string)$booking['user_id'] !== (string)$userId) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Access denied: You cannot cancel another player\'s booking'];
            }
            if ($booking['status'] === 'cancelled') {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'Booking is already cancelled'];
            }
            // Only block cancellation if the booking was CONFIRMED/ACCEPTED by the owner.
            // If it is still PENDING (unaccepted), the player can always cancel/withdraw their request!
            if (($booking['status'] ?? '') !== 'pending' && $this->hasBookingStarted($booking)) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'This session has already started or finished, so it can no longer be cancelled.'];
            }

            $prevStatus = $booking['status'] ?? 'pending';

            // 1. Status update FIRST (idempotency anchor)
            $this->pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?")->execute([$bookingId]);

            if ($prevStatus === 'confirmed') {
                $matchId = $booking['match_id'] ?? null;
                if (!$matchId && (!empty($booking['court_name']) || str_starts_with($bookingId, 'PKL-OP-'))) {
                    $matches = $this->getMatchesByFacility($booking['facility_id'] ?? 0);
                    foreach ($matches as $m) {
                        if (($m['type'] ?? '') === ($booking['court_name'] ?? '') && ($m['date'] ?? '') === ($booking['date'] ?? '') && ($m['time'] ?? '') === ($booking['time'] ?? '')) {
                            $matchId = $m['id'];
                            break;
                        }
                    }
                }
                if ($matchId) {
                    $this->adjustMatchPlayerCount($matchId, -1);
                }
            }

            // 2. Refund SECOND (inside same transaction)
            $isSafeWindow = $this->isWithin24HourWindow($booking);
            $isPending = ($prevStatus === 'pending');
            if (($isPending || $isSafeWindow) && ($booking['payment_method'] ?? '') === 'Pickle Credits' && (float)($booking['price'] ?? 0) > 0) {
                // Idempotent: a booking already refunded by any path (owner
                // decline, admin cancellation) is never refunded again.
                $refundAmount = $this->creditWalletOnce(
                    (string)$userId,
                    number_format((float)$booking['price'], 2, '.', ''),
                    "Full Refund for Cancelled Booking #$bookingId",
                    'refund:booking:' . $bookingId,
                    ['entry_kind' => 'refund', 'booking_id' => (string)$bookingId]
                ) ? (float)$booking['price'] : 0;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[DB Error] cancelBooking failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Cancellation failed. Please try again.'];
        }

        $paidWith = (string)($booking['payment_method'] ?? '');
        if ($refundAmount > 0) {
            $msg = "Booking cancelled. ₱" . number_format($refundAmount, 2) . " has been fully refunded to your Pickle Credits!";
        } elseif ($paidWith === 'Pickle Credits' && (float)($booking['price'] ?? 0) > 0) {
            $msg = "Booking cancelled. Since cancellation occurred within 24 hours of play, no refund is provided per venue policy.";
        } elseif ($paidWith === 'Pay at Venue') {
            $msg = "Booking cancelled. Nothing was charged for this Pay at Venue booking.";
        } else {
            // Only Pickle Credits can be refunded in-app.
            $msg = "Booking cancelled. It was paid via " . ($paidWith !== '' ? $paidWith : 'another method')
                . ", so please contact the facility about your payment.";
        }
        $this->addNotification($userId, 'Booking Cancelled', $msg, 'booking');

        return ['success' => true, 'message' => $msg, 'refund_amount' => $refundAmount];
    }

    // Wallet
    public function getWalletTransactions($userId) {
        $rows = [];
        $stmt = $this->pdo->prepare("SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        $user = $this->getUserById($userId);
        if ($user) {
            $bal = (float)($user['wallet_balance'] ?? 0);

            // If user has a positive balance but no credit/top-up transactions recorded yet, synthesize an initial deposit transaction
            $hasCreditTx = false;
            foreach ($rows as $r) {
                if (($r['type'] ?? '') === 'credit') {
                    $hasCreditTx = true;
                    break;
                }
            }

            if (!$hasCreditTx && $bal > 0) {
                // Display-only. A balance with no recorded credit behind it
                // (seeded/imported accounts) is shown as an opening balance and
                // never written into the ledger.
                $createdAt = (string)($user['created_at'] ?? date('Y-m-d H:i:s'));
                $rows[] = [
                    'id' => 'tx_opening_' . substr(hash('sha256', (string)$userId), 0, 10),
                    'user_id' => $userId,
                    'type' => 'credit',
                    'amount' => $bal,
                    'label' => 'Opening Balance',
                    'date' => date('M j, Y', strtotime($createdAt) ?: time()),
                    'created_at' => $createdAt,
                ];
            }
        }

        // Also compile any court bookings / open play joins for this user
        if ($user) {
            $existingLabels = array_column($rows, 'label');
            $bookings = [];
            $stmt = $this->pdo->prepare("SELECT * FROM bookings WHERE user_id = ? AND status != 'cancelled' ORDER BY created_at DESC");
            $stmt->execute([$userId]);
            $bookings = $stmt->fetchAll();

            foreach ($bookings as $b) {
                $bId = (string)($b['id'] ?? '');
                $alreadyInTx = false;
                foreach ($existingLabels as $lbl) {
                    if (str_contains($lbl, $bId)) {
                        $alreadyInTx = true;
                        break;
                    }
                }
                if (!$alreadyInTx && (float)($b['price'] ?? 0) > 0) {
                    $payMethod = !empty($b['payment_method']) ? $b['payment_method'] : 'GCash';
                    $facName = !empty($b['facility_name']) ? $b['facility_name'] : 'Pickleball Facility';
                    $courtName = !empty($b['court_name']) ? $b['court_name'] : 'Court Reservation';
                    $isOP = str_starts_with($bId, 'PKL-OP-') || stripos($courtName, 'open play') !== false;
                    $labelPrefix = $isOP ? "Open Play #$bId at $facName" : "Booking #$bId at $facName";
                    $bDate = !empty($b['created_at']) ? date('M j, Y', strtotime($b['created_at'])) : date('M j, Y');

                    $bTx = [
                        'id' => 'tx_b_' . strtolower(str_replace(['PKL-', '-'], '', $bId)),
                        'user_id' => $userId,
                        'type' => 'debit',
                        'amount' => (float)$b['price'],
                        'label' => $labelPrefix . " via $payMethod",
                        'date' => $bDate,
                        'created_at' => $b['created_at'] ?? date('Y-m-d H:i:s')
                    ];
                    $rows[] = $bTx;
                }
            }
        }

        // Sort all transactions by created_at DESC
        usort($rows, function($a, $b) {
            $tA = strtotime($a['created_at'] ?? $a['date'] ?? 'now');
            $tB = strtotime($b['created_at'] ?? $b['date'] ?? 'now');
            return $tB <=> $tA;
        });

        return array_values($rows);
    }

    /**
     * @param array{entry_kind?:string,booking_id?:?string,actor_user_id?:?string,reason?:?string,idempotency_key?:?string,balance_after?:?string} $meta
     *        Ledger evidence recorded for NEW entries once the wallet ledger
     *        migration has run (kind, linked booking, acting admin, reason,
     *        resulting balance, idempotency key). Ignored before that.
     */
    public function addTransaction($userId, $type, $amount, $label, array $meta = []) {
        $id = 'tx_' . bin2hex(random_bytes(5));
        $date = date('M j, Y');
        $createdAt = date('Y-m-d H:i:s');

        $tx = [
            'id' => $id, 'user_id' => $userId, 'type' => $type,
            'amount' => (float)$amount, 'label' => $label, 'date' => $date,
            'created_at' => $createdAt
        ];

        return $this->ledger()->record((string)$userId, (string)$type, $amount, (string)$label, $meta);
    }

    public function topUpWallet($userId, $amount, $method) {
        $amount = (float)$amount;
        if ($amount <= 0 || $amount > 50000) {
            return ['success' => false, 'message' => 'Top-up amount must be between ₱1.00 and ₱50,000.00'];
        }

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->rollBack();
                return ['success' => false, 'message' => 'User not found'];
            }

            $oldBalance = (float)($row['wallet_balance'] ?? 0);
            $newBalance = round($oldBalance + $amount, 2);

            $this->pdo->prepare(
                "UPDATE users SET wallet_balance = ROUND(wallet_balance + ?, 2) WHERE id = ?"
            )->execute([$amount, $userId]);

            $this->addTransaction($userId, 'credit', $amount, $method, ['entry_kind' => 'top_up']);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[DB Error] topUpWallet failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Top-up failed. Please try again.'];
        }

        $this->addNotification(
            $userId, 
            'Top-Up Successful! 💳', 
            "₱" . number_format($amount, 2) . " Pickle Credits added via $method. Previous balance: ₱" . number_format($oldBalance, 2) . ", Current balance: ₱" . number_format($newBalance, 2), 
            'system'
        );

        return [
            'success' => true,
            'message' => "Successfully topped up ₱" . number_format($amount, 2) . "! New balance: ₱" . number_format($newBalance, 2),
            'old_balance' => $oldBalance,
            'amount_added' => $amount,
            'new_balance' => $newBalance
        ];
    }

    // Community Feed
    public function getFeedPosts($userId = null) {
        // Content hidden by moderation is excluded from every public read.
        $visible = $this->hasColumn('feed_posts', 'hidden_at') ? ' WHERE hidden_at IS NULL' : '';
        $stmt = $this->pdo->query("SELECT * FROM feed_posts{$visible} ORDER BY created_at DESC");
        $posts = $stmt->fetchAll();
        if (empty($posts)) return [];

        $postIds = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($postIds), '?'));

        // Batch fetch liked post IDs in 1 query
        $likedPostIds = [];
        if ($userId) {
            $likeStmt = $this->pdo->prepare("SELECT post_id FROM feed_likes WHERE user_id = ? AND post_id IN ($placeholders)");
            $likeStmt->execute(array_merge([$userId], $postIds));
            $likedPostIds = array_flip($likeStmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        // Batch fetch comments grouped by post_id in 1 query
        $hiddenComments = $this->hasColumn('feed_comments', 'hidden_at');
        $commentStmt = $this->pdo->prepare("SELECT * FROM feed_comments WHERE post_id IN ($placeholders)" . ($hiddenComments ? ' AND hidden_at IS NULL' : '') . " ORDER BY created_at ASC");
        $commentStmt->execute($postIds);
        $commentsByPost = [];
        foreach ($commentStmt->fetchAll() as $comm) {
            $commentsByPost[$comm['post_id']][] = $comm;
        }

        foreach ($posts as &$p) {
            $p['i_liked'] = isset($likedPostIds[$p['id']]);
            $p['comments'] = $commentsByPost[$p['id']] ?? [];
            if ($hiddenComments) {
                // The stored counter includes hidden comments; report what players can see.
                $p['comment_count'] = count($p['comments']);
            }
        }
        return $posts;
    }

    public function createFeedPost($userId, $content, $imageUrl = null, $type = 'text') {
        $user = $this->getUserById($userId);
        if (!$user) return ['success' => false, 'message' => 'User not found'];

        $id = 'post_' . bin2hex(random_bytes(6));
        $createdAt = date('Y-m-d H:i:s');

        $post = [
            'id' => $id,
            'author_id' => $userId,
            'author_name' => $user['name'],
            'author_avatar' => $user['avatar_url'],
            'author_level' => $user['level'],
            'content' => $content,
            'image_url' => $imageUrl,
            'post_type' => $type,
            'like_count' => 0,
            'comment_count' => 0,
            'created_at' => $createdAt
        ];

        $stmt = $this->pdo->prepare("INSERT INTO feed_posts (id, author_id, author_name, author_avatar, author_level, content, image_url, post_type, like_count, comment_count, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$id, $userId, $user['name'], $user['avatar_url'], $user['level'], $content, $imageUrl, $type, 0, 0, $createdAt]);

        $post['i_liked'] = false;
        $post['comments'] = [];
        return ['success' => true, 'post' => $post];
    }

    private function feedPostExists(string $postId): bool {
        if ($postId === '') {
            return false;
        }
        // A post hidden by moderation cannot be liked or commented on.
        $visible = $this->hasColumn('feed_posts', 'hidden_at') ? ' AND hidden_at IS NULL' : '';
        $s = $this->pdo->prepare("SELECT 1 FROM feed_posts WHERE id = ?{$visible} LIMIT 1");
        $s->execute([$postId]);
        return (bool)$s->fetchColumn();
    }

    public function toggleLikePost($postId, $userId) {
        // Likes/comments for a post id that doesn't exist were stored as
        // orphan rows and reported back as a success.
        if (!$this->feedPostExists((string)$postId)) {
            return ['success' => false, 'message' => 'This post is no longer available.'];
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as c FROM feed_likes WHERE post_id = ? AND user_id = ?");
        $stmt->execute([$postId, $userId]);
        $hasLiked = $stmt->fetch()['c'] > 0;
        if ($hasLiked) {
            $del = $this->pdo->prepare("DELETE FROM feed_likes WHERE post_id = ? AND user_id = ?");
            $del->execute([$postId, $userId]);
            $decStmt = $this->pdo->prepare("UPDATE feed_posts SET like_count = GREATEST(0, like_count - 1) WHERE id = ?");
            $decStmt->execute([$postId]);
            $liked = false;
        } else {
            $ins = $this->pdo->prepare("INSERT INTO feed_likes (post_id, user_id) VALUES (?,?)");
            $ins->execute([$postId, $userId]);
            $incStmt = $this->pdo->prepare("UPDATE feed_posts SET like_count = like_count + 1 WHERE id = ?");
            $incStmt->execute([$postId]);
            $liked = true;
        }
        $cntStmt = $this->pdo->prepare("SELECT like_count FROM feed_posts WHERE id = ?");
        $cntStmt->execute([$postId]);
        $cnt = $cntStmt->fetch()['like_count'] ?? 0;
        return ['success' => true, 'i_liked' => $liked, 'like_count' => (int)$cnt];
    }

    /**
     * Toggle a player's favorite on a facility. Mirrors toggleLikePost()'s shape.
     */
    public function toggleFavoriteFacility(string $userId, int|string $facilityId): array {
        $facilityId = (int)$facilityId;
        if ($facilityId <= 0 || !$this->getFacility($facilityId)) {
            return ['success' => false, 'message' => 'Facility not found.'];
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as c FROM facility_favorites WHERE user_id = ? AND facility_id = ?");
        $stmt->execute([$userId, $facilityId]);
        $isFavorited = ((int)($stmt->fetch()['c'] ?? 0)) > 0;
        if ($isFavorited) {
            $this->pdo->prepare("DELETE FROM facility_favorites WHERE user_id = ? AND facility_id = ?")->execute([$userId, $facilityId]);
            $favorited = false;
        } else {
            $this->pdo->prepare("INSERT INTO facility_favorites (user_id, facility_id) VALUES (?, ?)")->execute([$userId, $facilityId]);
            $favorited = true;
        }
        return ['success' => true, 'favorited' => $favorited];
    }

    /** @return array<int,int> facility ids this player has favorited */
    public function getFavoriteFacilityIds(string $userId): array {
        if ($userId === '') {
            return [];
        }
        $stmt = $this->pdo->prepare("SELECT facility_id FROM facility_favorites WHERE user_id = ?");
        $stmt->execute([$userId]);
        return array_map('intval', array_column($stmt->fetchAll(), 'facility_id'));
    }

    public function addComment($postId, $userId, $comment) {
        $user = $this->getUserById($userId);
        if (!$user) return ['success' => false, 'message' => 'User not found'];
        if (!$this->feedPostExists((string)$postId)) {
            return ['success' => false, 'message' => 'This post is no longer available.'];
        }

        $id = 'comm_' . bin2hex(random_bytes(5));
        $createdAt = date('Y-m-d H:i:s');
        $entry = [
            'id' => $id,
            'post_id' => $postId,
            'user_id' => $userId,
            'author_name' => $user['name'],
            'author_avatar' => $user['avatar_url'],
            'comment' => $comment,
            'created_at' => $createdAt
        ];

        $stmt = $this->pdo->prepare("INSERT INTO feed_comments (id, post_id, user_id, author_name, author_avatar, comment, created_at) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([$id, $postId, $userId, $user['name'], $user['avatar_url'], $comment, $createdAt]);
        $this->pdo->prepare("UPDATE feed_posts SET comment_count = comment_count + 1 WHERE id = ?")->execute([$postId]);

        return ['success' => true, 'comment' => $entry];
    }

    // Direct Messages & Chat
    public function getMessages($userId, $partnerId) {
        $stmt = $this->pdo->prepare("SELECT * FROM direct_messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC");
        $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
        return $stmt->fetchAll();
    }

    public function sendMessage($senderId, $receiverId, $content) {
        $id = 'msg_' . bin2hex(random_bytes(6));
        $createdAt = date('Y-m-d H:i:s');
        $msg = [
            'id' => $id,
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'content' => $content,
            'is_read' => 0,
            'created_at' => $createdAt
        ];

        $stmt = $this->pdo->prepare("INSERT INTO direct_messages (id, sender_id, receiver_id, content, is_read, created_at) VALUES (?,?,?,?,?,?)");
        $stmt->execute([$id, $senderId, $receiverId, $content, 0, $createdAt]);

        return ['success' => true, 'message' => $msg];
    }

    /**
     * Mark everything $partnerId sent to $userId as read. Nothing ever set
     * direct_messages.is_read, so the owner inbox's unread badges could never
     * clear no matter how many times a conversation was opened.
     */
    public function markConversationRead(string $userId, string $partnerId): void {
        if ($userId === '' || $partnerId === '') {
            return;
        }
        $this->pdo->prepare(
            "UPDATE direct_messages SET is_read = 1 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0"
        )->execute([$userId, $partnerId]);
        return;
    }

    /**
     * A compact fingerprint of one account's own bookings plus its wallet
     * balance, carried on every sync poll. The sync channels are platform-wide
     * version counters, so a player's Bookings tab and balance had no way to
     * learn that THEIR request was accepted/declined or a refund landed.
     *
     * @return array{bookings:string,wallet:float}
     */
    public function getAccountSyncState(string $userId): array {
        // CRC32 sums instead of GROUP_CONCAT, which silently truncates at
        // group_concat_max_len for an account with many bookings.
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CRC32(CONCAT(id, ':', status, ':', COALESCE(ended_early, 0)))), 0) AS sig
               FROM bookings WHERE user_id = ?"
        );
        $s->execute([$userId]);
        $row = $s->fetch() ?: ['n' => 0, 'sig' => 0];
        $bookingsSig = (int)$row['n'] . ':' . (string)$row['sig'];

        $user = $this->getUserById($userId);
        return [
            'bookings' => $bookingsSig,
            'wallet'   => round((float)($user['wallet_balance'] ?? 0), 2),
        ];
    }

    // Notifications
    public function getNotifications($userId) {
        $stmt = $this->pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$userId]);
        $list = $stmt->fetchAll();

        foreach ($list as &$item) {
            if (!empty($item['created_at'])) {
                $ts = strtotime($item['created_at']);
                if ($ts) {
                    $diff = time() - $ts;
                    if ($diff < 60) {
                        $item['time'] = 'Just now';
                    } elseif ($diff < 3600) {
                        $item['time'] = max(1, (int)floor($diff / 60)) . 'm ago';
                    } elseif ($diff < 86400) {
                        $item['time'] = max(1, (int)floor($diff / 3600)) . 'h ago';
                    } elseif ($diff < 172800) {
                        $item['time'] = 'Yesterday';
                    } elseif ($diff < 604800) {
                        $item['time'] = max(1, (int)floor($diff / 86400)) . 'd ago';
                    } else {
                        $item['time'] = date('M j', $ts);
                    }
                }
            }
        }
        unset($item);

        return $list;
    }

    public function addNotification($userId, $title, $body, $type = 'system') {
        return $this->notifier()->notify((string)$userId, (string)$title, (string)$body, (string)$type);
    }

    public function markAllNotificationsRead($userId) {
        $stmt = $this->pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
        return true;
    }

    public function deleteNotification($notifId, $userId) {
        $stmt = $this->pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    }

    // --------------------------------------------------------------------------
    // Promo Code Engine Methods
    // --------------------------------------------------------------------------

    /**
     * All promo codes as actually stored. This is a pure read — it must never
     * fabricate rows. (It once lazily inserted three synthetic promo codes,
     * one of them pre-marked 'disabled' with invented usage counts, the very
     * first time this was called on an empty table. That meant every fresh
     * install — including the test database — silently acquired fictional
     * data and a promo that could never be redeemed. Real seed data belongs
     * in seedInitialData(), guarded by the same .seeded flag as everything
     * else, seeded honestly with times_used = 0.)
     */
    public function getPromoCodes(): array {
        $stmt = $this->pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC");
        $rows = $stmt->fetchAll() ?: [];

        return array_map(function($r) {
            $r['discount_value'] = (float)($r['discount_value'] ?? 0);
            $r['min_spend'] = (float)($r['min_spend'] ?? 0);
            $r['usage_limit'] = (int)($r['usage_limit'] ?? 0);
            $r['times_used'] = (int)($r['times_used'] ?? 0);
            $r['user_limit'] = (int)($r['user_limit'] ?? 1);
            return $r;
        }, $rows);
    }

    public function getPromoCode(string $code): ?array {
        $normalized = strtoupper(trim($code));
        if ($normalized === '') return null;

        $promos = $this->getPromoCodes();
        foreach ($promos as $p) {
            if (strtoupper((string)($p['code'] ?? '')) === $normalized) {
                return $p;
            }
        }
        return null;
    }

    public function createPromoCode(array $data): array {
        $id = $data['id'] ?? ('prm_' . bin2hex(random_bytes(6)));
        $code = strtoupper(trim((string)($data['code'] ?? '')));
        if ($code === '') {
            $code = 'PICKLE' . rand(100, 999);
        }
        $type = in_array(($data['discount_type'] ?? 'fixed'), ['fixed', 'percentage', 'percent'], true) ? (string)($data['discount_type'] ?? 'fixed') : 'fixed';
        if ($type === 'percent') $type = 'percentage';

        $value = (float)($data['discount_value'] ?? 0.0);
        $minSpend = (float)($data['min_spend'] ?? 0.0);
        $usageLimit = (int)($data['usage_limit'] ?? 0);
        $userLimit = (int)($data['user_limit'] ?? 1);
        $expiresAt = !empty($data['expires_at']) ? (string)$data['expires_at'] : null;
        // Omitting status used to store '' (an undefined-key read), which
        // evaluatePromo() treats as inactive, so the code never applied.
        $status = in_array(($data['status'] ?? 'active'), ['active', 'disabled', 'expired'], true) ? (string)($data['status'] ?? 'active') : 'active';
        $createdAt = $data['created_at'] ?? date('Y-m-d H:i:s');

        $row = [
            'id' => $id,
            'code' => $code,
            'discount_type' => $type,
            'discount_value' => $value,
            'min_spend' => $minSpend,
            'max_discount' => null,
            'usage_limit' => $usageLimit,
            'times_used' => (int)($data['times_used'] ?? 0),
            'user_limit' => $userLimit,
            'expires_at' => $expiresAt,
            'status' => $status,
            'created_at' => $createdAt
        ];

        $stmt = $this->pdo->prepare("
            INSERT INTO promo_codes (id, code, discount_type, discount_value, min_spend, usage_limit, times_used, user_limit, expires_at, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                discount_type = VALUES(discount_type),
                discount_value = VALUES(discount_value),
                min_spend = VALUES(min_spend),
                usage_limit = VALUES(usage_limit),
                user_limit = VALUES(user_limit),
                expires_at = VALUES(expires_at),
                status = VALUES(status)
        ");
        $stmt->execute([$id, $code, $type, $value, $minSpend, $usageLimit, $row['times_used'], $userLimit, $expiresAt, $status, $createdAt]);

        return $row;
    }

    public function togglePromoStatus(string $promoId, ?string $newStatus = null): bool {
        $promos = $this->getPromoCodes();
        $target = null;
        foreach ($promos as $p) {
            if (($p['id'] ?? '') === $promoId || strtoupper((string)($p['code'] ?? '')) === strtoupper($promoId)) {
                $target = $p;
                break;
            }
        }
        if (!$target) return false;

        $status = $newStatus ?? (($target['status'] ?? 'active') === 'active' ? 'disabled' : 'active');
        $stmt = $this->pdo->prepare("UPDATE promo_codes SET status = ? WHERE id = ?");
        $stmt->execute([$status, $target['id']]);
        return true;
    }

    public function deletePromoCode(string $promoId): bool {
        $stmt = $this->pdo->prepare("DELETE FROM promo_codes WHERE id = ? OR code = ?");
        $stmt->execute([$promoId, strtoupper($promoId)]);
        return $stmt->rowCount() > 0;
    }

    public function recordPromoRedemption(string $code, string $userId, ?string $bookingId = null, float $discountAmount = 0.0): bool {
        $promo = $this->getPromoCode($code);
        if (!$promo) return false;

        $id = 'rdm_' . bin2hex(random_bytes(6));
        $redemption = [
            'id' => $id,
            'promo_id' => $promo['id'],
            'promo_code' => $promo['code'],
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'discount_amount' => $discountAmount,
            'created_at' => date('Y-m-d H:i:s')
        ];

        // The quote was validated before the booking transaction started, so
        // two players could both pass "times_used < usage_limit" and both
        // redeem the last use. Re-check under a row lock, then increment
        // conditionally; failure aborts the caller's booking transaction.
        $lock = $this->pdo->prepare("SELECT * FROM promo_codes WHERE id = ? FOR UPDATE");
        $lock->execute([$promo['id']]);
        $locked = $lock->fetch();
        if (!$locked || (string)($locked['status'] ?? '') !== 'active') {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code is no longer active.');
        }
        if (!empty($locked['expires_at']) && strtotime((string)$locked['expires_at']) < time()) {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code has expired.');
        }
        $userLimit = (int)($locked['user_limit'] ?? 1);
        if ($userLimit > 0) {
            $uses = $this->pdo->prepare("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ? AND user_id = ?");
            $uses->execute([$promo['id'], $userId]);
            if ((int)$uses->fetchColumn() >= $userLimit) {
                throw new \Picklers\Exceptions\PromoUnavailableException('You have already used this promo code.');
            }
        }
        $stmtInc = $this->pdo->prepare(
            "UPDATE promo_codes SET times_used = times_used + 1 WHERE id = ? AND (usage_limit <= 0 OR times_used < usage_limit)"
        );
        $stmtInc->execute([$promo['id']]);
        if ($stmtInc->rowCount() === 0) {
            throw new \Picklers\Exceptions\PromoUnavailableException('This promo code has reached its usage limit.');
        }

        $stmt = $this->pdo->prepare("INSERT INTO promo_redemptions (id, promo_id, promo_code, user_id, booking_id, discount_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$id, $promo['id'], $promo['code'], $userId, $bookingId, $discountAmount, $redemption['created_at']]);

        return true;
    }

    public function getPromoRedemptionsCount(string $promoId, ?string $userId = null): int {
        if ($userId) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) c FROM promo_redemptions WHERE (promo_id = ? OR promo_code = ?) AND user_id = ?");
            $stmt->execute([$promoId, strtoupper($promoId), $userId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) c FROM promo_redemptions WHERE promo_id = ? OR promo_code = ?");
            $stmt->execute([$promoId, strtoupper($promoId)]);
        }
        return (int)($stmt->fetch()['c'] ?? 0);
    }

    public function getPromoStats(): array {
        $promos = $this->getPromoCodes();
        $totalActive = count(array_filter($promos, fn($p) => ($p['status'] ?? '') === 'active'));
        $totalRedemptions = (int)array_sum(array_column($promos, 'times_used'));

        // The real sum of discount_amount across recorded redemptions, which
        // is legitimately zero when nothing has been redeemed yet.
        $totalSavings = 0.0;
        $stmt = $this->pdo->query("SELECT SUM(discount_amount) s FROM promo_redemptions");
        $totalSavings = (float)($stmt->fetch()['s'] ?? 0.0);

        return [
            'totalActive' => $totalActive,
            'totalRedemptions' => $totalRedemptions,
            'totalSavings' => $totalSavings
        ];
    }

    // --------------------------------------------------------------------------
    // Tournaments
    //
    // The tournament record is owned end to end by TournamentService (roster,
    // bracket generation, result cascade, lifecycle). These stay as thin
    // delegates so existing call sites keep working, and so nothing can write a
    // tournament row that bypasses the service's validation.
    // --------------------------------------------------------------------------

    private function tournamentService(): \Picklers\Services\TournamentService {
        return new \Picklers\Services\TournamentService($this->notifier(), DATA_PATH);
    }

    public function getTournaments($facilityId = null): array {
        return $this->tournamentService()->all($facilityId === null ? null : (string)$facilityId);
    }

    public function getTournamentById(string $id): ?array {
        return $this->tournamentService()->find($id);
    }

    public function createTournament(array $data): ?array {
        return $this->tournamentService()->create($data)['tournament'];
    }

    public function addTournamentPlayer(string $tournId, array $playerData): ?array {
        return $this->tournamentService()->addEntrant($tournId, $playerData)['tournament'];
    }

    public function mixTournamentTeams(string $tournId): ?array {
        return $this->tournamentService()->mixDraw($tournId)['tournament'];
    }

    // --------------------------------------------------------------------------
    // Owner Financials — real aggregate for Dashboard KPIs + Earnings tab
    // --------------------------------------------------------------------------

    public function getOwnerFinancials(int|string $facilityId): array {
        $facilityIdStr = (string)$facilityId;
        $FEE_PCT = 0.05;
        $monthStart = date('Y-m-01');
        $today = date('Y-m-d');

        // Monthly gross (confirmed bookings this calendar month)
        $s = $this->pdo->prepare(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)>=?"
        );
        $s->execute([$facilityId, $monthStart]);
        $monthlyGross = (float)$s->fetchColumn();

        // Today gross
        $s = $this->pdo->prepare(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)=?"
        );
        $s->execute([$facilityId, $today]);
        $todayGross = (float)$s->fetchColumn();

        // Today's session count (for the "X sessions" quick stat)
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             AND DATE(created_at)=?"
        );
        $s->execute([$facilityId, $today]);
        $todaySessionsCount = (int)$s->fetchColumn();

        // All-time gross (for payout totals)
        $s = $this->pdo->prepare(
            "SELECT COALESCE(SUM(price),0) FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')"
        );
        $s->execute([$facilityId]);
        $allTimeGross = (float)$s->fetchColumn();

        // Active bookings (pending + confirmed)
        $s = $this->pdo->prepare(
            "SELECT COUNT(*) FROM bookings WHERE facility_id=? AND status IN ('pending','confirmed')"
        );
        $s->execute([$facilityId]);
        $activeCount = (int)$s->fetchColumn();

        // Distinct new players today
        $s = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM bookings WHERE facility_id=? AND DATE(created_at)=?"
        );
        $s->execute([$facilityId, $today]);
        $newPlayersToday = (int)$s->fetchColumn();

        // Spark: daily revenue last 7 days (scaled to chart range), plus the
        // raw (unscaled) peso amounts for the hero card's Peak Day /
        // Yesterday's Income quick stats.
        $spark = [];
        $dailyRawArr = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $s = $this->pdo->prepare(
                "SELECT COALESCE(SUM(price),0) FROM bookings
                 WHERE facility_id=? AND status IN ('confirmed','completed') AND DATE(created_at)=?"
            );
            $s->execute([$facilityId, $day]);
            $dayGross = (float)$s->fetchColumn();
            $dailyRawArr[] = $dayGross;
            $spark[] = max(0, (int)round($dayGross / 100));
        }

        // Ledger: last 10 confirmed bookings with player name
        $s = $this->pdo->prepare(
            "SELECT b.id, b.court_name, b.price, b.status, b.created_at, b.user_id,
                    COALESCE(u.name,'Player') as player_name
             FROM bookings b
             LEFT JOIN users u ON b.user_id = u.id
             WHERE b.facility_id=? AND b.status IN ('confirmed','completed')
             ORDER BY b.created_at DESC LIMIT 10"
        );
        $s->execute([$facilityId]);
        $rawLedger = $s->fetchAll();

        // Repeater rate: users with >1 booking / total users with bookings
        $s = $this->pdo->prepare(
            "SELECT user_id, COUNT(*) as cnt FROM bookings
             WHERE facility_id=? AND status IN ('confirmed','completed')
             GROUP BY user_id"
        );
        $s->execute([$facilityId]);
        $userCounts = $s->fetchAll(\PDO::FETCH_KEY_PAIR);
        $totalUsers = count($userCounts);
        $repeaters  = count(array_filter($userCounts, fn($c) => $c > 1));
        $repeaterRate = $totalUsers > 0 ? round($repeaters / $totalUsers * 100) : 0;

        // Past-30-day daily breakdown for the Daily Income modal, which used
        // to render a hardcoded table of invented dates and revenue for every
        // owner. Dated by created_at, like every other figure here.
        $windowStart = date('Y-m-d', strtotime('-29 days'));
        $s = $this->pdo->prepare(
            "SELECT DATE(created_at) AS d, user_id, court_name, price FROM bookings
              WHERE facility_id = ? AND status IN ('confirmed','completed') AND DATE(created_at) >= ?"
        );
        $s->execute([$facilityId, $windowStart]);
        $windowRows = $s->fetchAll();

        $byDay = [];
        foreach ($windowRows as $r) {
            $d = (string)$r['d'];
            $byDay[$d]['revenue'] = ($byDay[$d]['revenue'] ?? 0.0) + (float)$r['price'];
            $byDay[$d]['sessions'] = ($byDay[$d]['sessions'] ?? 0) + 1;
            $byDay[$d]['players'][(string)$r['user_id']] = true;
            $court = trim((string)$r['court_name']);
            if ($court !== '') {
                $byDay[$d]['courts'][$court] = ($byDay[$d]['courts'][$court] ?? 0) + 1;
            }
        }

        $dailyBreakdown = [];
        $breakdownPeak = 0.0;
        for ($i = 0; $i < 30; $i++) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $dayTs = strtotime($d);
            $dayRevenue = round((float)($byDay[$d]['revenue'] ?? 0), 2);
            $breakdownPeak = max($breakdownPeak, $dayRevenue);
            $dayCourts = $byDay[$d]['courts'] ?? [];
            arsort($dayCourts);
            $dailyBreakdown[] = [
                'date'         => date('M j, Y', $dayTs),
                'dow'          => date('l', $dayTs),
                'month'        => strtolower(date('F', $dayTs)),
                'is_today'     => $i === 0,
                'is_yesterday' => $i === 1,
                'is_weekend'   => (int)date('N', $dayTs) >= 6,
                'sessions'     => (int)($byDay[$d]['sessions'] ?? 0),
                'players'      => count($byDay[$d]['players'] ?? []),
                'court'        => $dayCourts ? (string)array_key_first($dayCourts) : '—',
                'revenue'      => $dayRevenue,
            ];
        }
        foreach ($dailyBreakdown as &$breakdownDay) {
            $breakdownDay['is_peak'] = $breakdownPeak > 0 && $breakdownDay['revenue'] === $breakdownPeak;
        }
        unset($breakdownDay);

        // Build ledger rows
        $ledger = [];
        foreach ($rawLedger as $row) {
            $gross = (float)($row['price'] ?? 0);
            $fee   = round($gross * $FEE_PCT, 2);
            $net   = round($gross - $fee, 2);
            $ts    = strtotime((string)($row['created_at'] ?? ''));
            if ($ts !== false) {
                $diff = time() - $ts;
                if ($diff < 3600)      $timeLabel = round($diff / 60) . 'm ago';
                elseif ($diff < 86400) $timeLabel = round($diff / 3600) . 'h ago';
                else                   $timeLabel = date('M j, g:i A', $ts);
            } else {
                $timeLabel = (string)($row['created_at'] ?? '');
            }
            $ledger[] = [
                'tx_id'  => 'tx-' . strtoupper(substr((string)($row['id'] ?? 'xxx'), -6)),
                'time'   => $timeLabel,
                'court'  => (string)($row['court_name'] ?? 'Court'),
                'player' => (string)($row['player_name'] ?? 'Player'),
                'gross'  => $gross,
                'fee'    => $fee,
                'net'    => $net,
                // "Settled" was hardcoded, implying money already paid out.
                'status' => ucfirst((string)($row['status'] ?? 'confirmed')),
            ];
        }

        $payoutsRequested = $this->getPayoutRequestedTotal($facilityId);
        $allTimeFee = round($allTimeGross * $FEE_PCT, 2);
        $allTimeNet = round($allTimeGross - $allTimeFee, 2);

        // If spark is all zeros (no confirmed bookings yet) supply a flat baseline so
        // the sparkline component renders without an empty graph.
        if (array_sum($spark) === 0) {
            $spark = [0, 0, 0, 0, 0, 0, 0];
        }

        // dailyRawArr runs oldest (index 0, 6 days ago) to newest (index 6, today),
        // so index 5 is yesterday. Daily Avg is this month's gross spread over the
        // days elapsed so far this month — the same quantity the Dashboard's hero
        // card has always labelled "Daily Avg" next to "Monthly Revenue".
        $yesterdayGross = $dailyRawArr[5] ?? 0.0;
        $peakDayGross = !empty($dailyRawArr) ? max($dailyRawArr) : 0.0;
        $daysElapsedThisMonth = max(1, (int)date('j'));
        $dailyAvgGross = $monthlyGross / $daysElapsedThisMonth;

        return [
            'monthly_gross'    => $monthlyGross,
            'today_gross'      => $todayGross,
            'today_sessions'   => $todaySessionsCount,
            'yesterday_gross'  => $yesterdayGross,
            'peak_day_gross'   => $peakDayGross,
            'daily_avg_gross'  => $dailyAvgGross,
            'active_bookings'  => $activeCount,
            'new_players_today'=> $newPlayersToday,
            'repeater_rate'    => $repeaterRate,
            'repeaters_count'  => $repeaters,
            'repeat_eligible_count' => $totalUsers,
            'spark'            => $spark,
            'gross'            => $allTimeGross,
            'fee_pct'          => $FEE_PCT,
            'fee_amount'       => $allTimeFee,
            'net'              => $allTimeNet,
            // Earlier payout requests are deducted from what is available.
            'payouts_requested'=> $payoutsRequested,
            'available'        => max(0.0, round($allTimeNet * 0.80 - $payoutsRequested, 2)),
            'ledger'           => $ledger,
            'daily_breakdown'  => $dailyBreakdown,
            'daily_breakdown_peak' => $breakdownPeak,
        ];
    }

    /** Total already requested for payout (anything not rejected/cancelled) for one facility. */
    public function getPayoutRequestedTotal(int|string $facilityId): float {
        try {
            $s = $this->pdo->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payout_requests
                  WHERE facility_id = ? AND status NOT IN ('rejected', 'cancelled', 'failed')"
            );
            $s->execute([(string)$facilityId]);
            return (float)$s->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[DB Error] getPayoutRequestedTotal failed: ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Record a payout request only while it still fits the facility's
     * available balance.
     *
     * The balance check lived in the controller, separate from the insert, so
     * two submissions at once both passed it. Check and insert now happen
     * under one per-facility lock.
     *
     * @return array{success:bool, message?:string, payout?:array}
     */
    public function createPayoutRequest(string $ownerId, string $facilityId, float $amount, string $method, ?string $notes = null): array {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Payout amount must be greater than zero.'];
        }

        $lockName = 'picklers_payout_' . $facilityId;
        $locked = false;
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        $locked = (int)$lock->fetchColumn() === 1;
        if (!$locked) {
            return ['success' => false, 'message' => 'Another payout request for this facility is being processed. Please try again.'];
        }

        try {
            $available = (float)($this->getOwnerFinancials($facilityId)['available'] ?? 0.0);
            if ($amount > $available) {
                return [
                    'success' => false,
                    'message' => 'Requested amount exceeds your available balance of ₱' . number_format($available, 2) . '.',
                ];
            }

            $id  = 'pay_' . bin2hex(random_bytes(6));
            $now = date('Y-m-d H:i:s');
            $record = [
                'id'          => $id,
                'owner_id'    => $ownerId,
                'facility_id' => $facilityId,
                'amount'      => $amount,
                'method'      => $method,
                'status'      => 'pending',
                // Destination account name/number, so whoever processes this
                // payout manually knows where the money actually needs to go.
                'notes'       => $notes,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];

            $this->pdo->prepare(
                "INSERT INTO payout_requests (id, owner_id, facility_id, amount, method, status, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
            )->execute([$id, $ownerId, $facilityId, $amount, $method, $notes, $now, $now]);

            return ['success' => true, 'payout' => $record];
        } finally {
            if ($locked) {
                $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            }
        }
    }
    // --------------------------------------------------------------------------
    // Owner Conversations — conversation partners for the Messages tab
    // --------------------------------------------------------------------------

    public function getConversationPartners(string $ownerId): array {
        $stmt = $this->pdo->prepare(
            "SELECT partner_id, MAX(created_at) as last_at,
                    SUM(CASE WHEN receiver_id=? AND is_read=0 THEN 1 ELSE 0 END) as unread
             FROM (
                 SELECT CASE WHEN sender_id=? THEN receiver_id ELSE sender_id END as partner_id,
                        created_at, receiver_id, is_read
                 FROM direct_messages
                 WHERE sender_id=? OR receiver_id=?
             ) t
             GROUP BY partner_id
             ORDER BY last_at DESC
             LIMIT 20"
        );
        $stmt->execute([$ownerId, $ownerId, $ownerId, $ownerId]);
        $partners = $stmt->fetchAll();

        $result = [];
        foreach ($partners as $p) {
            $uid  = (string)($p['partner_id'] ?? '');
            $user = $this->getUserById($uid);
            if (!$user) continue;

            $msgs = $this->getMessages($ownerId, $uid);
            $lastMsg = end($msgs);

            $result[] = [
                'id'           => 'conv_' . substr($uid, -8),
                'user_id'      => $uid,
                'user_name'    => (string)($user['name']       ?? 'Player'),
                'user_avatar'  => (string)($user['avatar_url'] ?? ''),
                'last_message' => $lastMsg ? (string)$lastMsg['content'] : '',
                'time'         => $lastMsg ? $this->relativeTime((string)($lastMsg['created_at'] ?? '')) : '',
                'unread'       => (int)($p['unread'] ?? 0),
                'messages'     => array_map(fn($m) => [
                    'sender' => (string)($m['sender_id'] ?? '') === $ownerId ? 'owner' : 'user',
                    'text'   => (string)($m['content'] ?? ''),
                    'time'   => date('g:i A', strtotime((string)($m['created_at'] ?? 'now'))),
                ], $msgs),
            ];
        }
        return $result;
    }

    private function relativeTime(string $datetime): string {
        $ts = strtotime($datetime);
        if ($ts === false) return $datetime;
        $diff = time() - $ts;
        if ($diff < 60)    return 'just now';
        if ($diff < 3600)  return round($diff / 60)  . 'm ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        return date('M j', $ts);
    }

}
