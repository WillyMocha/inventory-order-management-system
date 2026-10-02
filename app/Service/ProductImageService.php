<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Exception\ValidationException;
use RuntimeException;
use finfo;

/**
 * Upload image product (PRD-01, research R-006).
 *
 * Tiga pertahanan yang bekerja bersama:
 *   1. Tipe ditentukan dari ISI file lewat finfo - bukan dari nama file atau
 *      Content-Type kiriman client, keduanya sepenuhnya dikendalikan penyerang.
 *   2. Nama simpan diacak, sehingga file tidak dapat ditebak.
 *   3. File disimpan DI LUAR document root dan disajikan lewat controller.
 *      Nama acak saja tidak mencegah eksekusi - lokasi penyimpanannya yang
 *      mencegah.
 *
 * validate(), generateStoredName(), dan pathFor() sengaja bebas efek samping
 * agar aturannya dapat di-unit-test tanpa menyentuh filesystem.
 */
final class ProductImageService
{
    /** @var array<string, string> mime terdeteksi => ekstensi */
    private const array EXTENSION_FOR_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** @param list<string> $allowedMimes */
    public function __construct(
        private readonly string $uploadPath,
        private readonly int $maxBytes,
        private readonly array $allowedMimes,
    ) {
    }

    /**
     * Memvalidasi tipe hasil deteksi dan ukuran file.
     *
     * @throws ValidationException
     */
    public function validate(string $detectedMime, int $sizeBytes): void
    {
        if (!in_array($detectedMime, $this->allowedMimes, true)) {
            throw new ValidationException([
                'image' => 'The image must be a JPEG, PNG, or WebP file.',
            ]);
        }

        if ($sizeBytes <= 0) {
            throw new ValidationException(['image' => 'The image file is empty.']);
        }

        if ($sizeBytes > $this->maxBytes) {
            throw new ValidationException([
                'image' => sprintf('The image must not exceed %d MB.', intdiv($this->maxBytes, 1024 * 1024)),
            ]);
        }
    }

    /**
     * Nama simpan acak. Ekstensi diturunkan dari tipe HASIL DETEKSI, tidak
     * pernah dari nama file kiriman client.
     *
     * @throws ValidationException
     */
    public function generateStoredName(string $detectedMime): string
    {
        $extension = self::EXTENSION_FOR_MIME[$detectedMime] ?? null;

        if ($extension === null) {
            throw new ValidationException([
                'image' => 'The image must be a JPEG, PNG, or WebP file.',
            ]);
        }

        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    /**
     * Path absolut sebuah file tersimpan.
     *
     * Menolak apa pun yang bukan nama file hasil generateStoredName(), agar
     * tidak ada jalur keluar dari direktori upload (security standard §2).
     *
     * @throws ValidationException
     */
    public function pathFor(string $storedName): string
    {
        if (preg_match('/^[0-9a-f]{32}\.(jpg|png|webp)$/', $storedName) !== 1) {
            throw new ValidationException(['image' => 'Invalid image reference.']);
        }

        return $this->uploadPath . '/' . $storedName;
    }

    /**
     * Memvalidasi lalu menyimpan file yang diunggah; mengembalikan nama
     * simpannya.
     *
     * @param array<string, mixed> $uploadedFile satu entry dari $_FILES
     *
     * @throws ValidationException
     * @throws RuntimeException bila direktori upload tidak dapat ditulis
     */
    public function store(array $uploadedFile): string
    {
        $error = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException(['image' => 'The image is too large.']);
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['image' => 'The image could not be uploaded.']);
        }

        $tmpPath = (string) ($uploadedFile['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new ValidationException(['image' => 'The image could not be uploaded.']);
        }

        $detectedMime = $this->detectMime($tmpPath);
        $this->validate($detectedMime, (int) ($uploadedFile['size'] ?? 0));

        // Pemeriksaan kedua: isinya harus benar-benar dapat dibaca sebagai
        // image. finfo saja masih bisa dikelabui oleh magic bytes yang
        // dipalsukan pada file yang selebihnya bukan image.
        if (getimagesize($tmpPath) === false) {
            throw new ValidationException(['image' => 'The file is not a readable image.']);
        }

        $storedName = $this->generateStoredName($detectedMime);
        $this->ensureUploadDirectory();

        if (!move_uploaded_file($tmpPath, $this->pathFor($storedName))) {
            throw new RuntimeException('Failed to store the uploaded image.');
        }

        return $storedName;
    }

    /**
     * Membaca file tersimpan untuk disajikan controller.
     *
     * @return array{contents: string, mime: string}
     *
     * @throws ValidationException bila nama tidak valid
     * @throws RuntimeException bila file tidak terbaca
     */
    public function read(string $storedName): array
    {
        $path = $this->pathFor($storedName);
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException('Image not readable.');
        }

        return ['contents' => $contents, 'mime' => $this->detectMime($path)];
    }

    public function delete(?string $storedName): void
    {
        if ($storedName === null || $storedName === '') {
            return;
        }

        $path = $this->pathFor($storedName);

        if (is_file($path)) {
            unlink($path);
        }
    }

    private function detectMime(string $path): string
    {
        $info = new finfo(FILEINFO_MIME_TYPE);
        $mime = $info->file($path);

        return $mime === false ? 'application/octet-stream' : $mime;
    }

    private function ensureUploadDirectory(): void
    {
        if (is_dir($this->uploadPath)) {
            return;
        }

        if (!mkdir($this->uploadPath, 0o755, true) && !is_dir($this->uploadPath)) {
            throw new RuntimeException('Upload directory does not exist and could not be created.');
        }
    }
}
