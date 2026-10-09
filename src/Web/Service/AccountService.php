<?php
declare(strict_types=1);

namespace Picklers\Web\Service;

use Picklers\Core\Database;
use Picklers\Helpers\Format;
use Picklers\Admin\Security\PlatformSession;
use Picklers\Services\AuthService;
use Picklers\Repositories\BookingRepository;
use Picklers\Repositories\NotificationRepository;
use Picklers\Web\Http\ApiError;

/**
 * Section 3 — the signed-in player's own account and notifications. Moved from
 * the legacy ApiController unchanged in behaviour; persistence stays in the
 * legacy services and Database.
 */
final class AccountService
{
    /** Ceiling for a stored avatar. A 256px re-encode lands well under this. */
    public const MAX_AVATAR_BYTES = 200000;

    public function __construct(
        private readonly AuthService $auth,
        private readonly NotificationRepository $notifications,
        private readonly BookingRepository $bookings,
        private readonly Database $db,
        private readonly PlatformSession $session,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function notifications(string $userId): array
    {
        return $this->notifications->getNotifications($userId);
    }

    public function markAllRead(string $userId): void
    {
        $this->notifications->markAllAsRead($userId);
    }

    public function deleteNotification(string $userId, string $notificationId): void
    {
        if ($notificationId !== '') {
            $this->notifications->deleteNotification($notificationId, $userId);
        }
    }

    public function changePassword(string $userId, string $current, string $new, string $confirm): void
    {
        if ($new === '') {
            throw new ApiError('Please enter a new password.');
        }
        if ($confirm !== '' && $new !== $confirm) {
            throw new ApiError('New passwords do not match. Please re-check.');
        }
        $result = $this->auth->changePassword($userId, $current, $new);
        if (!$result['success']) {
            throw new ApiError($result['error'] ?? 'Failed to update password.');
        }
        // Keep THIS session; every other session is signed out.
        $this->session->markAuthenticatedNow();
    }

    /**
     * @param array<string,mixed> $user  the current (sanitised) users row
     * @param array<string,mixed> $input name, email, phone, level, avatar_url
     * @return array<string,mixed> the updated users row
     */
    public function updateProfile(array $user, array $input): array
    {
        $name = trim((string)($input['name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        // One stored form per number, so sign-in and the uniqueness check recognise it however it was typed.
        if ($phone !== '') {
            $phone = Format::phMobile($phone) ?? $phone;
        }
        $level = trim((string)($input['level'] ?? ''));

        // users.name/phone/level are VARCHAR(120/32/20): under STRICT_TRANS_TABLES an over-long value is a hard SQL error.
        if ($name !== '' && (mb_strlen($name) > 120 || preg_match('/[<>]/', $name))) {
            throw new ApiError('Please enter a valid name (up to 120 characters, no < or >).');
        }
        if ($level !== '' && (mb_strlen($level) > 20 || preg_match('/[<>]/', $level))) {
            throw new ApiError('Please choose a valid skill level.');
        }
        if ($phone !== '' && $phone !== (string)($user['phone'] ?? '')) {
            if (strlen($phone) > 32 || !preg_match('/^[0-9+()\-\s]{7,32}$/', $phone)) {
                throw new ApiError('Please enter a valid mobile number.');
            }
            // Phone is a sign-in identifier: it must stay unique.
            $owner = $this->auth->getUserByEmailOrPhone($phone);
            if ($owner && (string)$owner['id'] !== (string)$user['id']) {
                throw new ApiError('This mobile number is already linked to another account.');
            }
        }

        $fields = array_filter(['name' => $name, 'phone' => $phone, 'level' => $level], static fn ($v) => !empty($v));
        if ($email !== '' && $email !== ($user['email'] ?? '')) {
            if (strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiError('Please enter a valid email address');
            }
            $existing = $this->auth->getUserByEmailOrPhone($email);
            if ($existing && $existing['id'] !== $user['id']) {
                throw new ApiError('An account with this email address already exists');
            }
            $fields['email'] = $email;
        }

        $avatar = trim((string)($input['avatar_url'] ?? ''));
        if ($avatar !== '') {
            // Stored avatars are inlined into every page rendering the user: cap the size
            // and accept only an http(s) URL or a small image data URL.
            if (strlen($avatar) > self::MAX_AVATAR_BYTES) {
                throw new ApiError('That image is too large. Please choose a smaller photo.', 413);
            }
            if (!preg_match('#^https?://#i', $avatar) && !preg_match('#^data:image/(png|jpe?g|webp|gif);base64,#i', $avatar)) {
                throw new ApiError('Unsupported image format.');
            }
            $fields['avatar_url'] = $avatar;
        }

        return $this->auth->updateUser((string)$user['id'], $fields);
    }

    /**
     * A user may REQUEST verification; only an administrator can grant it.
     *
     * @param array<string,mixed> $user
     * @return array{status:string,message:string,user:?array}  user is null when unchanged
     */
    public function requestVerification(array $user): array
    {
        $status = (string)($user['verification_status'] ?? 'unverified');
        if ($status === 'verified') {
            return ['status' => 'verified', 'message' => 'Your identity is already verified.', 'user' => null];
        }
        if ($status === 'pending_review') {
            return ['status' => 'pending_review', 'message' => 'Your verification is already under review. We will notify you once it is approved.', 'user' => null];
        }
        $updated = $this->auth->updateUser((string)$user['id'], ['verification_status' => 'pending_review']);
        $this->notifications->addNotification(
            (string)$user['id'],
            'Verification Requested 🛡️',
            'Thanks! Your identity verification request has been submitted. Our team will review it shortly.',
            'system'
        );

        return ['status' => 'pending_review', 'message' => 'Verification request submitted — our team will review it shortly.', 'user' => $updated];
    }

    /**
     * Soft delete (financial records referenced by foreign keys survive), after
     * releasing the courts this account still holds under the normal
     * cancellation policy, then sign out.
     *
     * @param array<string,mixed> $user
     */
    public function deleteOwnAccount(array $user, string $typedConfirmation): void
    {
        if (trim($typedConfirmation) !== 'DELETE') {
            throw new ApiError('Type DELETE in capitals to confirm.');
        }
        if (!empty($user['is_admin'])) {
            throw new ApiError('Administrator accounts cannot be self-deleted. Contact another administrator.', 403);
        }
        // The facility listing, its bookings and payouts would stay attached to an account nobody can sign in to.
        if (!empty($user['is_owner'])) {
            throw new ApiError('Facility owner accounts cannot be deleted from the app while a facility is listed. Please contact Picklers support.', 403);
        }

        $id = (string)$user['id'];
        foreach ($this->bookings->getBookings($id) as $held) {
            if (in_array((string)($held['status'] ?? ''), ['pending', 'confirmed'], true) && !$this->db->isBookingPast($held)) {
                $this->bookings->cancelBooking((string)$held['id'], $id);
            }
        }
        $this->auth->updateUser($id, [
            'role' => 'deleted',
            'email' => 'deleted_' . $id . '@picklers.invalid',
            'is_admin' => 0,
            'is_owner' => 0,
            'verification_status' => 'unverified',
        ]);
        $this->session->destroy();
    }
}
