<?php

declare(strict_types=1);

/**
 * 500 — kegagalan tak terduga.
 *
 * Detail exception HANYA masuk ke server log. Tidak ada stack trace, nama
 * file, atau pesan database yang boleh muncul di sini (ERR-01).
 */
?>
<p class="error-code">500</p>
<h1 class="page-title">Something went wrong</h1>
<p class="page-subtitle">The problem has been logged. Please try again in a moment.</p>
<div class="form-actions">
    <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
</div>
