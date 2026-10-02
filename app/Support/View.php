<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Renderer template PHP polos.
 *
 * Tidak memakai template engine: sebuah dependency plus direktori cache untuk
 * masalah yang tidak dinyatakan requirement mana pun (research R-004, C-003).
 *
 * Seluruh output WAJIB melewati e(). Template tidak pernah meng-echo variabel
 * mentah (§4.2).
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(private readonly string $viewPath)
    {
    }

    /**
     * `mixed` dibenarkan: variabel yang dibagikan ke template bisa berupa
     * entity, list, atau skalar. Escaping-nya dijamin View::e() pada saat
     * dirender, bukan oleh tipe di sini.
     */
    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    /**
     * Merender template ke dalam layout.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], string $layout = 'layout/app'): string
    {
        $content = $this->renderPartial($template, $data);

        return $this->renderPartial($layout, array_merge($data, ['content' => $content]));
    }

    /**
     * Merender satu template tanpa layout — dipakai untuk partial.
     *
     * @param array<string, mixed> $data
     */
    public function renderPartial(string $template, array $data = []): string
    {
        $file = $this->viewPath . '/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('View tidak ditemukan: ' . $template);
        }

        $scope = array_merge($this->shared, $data);

        ob_start();

        try {
            (static function (string $__file, array $__scope): void {
                extract($__scope, EXTR_SKIP);
                require $__file;
            })($file, $scope);
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }

    /**
     * Satu-satunya jalur output yang disahkan. Meng-escape untuk konteks HTML.
     */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
