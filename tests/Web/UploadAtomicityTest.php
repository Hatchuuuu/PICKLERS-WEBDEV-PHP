<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadAtomicityTest extends AdminWebTestCase
{
    private function upload(string $name): UploadedFile
    {
        $file = tempnam(sys_get_temp_dir(), 'pkl');
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($file, $name . '.png', 'image/png', null, true);
    }

    public function testApplicationWriteFailureLeavesNoRowsOrPrivateUploads(): void
    {
        $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
        $this->db()->executeStatement("UPDATE users SET verification_status = 'unverified' WHERE id = 'usr_fx_p1'");
        $dir = $_ENV['PRIVATE_DOCUMENT_DIR'];
        $before = glob($dir . '/repair_*.png') ?: [];
        $this->db()->executeStatement("CREATE TRIGGER test_application_fail BEFORE UPDATE ON users FOR EACH ROW BEGIN IF NEW.id = 'usr_fx_p1' AND NEW.verification_status = 'pending_owner_review' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected application failure'; END IF; END");
        try {
            $this->signIn('usr_fx_p1');
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'upload-test';
            $this->client->request('POST', '/owner-application', ['facility_name' => 'Repair Arena', 'address' => 'Dumaguete', 'owner_name' => 'Paula', 'business_email' => 'paula@fixture.test', 'phone' => '09175550199', 'entity_name' => 'Repair Arena Inc', 'reg_number' => 'DTI-TEST'], ['permit_file' => $this->upload('repair_permit'), 'gov_id_file' => $this->upload('repair_id')], ['HTTP_X_CSRF_TOKEN' => 'upload-test', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseStatusCodeSame(500);
            self::assertSame(0, (int)$this->scalar("SELECT COUNT(*) FROM owner_applications WHERE user_id = 'usr_fx_p1'"));
            self::assertSame($before, glob($dir . '/repair_*.png') ?: []);
            self::assertSame('unverified', $this->scalar("SELECT verification_status FROM users WHERE id = 'usr_fx_p1'"));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_application_fail');
            $this->db()->executeStatement("DELETE FROM owner_applications WHERE user_id = 'usr_fx_p1'");
            foreach (array_diff(glob($dir . '/repair_*.png') ?: [], $before) as $file) unlink($file);
        }
    }

    public function testLogoWriteExceptionCleansNewUploadAndPreservesPreviousImage(): void
    {
        $facility = $this->db()->fetchAssociative("SELECT id, image, name FROM facilities WHERE owner_id = 'usr_fx_owner1' LIMIT 1");
        $dir = dirname(__DIR__, 2) . '/public/uploads/facilities';
        $before = glob($dir . '/facility_' . $facility['id'] . '_*') ?: [];
        $this->db()->executeStatement("CREATE TRIGGER test_logo_fail BEFORE UPDATE ON facilities FOR EACH ROW BEGIN IF NEW.name = 'Upload failure fixture' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected logo failure'; END IF; END");
        try {
            $this->signIn('usr_fx_owner1');
            $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'upload-test';
            $this->client->request('POST', '/owner.php?action=update_facility_settings', ['facility_id' => $facility['id'], 'facility_name' => 'Upload failure fixture'], ['logo' => $this->upload('repair_logo')], ['HTTP_X_CSRF_TOKEN' => 'upload-test']);
            self::assertResponseStatusCodeSame(500);
            self::assertSame($before, glob($dir . '/facility_' . $facility['id'] . '_*') ?: []);
            self::assertSame($facility['image'], $this->scalar('SELECT image FROM facilities WHERE id = ?', [$facility['id']]));
        } finally {
            $this->db()->executeStatement('DROP TRIGGER test_logo_fail');
            foreach (array_diff(glob($dir . '/facility_' . $facility['id'] . '_*') ?: [], $before) as $file) unlink($file);
        }
    }
}
