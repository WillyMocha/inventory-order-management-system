<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\Role;
use App\Entity\User;
use App\Repository\UserRepositoryInterface;
use App\Support\Exception\DomainException;
use App\Support\Exception\NotFoundException;
use App\Support\Validator;

/**
 * Manajemen user (USR-01).
 *
 * Admin menambah, mengubah, dan mengaktifkan/menonaktifkan akun Sales dan
 * Warehouse Staff. Tidak ada public registration - seluruh akun dibuat di sini.
 *
 * Acting user di-pass sebagai argument pada operasi yang memang membutuhkannya,
 * bukan dibaca dari session (constitution Principle I) - itulah yang membuat
 * aturan "Admin tidak boleh menonaktifkan dirinya sendiri" dapat di-unit-test.
 */
final class UserService
{
    /** Panjang minimum password. Cukup untuk demo, tidak melemahkan hashing. */
    private const int MIN_PASSWORD_LENGTH = 8;

    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \App\Support\Exception\ValidationException
     */
    public function create(array $data): int
    {
        $this->validate($data, null, true);

        $user = new User(
            null,
            trim((string) $data['name']),
            trim((string) $data['email']),
            password_hash((string) $data['password'], PASSWORD_DEFAULT),
            Role::from((string) $data['role']),
            true,
        );

        return $this->users->save($user);
    }

    /**
     * Mengubah nama, email, dan role. Password TIDAK diubah di sini -
     * perubahan password melewati changePassword(), yang di controller-nya
     * menuntut step-up re-auth (security standard §7).
     *
     * @param array<string, mixed> $data
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function update(int $id, array $data): void
    {
        $existing = $this->requireUser($id);

        $this->validate($data, $id, false);

        $this->users->save(new User(
            $existing->id,
            trim((string) $data['name']),
            trim((string) $data['email']),
            $existing->passwordHash,
            Role::from((string) $data['role']),
            $existing->isActive,
        ));
    }

    /**
     * Membalik status aktif.
     *
     * @throws NotFoundException
     * @throws DomainException bila Admin mencoba menonaktifkan dirinya sendiri
     */
    public function toggleActive(User $actor, int $id): void
    {
        $target = $this->requireUser($id);

        // Menonaktifkan diri sendiri akan mengunci Admin keluar dari
        // sistemnya sendiri, dan tidak ada jalan kembali lewat UI.
        if ($actor->id !== null && $actor->id === $id && $target->isActive) {
            throw new DomainException('You cannot deactivate your own account.');
        }

        $this->users->setActive($id, !$target->isActive);
    }

    /**
     * Menyetel password baru. Pemeriksaan step-up re-auth dilakukan controller
     * sebelum memanggil method ini.
     *
     * @throws NotFoundException
     * @throws \App\Support\Exception\ValidationException
     */
    public function changePassword(int $id, string $newPassword): void
    {
        $this->requireUser($id);

        Validator::make(['password' => $newPassword])
            ->required('password', 'Password')
            ->minLength('password', 'Password', self::MIN_PASSWORD_LENGTH)
            ->validate();

        $this->users->updatePasswordHash($id, password_hash($newPassword, PASSWORD_DEFAULT));
    }

    /**
     * @param array{search?: string, role?: string, active?: bool} $criteria
     * @return list<User>
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        return $this->users->search($criteria, $limit, $offset);
    }

    /** @param array{search?: string, role?: string, active?: bool} $criteria */
    public function count(array $criteria): int
    {
        return $this->users->countBy($criteria);
    }

    /** @throws NotFoundException */
    public function requireUser(int $id): User
    {
        $user = $this->users->findById($id);

        if ($user === null) {
            throw new NotFoundException();
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     * @param int|null $exceptId id yang dikecualikan dari pemeriksaan email unik
     */
    private function validate(array $data, ?int $exceptId, bool $requirePassword): void
    {
        $email = trim((string) ($data['email'] ?? ''));

        $validator = Validator::make($data)
            ->required('name', 'Name')
            ->maxLength('name', 'Name', 150)
            ->required('email', 'Email')
            ->email('email', 'Email')
            ->maxLength('email', 'Email', 190)
            ->required('role', 'Role')
            ->enum('role', 'Role', Role::class);

        if ($requirePassword) {
            $validator
                ->required('password', 'Password')
                ->minLength('password', 'Password', self::MIN_PASSWORD_LENGTH);
        }

        // Email unik (USR-01). Dicek hanya bila formatnya sudah valid, agar
        // pesan yang muncul adalah yang paling berguna.
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $validator->rule(
                'email',
                !$this->users->emailExists($email, $exceptId),
                'That email address is already in use.',
            );
        }

        $validator->validate();
    }
}
