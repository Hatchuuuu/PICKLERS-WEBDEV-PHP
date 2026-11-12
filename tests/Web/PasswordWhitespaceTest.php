<?php
declare(strict_types=1);

namespace Picklers\Tests\Web;

use Picklers\Tests\Admin\AdminWebTestCase;

final class PasswordWhitespaceTest extends AdminWebTestCase
{
    public function testFormAndJsonPasswordChangesPreserveEveryCharacter(): void
    {
        $current = ' Fixture-Pass-2026 ';
        $this->db()->update('users', ['password_hash' => password_hash($current, PASSWORD_BCRYPT)], ['id' => 'usr_fx_p5']);
        $this->signIn('usr_fx_p5');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'password-test-token';
        foreach (['form', 'json'] as $format) {
            $new = $format === 'form' ? ' Form-Secret-123 ' : ' Json-Secret-456 ';
            $fields = ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $new];
            if ($format === 'form') {
                $this->client->request('POST', '/api.php', ['action' => 'change_password'] + $fields, [], ['HTTP_X_CSRF_TOKEN' => 'password-test-token']);
            } else {
                $this->client->jsonRequest('POST', '/api/me/password', $fields, ['HTTP_X_CSRF_TOKEN' => 'password-test-token']);
            }
            self::assertResponseIsSuccessful();
            self::assertTrue(json_decode((string)$this->client->getResponse()->getContent(), true)['success']);
            $hash = (string)$this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p5'");
            self::assertTrue(password_verify($new, $hash));
            self::assertFalse(password_verify(trim($new), $hash));
            self::assertSame('usr_fx_p5', $this->webUser()['id'] ?? null);
            $current = $new;
        }
    }

    public function testConfirmationSpacesAreSignificantAndArrayInputIsRefused(): void
    {
        $this->signIn('usr_fx_p5');
        $_SESSION['_sf2_attributes']['_csrf/picklers'] = 'password-test-token';
        $originalHash = $this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p5'");
        foreach (['New-Secret-123', ['New-Secret-123']] as $confirm) {
            $this->client->jsonRequest('POST', '/api/me/password', [
                'current_password' => 'Fixture-Pass-2026', 'new_password' => ' New-Secret-123 ', 'confirm_password' => $confirm,
            ], ['HTTP_X_CSRF_TOKEN' => 'password-test-token']);
            self::assertResponseStatusCodeSame(400);
            self::assertSame($originalHash, $this->scalar("SELECT password_hash FROM users WHERE id = 'usr_fx_p5'"));
        }
    }
}
