<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\Encryption\Test\Unit;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\Adapter\SodiumChachaIetf;
use Magento\Framework\Encryption\Crypt;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Test case for \Magento\Framework\Encryption\Encryptor
 */
class EncryptorTest extends TestCase
{
    private const CRYPT_KEY_1 = 'g9mY9KLrcuAVJfsmVUSRkKFLDdUPVkaZ';

    private const CRYPT_KEY_2 = '7wEjmrliuqZQ1NQsndSa8C8WHvddeEbN';

    /**
     * @var Encryptor
     */
    private $encryptor;

    /**
     * @var Random|MockObject
     */
    private $randomGeneratorMock;

    /**
     * @var KeyValidator|MockObject
     */
    private $keyValidatorMock;

    /**
     * @inheritdoc
     */
    protected function setUp(): void
    {
        $this->randomGeneratorMock = $this->createMock(Random::class);
        /** @var DeploymentConfig|MockObject $deploymentConfigMock */
        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock->expects($this->any())
            ->method('get')
            ->with(Encryptor::PARAM_CRYPT_KEY)
            ->willReturn(self::CRYPT_KEY_1);
        $this->keyValidatorMock = $this->createMock(KeyValidator::class);
        $this->encryptor = (new ObjectManager($this))->getObject(
            Encryptor::class,
            [
                'random' => $this->randomGeneratorMock,
                'deploymentConfig' => $deploymentConfigMock,
                'keyValidator' => $this->keyValidatorMock
            ]
        );
    }

    /**
     * Hashing without a salt.
     *
     * @return void
     */
    public function testGetHashNoSalt(): void
    {
        $this->randomGeneratorMock->expects($this->never())->method('getRandomString');
        $expected = '1421feadb52d556a2045588672d8880d812ecc81ebb53dd98f6ff43500786b36';
        $actual = $this->encryptor->getHash('password');
        $this->assertEquals($expected, $actual);
    }

