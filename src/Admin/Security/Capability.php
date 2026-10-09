<?php
declare(strict_types=1);

namespace Picklers\Admin\Security;

/**
 * The admin capability matrix — the single source of truth for who may do what.
 *
 * Two tiers only (the smallest coherent model): every active administrator
 * (ROLE_ADMIN) can run day-to-day operations; actions that move money, change
 * who holds administrator power, irreversibly remove accounts, act as another
 * person or message everyone need an explicitly granted privileged
 * administrator (ROLE_PRIVILEGED_ADMIN). Legacy admins are NOT auto-promoted;
 * see `bin/console picklers:admin:privilege`.
 *
 * Rendered as a table in docs/admin-migration/acceptance-matrix.md.
 */
final class Capability
{
    public const VIEW_CONSOLE        = 'admin.console.view';
    public const REVIEW_APPLICATIONS = 'admin.applications.review';
    public const VIEW_DOCUMENTS      = 'admin.applications.documents';
    public const MANAGE_FACILITIES   = 'admin.facilities.manage';
    public const MANAGE_BOOKINGS     = 'admin.bookings.manage';
    public const VERIFY_USERS        = 'admin.users.verify';
    public const NOTIFY_USER         = 'admin.users.notify';
    public const RESET_PASSWORD      = 'admin.users.reset_password';
    public const DEACTIVATE_USER     = 'admin.users.deactivate';
    public const SET_BASIC_ROLE      = 'admin.users.role_basic';
    public const MODERATE_CONTENT    = 'admin.moderation.manage';
    public const MANAGE_PROMOS       = 'admin.promos.manage';
    public const VIEW_LEDGER         = 'admin.ledger.view';
    public const VIEW_REPORTS        = 'admin.reports.view';
    public const EXPORT_DATA         = 'admin.export';
    public const VIEW_AUDIT          = 'admin.audit.view';
    public const VIEW_SYSTEM         = 'admin.system.view';

    // Privileged tier
    public const SET_ADMIN_ROLE      = 'admin.users.role_admin';
    public const MANAGE_PRIVILEGE    = 'admin.users.privilege';
    public const DEACTIVATE_ADMIN    = 'admin.users.deactivate_admin';
    public const DELETE_USER         = 'admin.users.delete';
    public const ADJUST_WALLET       = 'admin.wallet.adjust';
    public const IMPERSONATE         = 'admin.users.impersonate';
    public const BROADCAST           = 'admin.notifications.broadcast';

    /** capability => minimum role */
    public const MATRIX = [
        self::VIEW_CONSOLE => 'ROLE_ADMIN',
        self::REVIEW_APPLICATIONS => 'ROLE_ADMIN',
        self::VIEW_DOCUMENTS => 'ROLE_ADMIN',
        self::MANAGE_FACILITIES => 'ROLE_ADMIN',
        self::MANAGE_BOOKINGS => 'ROLE_ADMIN',
        self::VERIFY_USERS => 'ROLE_ADMIN',
        self::NOTIFY_USER => 'ROLE_ADMIN',
        self::RESET_PASSWORD => 'ROLE_ADMIN',
        self::DEACTIVATE_USER => 'ROLE_ADMIN',
        self::SET_BASIC_ROLE => 'ROLE_ADMIN',
        self::MODERATE_CONTENT => 'ROLE_ADMIN',
        self::MANAGE_PROMOS => 'ROLE_ADMIN',
        self::VIEW_LEDGER => 'ROLE_ADMIN',
        self::VIEW_REPORTS => 'ROLE_ADMIN',
        self::EXPORT_DATA => 'ROLE_ADMIN',
        self::VIEW_AUDIT => 'ROLE_ADMIN',
        self::VIEW_SYSTEM => 'ROLE_ADMIN',
        self::SET_ADMIN_ROLE => 'ROLE_PRIVILEGED_ADMIN',
        self::MANAGE_PRIVILEGE => 'ROLE_PRIVILEGED_ADMIN',
        self::DEACTIVATE_ADMIN => 'ROLE_PRIVILEGED_ADMIN',
        self::DELETE_USER => 'ROLE_PRIVILEGED_ADMIN',
        self::ADJUST_WALLET => 'ROLE_PRIVILEGED_ADMIN',
        self::IMPERSONATE => 'ROLE_PRIVILEGED_ADMIN',
        self::BROADCAST => 'ROLE_PRIVILEGED_ADMIN',
    ];

    public const LABELS = [
        self::VIEW_CONSOLE => 'Open the admin console',
        self::REVIEW_APPLICATIONS => 'Approve / reject partner applications',
        self::VIEW_DOCUMENTS => 'View private application documents',
        self::MANAGE_FACILITIES => 'Edit facilities, court maintenance, suspend/reactivate',
        self::MANAGE_BOOKINGS => 'Change booking status, cancel bookings',
        self::VERIFY_USERS => 'Verify / revoke verification',
        self::NOTIFY_USER => 'Send an individual notice',
        self::RESET_PASSWORD => 'Reset a non-admin password',
        self::DEACTIVATE_USER => 'Deactivate / reactivate non-admin accounts',
        self::SET_BASIC_ROLE => 'Switch accounts between player and owner',
        self::MODERATE_CONTENT => 'Flag, hide, restore, resolve, dismiss content',
        self::MANAGE_PROMOS => 'Create, enable/disable, archive promo codes',
        self::VIEW_LEDGER => 'View the financial ledger',
        self::VIEW_REPORTS => 'View analytics and reports',
        self::EXPORT_DATA => 'Export CSV',
        self::VIEW_AUDIT => 'Read the audit trail',
        self::VIEW_SYSTEM => 'View system health',
        self::SET_ADMIN_ROLE => 'Grant / remove administrator role',
        self::MANAGE_PRIVILEGE => 'Grant / revoke privileged administrator',
        self::DEACTIVATE_ADMIN => 'Deactivate an administrator',
        self::DELETE_USER => 'Permanently delete an account',
        self::ADJUST_WALLET => 'Credit / debit a wallet',
        self::IMPERSONATE => 'View the app as a user (impersonate)',
        self::BROADCAST => 'Broadcast a notice to many users',
    ];
}
