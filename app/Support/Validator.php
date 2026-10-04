<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Exception\ValidationException;
use BackedEnum;
use DateTimeImmutable;

/**
 * Validasi sisi server — sumber kebenaran (FR-029).
 *
 * Mengumpulkan seluruh pesan per field lebih dulu, baru melempar sekali,
 * sehingga form dapat menampilkan semua kesalahan sekaligus dan tidak pernah
 * menyimpan data setengah jadi.
 *
 * Pesan ditulis dalam bahasa Inggris karena tampil di UI (spec C-007).
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @param array<string, mixed> $data */
    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $label): self
    {
        $value = $this->raw($field);

        if ($value === null || trim((string) $value) === '') {
            $this->fail($field, $label . ' is required.');
        }

        return $this;
    }

    public function email(string $field, string $label): self
    {
        $value = trim((string) ($this->raw($field) ?? ''));

        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->fail($field, $label . ' must be a valid email address.');
        }

        return $this;
    }

    public function maxLength(string $field, string $label, int $max): self
    {
        $value = (string) ($this->raw($field) ?? '');

        if (mb_strlen($value) > $max) {
            $this->fail($field, sprintf('%s must not exceed %d characters.', $label, $max));
        }

        return $this;
    }

    public function minLength(string $field, string $label, int $min): self
    {
        $value = (string) ($this->raw($field) ?? '');

        if ($value !== '' && mb_strlen($value) < $min) {
            $this->fail($field, sprintf('%s must be at least %d characters.', $label, $min));
        }

        return $this;
    }

    /**
     * Integer dengan batas bawah. Dipakai untuk quantity dan reorder point,
     * yang tidak boleh negatif (PRD-01).
     */
    public function integerMin(string $field, string $label, int $min): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (!is_numeric($value) || (string) (int) $value !== trim((string) $value)) {
            $this->fail($field, $label . ' must be a whole number.');
            return $this;
        }

        if ((int) $value < $min) {
            $this->fail($field, sprintf('%s must be %d or greater.', $label, $min));
        }

        return $this;
    }

    /**
     * Nilai uang. Diperlakukan sebagai string agar presisi tidak hilang.
     */
    public function decimalMin(string $field, string $label, float $min): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if (!is_numeric($value)) {
            $this->fail($field, $label . ' must be a number.');
            return $this;
        }

        if ((float) $value < $min) {
            $this->fail($field, sprintf('%s must be %s or greater.', $label, (string) $min));
        }

        return $this;
    }

    /**
     * Keanggotaan enum. Nilai di luar enum ditolak, tidak pernah diteruskan
     * ke query.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public function enum(string $field, string $label, string $enumClass): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '') {
            return $this;
        }

        if ($enumClass::tryFrom((string) $value) === null) {
            $this->fail($field, $label . ' is not a valid option.');
        }

        return $this;
    }

    public function date(string $field, string $label, string $format = 'Y-m-d'): self
    {
        $value = trim((string) ($this->raw($field) ?? ''));

        if ($value === '') {
            return $this;
        }

        $parsed = DateTimeImmutable::createFromFormat($format, $value);

        if ($parsed === false || $parsed->format($format) !== $value) {
            $this->fail($field, $label . ' must be a valid date.');
        }

        return $this;
    }

    /**
     * Keberadaan foreign key. Callback melakukan lookup lewat repository,
     * sehingga Validator tetap tidak mengenal database.
     *
     * @param callable(int): bool $exists
     */
    public function existsById(string $field, string $label, callable $exists): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $this;
        }

        if (!$exists((int) $value)) {
            $this->fail($field, 'The selected ' . strtolower($label) . ' does not exist.');
        }

        return $this;
    }

    /**
     * Foreign key yang harus ada DAN aktif — record nonaktif tidak boleh
     * dipilih di transaksi baru (tech-debt TD-10). Callback mengembalikan status
     * aktif record, atau null bila record tidak ada; Validator tetap tidak
     * mengenal database.
     *
     * @param callable(int): ?bool $isActive
     */
    public function activeById(string $field, string $label, callable $isActive): self
    {
        $value = $this->raw($field);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $this;
        }

        $active = $isActive((int) $value);
        $selected = 'The selected ' . strtolower($label);

        if ($active === null) {
            $this->fail($field, $selected . ' does not exist.');
        } elseif (!$active) {
            $this->fail($field, $selected . ' is inactive. Choose an active one.');
        }

        return $this;
    }

    /** Aturan bebas untuk kondisi yang tidak tertutup helper di atas. */
    public function rule(string $field, bool $passes, string $message): self
    {
        if (!$passes) {
            $this->fail($field, $message);
        }

        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Melempar bila ada kesalahan. Dipanggil sebelum penyimpanan apa pun,
     * sehingga tidak pernah ada partial save.
     *
     * @throws ValidationException
     */
    public function validate(): void
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }
    }

    /**
     * `mixed` dibenarkan: inilah titik masuk input mentah. Validator justru ada
     * untuk MENGUBAH nilai bertipe tidak menentu menjadi nilai yang terpercaya,
     * jadi menuntut tipe pasti di sini akan memutar balik tanggung jawabnya.
     */
    private function raw(string $field): mixed
    {
        return $this->data[$field] ?? null;
    }

    /** Pesan pertama per field yang dipertahankan — paling relevan bagi user. */
    private function fail(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }
}