    /**
     * Providing salt for hash.
     *
     * @return void
     */
    public function testGetHashSpecifiedSalt(): void
    {
        $this->randomGeneratorMock->expects($this->never())->method('getRandomString');
        if ($this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13) {
            $version = Encryptor::HASH_VERSION_ARGON2ID13;
            $expected = '7640855aef9cb6ffd20229601d2904a2192e372b391db8230d7faf073b393e4c:salt:2';
        } else {
            $version = Encryptor::HASH_VERSION_SHA256;
            $expected = '13601bda4ea78e55a07b98866d2be6be0744e3866f13c00c811cab608a28f322:salt:1';
        }
        $actual = $this->encryptor->getHash('password', 'salt', $version);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Hashing with random salt.
     *
     * @return void
     */
    public function testGetHashRandomSaltDefaultLength(): void
    {
        $salt = 'random-salt';
        $salt = str_pad(
            $salt,
            $this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13
                ? SODIUM_CRYPTO_PWHASH_SALTBYTES : 32,
            $salt
        );
        if ($this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13) {
            $version = Encryptor::HASH_VERSION_ARGON2ID13;
            $expected = '2d78b5e93b683c4d3b0574c1ced8e40ddec7730c2e1b35f282b2c955b5cb7262:' . $salt . ':2';
        } else {
            $version = Encryptor::HASH_VERSION_SHA256;
            $expected = '2c210995b6029cdbd3a88c32be1083fdca263cf19600247d09a2409b30f09f16:' . $salt . ':1';
        }
        $this->randomGeneratorMock
            ->expects($this->once())
            ->method('getRandomString')
            ->willReturn($salt);
        $actual = $this->encryptor->getHash('password', true, $version);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Hashing with random salt of certain length.
     *
     * @return void
     */
    public function testGetHashRandomSaltSpecifiedLength(): void
    {
        $this->randomGeneratorMock
            ->expects($this->once())
            ->method('getRandomString')
            ->willReturn(
                $this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13 ?
                    'random_salt12345' :
                    'random_salt'
            );
        $expected = $this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13 ?
            'ca7982945fa90444b78d586678ff1c223ce13f99a39ec9541eae8b63ada3816a:random_salt12345:2' :
            '4c5cab8dd00137d11258f8f87b93fd17bd94c5026fc52d3c5af911dd177a2611:random_salt:1';
        $version = $this->encryptor->getLatestHashVersion() >= Encryptor::HASH_VERSION_ARGON2ID13
            ? Encryptor::HASH_VERSION_ARGON2ID13 : Encryptor::HASH_VERSION_SHA256;
        $actual = $this->encryptor->getHash('password', 11, $version);
        $this->assertEquals($expected, $actual);
    }

    /**
     * Validating hashes generated by different algorithms.
     *
     * @param string $password
     * @param string $hash
     * @param bool $expected
     *
     * @return void
     * @dataProvider validateHashDataProvider
     */
    public function testValidateHash($password, $hash, $expected, int $requiresVersion): void
    {
        if ($requiresVersion > $this->encryptor->getLatestHashVersion()) {
            $this->markTestSkipped('On current installation encryptor does not support algo #' . $requiresVersion);
        }
        $actual = $this->encryptor->validateHash($password, $hash);
        $this->assertEquals($expected, $actual);
    }

    /**
     * List of values and their hashes using different algorithms.
     *
     * @return array
     */
    public function validateHashDataProvider(): array
    {
        return [
            ['password', 'hash:salt:1', false, 1],
            ['password', '67a1e09bb1f83f5007dc119c14d663aa:salt:0', true, 0],
            ['password', '13601bda4ea78e55a07b98866d2be6be0744e3866f13c00c811cab608a28f322:salt:1', true, 1],
            //Hashes after customer:hash:upgrade command issued
            //Upgraded from version #1 to #2
            ['password', 'c6aad9e058f6c4b06187c06d2b69bf506a786af030f81fb6d83778422a68205e:salt:1:2', true, 2],
            //From #0 to #1
            ['password', '3b68ca4706cbae291455e4340478076c1e1618e742b6144cfcc3e50f648903e4:salt:0:1', true, 1]
        ];
    }

    /**
     * Encrypting with empty keys.
     *
     * @param mixed $key
     *
     * @return void
     * @dataProvider emptyKeyDataProvider
     */
    public function testEncryptWithEmptyKey($key): void
    {
        $this->expectException('SodiumException');
        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock->expects($this->any())
            ->method('get')
            ->with(Encryptor::PARAM_CRYPT_KEY)
            ->willReturn($key);
        $model = new Encryptor($this->randomGeneratorMock, $deploymentConfigMock);
        $value = 'arbitrary_string';
        $this->assertEquals($value, $model->encrypt($value));
    }

    /**
     * Seeing how decrypting works with invalid keys.
     *
     * @param mixed $key
     *
     * @return void
     * @dataProvider emptyKeyDataProvider
     */
    public function testDecryptWithEmptyKey($key): void
    {
        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock->expects($this->any())
            ->method('get')
            ->with(Encryptor::PARAM_CRYPT_KEY)
            ->willReturn($key);
        $model = new Encryptor($this->randomGeneratorMock, $deploymentConfigMock);
        $value = 'arbitrary_string';
        $this->assertEquals('', $model->decrypt($value));
    }

    /**
     * List of invalid keys.
     *
     * @return array
     */
    public function emptyKeyDataProvider(): array
    {
        return [[null], [0], [''], ['0']];
    }

    /**
     * Seeing that encrypting uses sodium.
     *
     * @return void
     */
    public function testEncrypt(): void
    {
        // sample data to encrypt
        $data = 'Mares eat oats and does eat oats, but little lambs eat ivy.';

        $actual = $this->encryptor->encrypt($data);

        // Extract the initialization vector and encrypted data
        $encryptedParts = explode(':', $actual, 3);

        $crypt = new SodiumChachaIetf(self::CRYPT_KEY_1);
        // Verify decrypted matches original data
        $this->assertEquals($data, $crypt->decrypt(base64_decode((string)$encryptedParts[2])));
    }

    /**
     * Check that decrypting works.
     *
     * @return void
     */
    public function testDecrypt(): void
    {
        $message = 'Mares eat oats and does eat oats, but little lambs eat ivy.';
        $encrypted = $this->encryptor->encrypt($message);

        $this->assertEquals($message, $this->encryptor->decrypt($encrypted));
    }

    /**
     * Using an old algo.
     *
     * @return void
     */
    public function testLegacyDecrypt(): void
    {
        // sample data to encrypt
        $data = '0:2:z3a4ACpkU35W6pV692U4ueCVQP0m0v0p:' .
            'DhEG8/uKGGq92ZusqrGb6X/9+2Ng0QZ9z2UZwljgJbs5/A3LaSnqcK0oI32yjHY49QJi+Z7q1EKu2yVqB8EMpA==';

        $actual = $this->encryptor->decrypt($data);

        // Extract the initialization vector and encrypted data
        [, , $iv, $encrypted] = explode(':', $data, 4);

        // Decrypt returned data with RIJNDAEL_256 cipher, cbc mode
        //phpcs:ignore PHPCompatibility.Constants.RemovedConstants
        $crypt = new Crypt(self::CRYPT_KEY_1, MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC, $iv);
        // Verify decrypted matches original data
        $this->assertEquals($encrypted, base64_encode($crypt->encrypt($actual)));
    }

    /**
     * Seeing that changing a key does not stand in a way of decrypting.
     *
     * @return void
     */
    public function testEncryptDecryptNewKeyAdded(): void
    {
        $deploymentConfigMock = $this->createMock(DeploymentConfig::class);
        $deploymentConfigMock
            ->method('get')
            ->withConsecutive([Encryptor::PARAM_CRYPT_KEY], [Encryptor::PARAM_CRYPT_KEY])
            ->willReturnOnConsecutiveCalls(self::CRYPT_KEY_1, self::CRYPT_KEY_1 . "\n" . self::CRYPT_KEY_2);
        $model1 = new Encryptor($this->randomGeneratorMock, $deploymentConfigMock);
        // simulate an encryption key is being added
        $model2 = new Encryptor($this->randomGeneratorMock, $deploymentConfigMock);

        // sample data to encrypt
        $data = 'Mares eat oats and does eat oats, but little lambs eat ivy.';
        // encrypt with old key
        $encryptedData = $model1->encrypt($data);
        $decryptedData = $model2->decrypt($encryptedData);

        $this->assertSame($data, $decryptedData, 'Encryptor failed to decrypt data encrypted by old keys.');
    }

    /**
     * Checking that encryptor relies on key validator.
     *
     * @return void
     */
    public function testValidateKey(): void
    {
        $this->keyValidatorMock->method('isValid')->willReturn(true);
        $this->encryptor->validateKey(self::CRYPT_KEY_1);
    }

    /**
     * Checking that encryptor relies on key validator.
     *
     * @return void
     */
    public function testValidateKeyInvalid(): void
    {
        $this->expectException('Exception');
        $this->keyValidatorMock->method('isValid')->willReturn(false);
        $this->encryptor->validateKey('-----    ');
    }

    /**
     * Algorithms and expressions to validate them.
     *
     * @return array
     */
    public function useSpecifiedHashingAlgoDataProvider(): array
    {
        return [
            [
                'password',
                'salt',
                Encryptor::HASH_VERSION_MD5,
                '/^[a-z0-9]{32}\:salt\:0$/'
            ],
            [
                'password',
                'salt',
                Encryptor::HASH_VERSION_SHA256,
                '/^[a-z0-9]{64}\:salt\:1$/'
            ],
            [
                'password',
                false,
                Encryptor::HASH_VERSION_MD5,
                '/^[0-9a-z]{32}$/'
            ],
            [
                'password',
                false,
                Encryptor::HASH_VERSION_SHA256,
                '/^[0-9a-z]{64}$/'
            ],
            [
                'password',
                true,
                Encryptor::HASH_VERSION_ARGON2ID13_AGNOSTIC,
                '/^.+\:.+\:' .Encryptor::HASH_VERSION_ARGON2ID13_AGNOSTIC .'\_\d+\_\d+\_\d+$/is'
            ]
        ];
    }

    /**
     * Check that specified algorithm is in fact being used.
     *
     * @dataProvider useSpecifiedHashingAlgoDataProvider
     *
     * @param string $password
     * @param string|bool $salt
     * @param int $hashAlgo
     * @param string $pattern
     *
     * @return void
     */
    public function testGetHashMustUseSpecifiedHashingAlgo($password, $salt, $hashAlgo, $pattern): void
    {
        $this->randomGeneratorMock->method('getRandomString')
            ->willReturnCallback(
                function (int $length = 32): string {
                    return random_bytes($length);
                }
            );
        $hash = $this->encryptor->getHash($password, $salt, $hashAlgo);
        $this->assertMatchesRegularExpression($pattern, $hash);
    }

    /**
     * Test hashing working as promised.
     *
     * @return void
     */
    public function testHash(): void
    {
        //Checking that the same hash is returned for the same value.
        $hash1 = $this->encryptor->hash($value = 'some value');
        $hash2 = $this->encryptor->hash($value);
        $this->assertEquals($hash1, $hash2);

        //Checking that hash works with hash validation.
        $this->assertTrue($this->encryptor->isValidHash($value, $hash1));

        //Checking that key matters.
        $this->keyValidatorMock->method('isValid')->willReturn(true);
        $this->encryptor->setNewKey(self::CRYPT_KEY_2);
        $hash3 = $this->encryptor->hash($value);
        $this->assertNotEquals($hash3, $hash1);
        //Validation still works
        $this->assertTrue($this->encryptor->validateHash($value, $hash3));
    }

    /**
     * Test that generated hashes can be later validated.
     *
     * @return void
     * @throws Throwable
     */
    public function testValidation(): void
    {
        $original = 'password';
        $this->randomGeneratorMock->method('getRandomString')
            ->willReturnCallback(
                function (int $length = 32): string {
                    return bin2hex(random_bytes($length));
                }
            );
        for ($version = $this->encryptor->getLatestHashVersion(); $version >= 0; $version--) {
            $hash = $this->encryptor->getHash($original, true, $version);
            $this->assertTrue(
                $this->encryptor->isValidHash($original, $hash),
                'Algo #' .$version .' hash is invalid'
            );
        }
    }

    /**
     * Test that upgraded generated hashes can be later validated.
     *
     * @return void
     * @throws Throwable
     */
    public function testUpgradedValidation(): void
    {
        $original = 'password';
        $hash = $original;
        $this->randomGeneratorMock->method('getRandomString')
            ->willReturnCallback(
                function (int $length = 32): string {
                    return bin2hex(random_bytes($length));
                }
            );
        //The hash will become sort of downgraded but that's important for the latest Argon algo.
        for ($version = $this->encryptor->getLatestHashVersion(); $version >= 0; $version--) {
            $info = explode(Encryptor::DELIMITER, $hash, 3);
            if (count($info) !== 3) {
                $salt = true;
                $hashStr = $hash;
                $versionInfo = '';
            } else {
                $salt = $info[1];
                $hashStr = $info[0];
                $versionInfo = $info[2] .':';
            }
            $hash = $this->encryptor->getHash($hashStr, $salt, $version);
            [$hashStr, $salt, $newVersion] = explode(Encryptor::DELIMITER, $hash, 3);
            $hash = implode(Encryptor::DELIMITER, [$hashStr, $salt, $versionInfo .$newVersion]);
        }

        $this->assertTrue($this->encryptor->isValidHash($original, $hash));
    }
}
