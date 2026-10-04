<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Service\ProductImageService;
use App\Support\Exception\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Jalur filesystem ProductImageService terhadap direktori sungguhan
 * (tech-debt TD-3).
 *
 * Tidak memakai database, tetapi menyentuh filesystem — karena itu berada di
 * suite Integration, bukan Unit. Setiap test memakai direktori sementaranya
 * sendiri dan menghapusnya kembali (FIRST: Independent, Repeatable).
 *
 * Jalur sukses store() TIDAK dapat diuji di sini: move_uploaded_file() hanya
 * menerima file yang benar-benar datang lewat HTTP POST, dan dari CLI selalu
 * menolak. Justru penolakan itulah yang diuji di bawah — file lokal yang
 * disodorkan sebagai upload tidak boleh pernah disimpan.
 */
final class ProductImageStorageTest extends TestCase
{
    /** PNG 1x1 yang sah: lolos finfo DAN getimagesize(). */
    private const string PNG_1X1 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private const int MAX_BYTES = 2 * 1024 * 1024;

    private string $directory;
    private ProductImageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/ioms-image-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0o755, true);

        $this->service = new ProductImageService(
            $this->directory,
            self::MAX_BYTES,
            ['image/jpeg', 'image/png', 'image/webp'],
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    // ------------------------------------------------------------- read

    #[Test]
    public function aStoredImageIsReadBackWithTheTypeDetectedFromItsContents(): void
    {
        $name = $this->placeStoredPng();

        $image = $this->service->read($name);

        self::assertSame($this->pngBytes(), $image['contents']);
        self::assertSame('image/png', $image['mime']);
    }

    #[Test]
    public function readingAMissingImageFailsInsteadOfReturningEmptyContents(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service->read(str_repeat('a', 32) . '.png');
    }

    #[Test]
    public function readingRefusesANameThatEscapesTheUploadDirectory(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->read('../../../etc/passwd');
    }

    // ----------------------------------------------------------- delete

    #[Test]
    public function deleteRemovesTheFileFromDisk(): void
    {
        $name = $this->placeStoredPng();

        $this->service->delete($name);

        self::assertFileDoesNotExist($this->directory . '/' . $name);
    }

    #[Test]
    public function deleteToleratesAProductWithoutAnImage(): void
    {
        $this->service->delete(null);
        $this->service->delete('');
        // File yang sudah tidak ada pun bukan kesalahan: tujuannya sudah tercapai.
        $this->service->delete(str_repeat('b', 32) . '.jpg');

        self::assertSame([], glob($this->directory . '/*'));
    }

    // ------------------------------------------------------------ store

    #[Test]
    public function aLocalFilePosingAsAnUploadIsNeverStored(): void
    {
        // Isinya PNG yang sah, tetapi file ini tidak datang lewat HTTP POST.
        // Tanpa is_uploaded_file(), file server mana pun dapat disalin ke
        // direktori upload lewat tmp_name yang dipalsukan.
        $local = $this->directory . '/local-source.png';
        file_put_contents($local, $this->pngBytes());

        try {
            $this->service->store([
                'name'     => 'photo.png',
                'tmp_name' => $local,
                'size'     => filesize($local),
                'error'    => UPLOAD_ERR_OK,
            ]);
            self::fail('File yang bukan hasil upload HTTP harus ditolak.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('image', $e->errors());
        }

        self::assertSame([$local], glob($this->directory . '/*'), 'Tidak boleh ada file baru yang tersimpan.');
    }

    #[Test]
    public function anUploadRejectedByPhpForItsSizeIsReportedAsTooLarge(): void
    {
        try {
            $this->service->store(['name' => 'big.png', 'tmp_name' => '', 'size' => 0, 'error' => UPLOAD_ERR_INI_SIZE]);
            self::fail('Upload yang ditolak PHP karena ukurannya harus ditolak.');
        } catch (ValidationException $e) {
            self::assertSame('The image is too large.', $e->errors()['image']);
        }
    }

    #[Test]
    public function aMissingUploadIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->store(['error' => UPLOAD_ERR_NO_FILE]);
    }

    // ---------------------------------------------------------- helpers

    private function placeStoredPng(): string
    {
        $name = $this->service->generateStoredName('image/png');
        file_put_contents($this->service->pathFor($name), $this->pngBytes());

        return $name;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode(self::PNG_1X1, true);
    }
}
