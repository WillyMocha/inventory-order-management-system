<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\ProductImageService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test validasi image product (PRD-01, research R-006).
 *
 * Validasi dan penamaan sengaja dipisahkan dari operasi tulis file, sehingga
 * aturannya dapat diuji tanpa menyentuh filesystem (constitution Principle III,
 * FIRST). Pemindahan file yang sesungguhnya ada di store(), yang tidak
 * dipanggil unit test.
 */
final class ProductImageServiceTest extends TestCase
{
    private const int MAX_BYTES = 2 * 1024 * 1024;
    private const array ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    private ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductImageService('/var/www/html/storage/uploads', self::MAX_BYTES, self::ALLOWED);
    }

    // ------------------------------------------------------- tipe file

    #[Test]
    public function acceptsTheThreeAllowedImageTypes(): void
    {
        $accepted = 0;

        foreach (self::ALLOWED as $mime) {
            $this->service->validate($mime, 1024);
            $accepted++;
        }

        self::assertSame(count(self::ALLOWED), $accepted, 'Ketiga tipe yang diizinkan harus lolos');
    }

    /**
     * Tipe ditentukan dari ISI file, bukan dari nama atau header client -
     * itulah sebabnya validate() menerima mime hasil deteksi, bukan nama file.
     */
    #[Test]
    public function rejectsADisallowedTypeEvenWhenItLooksLikeAnImage(): void
    {
        try {
            // File PHP yang di-rename menjadi .jpg: isinya tetap terdeteksi
            // sebagai text/x-php, jadi ditolak.
            $this->service->validate('text/x-php', 1024);
            self::fail('Tipe yang tidak diizinkan seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('image', $e->errors());
        }
    }

    #[Test]
    public function rejectsSvgWhichCanCarryScript(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->validate('image/svg+xml', 1024);
    }

    #[Test]
    public function rejectsAnExecutable(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->validate('application/x-dosexec', 1024);
    }

    // ----------------------------------------------------------- ukuran

    #[Test]
    public function rejectsAnOversizedFile(): void
    {
        try {
            $this->service->validate('image/png', self::MAX_BYTES + 1);
            self::fail('File melebihi batas seharusnya ditolak');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('image', $e->errors());
        }
    }

    /** Batas bersifat inklusif: tepat pada MAX_BYTES masih diterima. */
    #[Test]
    public function acceptsAFileExactlyAtTheLimit(): void
    {
        $this->expectNotToPerformAssertions();

        $this->service->validate('image/png', self::MAX_BYTES);
    }

    #[Test]
    public function rejectsAnEmptyFile(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->validate('image/png', 0);
    }

    // ------------------------------------------------- nama tersimpan

    /**
     * Nama file harus acak dan tidak dapat ditebak (PRD-01). Nama asli dari
     * client tidak pernah dipakai - itu jalur path traversal sekaligus
     * membuat file dapat ditebak.
     */
    #[Test]
    public function generatesAnUnguessableStoredName(): void
    {
        $first = $this->service->generateStoredName('image/png');
        $second = $this->service->generateStoredName('image/png');

        self::assertNotSame($first, $second, 'Dua unggahan tidak boleh menghasilkan nama sama');
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $first);
    }

    #[Test]
    public function derivesTheExtensionFromTheDetectedTypeNotTheClientName(): void
    {
        self::assertStringEndsWith('.jpg', $this->service->generateStoredName('image/jpeg'));
        self::assertStringEndsWith('.png', $this->service->generateStoredName('image/png'));
        self::assertStringEndsWith('.webp', $this->service->generateStoredName('image/webp'));
    }

    #[Test]
    public function refusesToGenerateANameForADisallowedType(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->generateStoredName('text/x-php');
    }

    // ------------------------------------------------------------ path

    /**
     * Nama file yang mengandung traversal tidak boleh pernah keluar dari
     * direktori upload (security standard §2).
     */
    #[Test]
    public function refusesAStoredNameThatTriesToEscapeTheUploadDirectory(): void
    {
        foreach (['../../etc/passwd', '..\\..\\windows\\system32', 'sub/dir/file.png'] as $malicious) {
            $rejected = false;

            try {
                $this->service->pathFor($malicious);
            } catch (ValidationException) {
                $rejected = true;
            }

            self::assertTrue($rejected, $malicious . ' seharusnya ditolak');
        }
    }

    #[Test]
    public function buildsThePathForAWellFormedStoredName(): void
    {
        $name = $this->service->generateStoredName('image/png');

        self::assertSame('/var/www/html/storage/uploads/' . $name, $this->service->pathFor($name));
    }
}
