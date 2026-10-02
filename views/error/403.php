<?php

declare(strict_types=1);

/**
 * 403 — role pemanggil memang tidak berhak atas route ini.
 * Tidak membocorkan apa pun, sehingga 403 aman dipakai di sini.
 *
 * Resource yang ADA tetapi di luar scope pemanggil justru dipetakan ke 404,
 * bukan ke halaman ini (security standard §2).
 */
?>
<p class="error-code">403</p>
<h1 class="page-title">Access denied</h1>
<p class="page-subtitle">You do not have permission to view this page.</p>
<div class="form-actions">
    <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
</div>
