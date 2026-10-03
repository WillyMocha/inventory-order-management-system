<?php

declare(strict_types=1);

/**
 * 404 — halaman atau record tidak ditemukan, ATAU berada di luar scope
 * pemanggil. Kedua kasus sengaja tidak dibedakan agar keberadaan record
 * tidak bocor (security standard §2).
 */
?>
<p class="error-code">404</p>
<h1 class="page-title">Page not found</h1>
<p class="page-subtitle">The page or record you are looking for does not exist.</p>
<div class="form-actions">
    <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
</div>
