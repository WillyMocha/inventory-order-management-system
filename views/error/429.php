<?php

declare(strict_types=1);

/**
 * 429 — rate limit terlampaui (security standard §7).
 * Pesannya seragam dan tidak menyebutkan apakah email-nya terdaftar.
 */
?>
<p class="error-code">429</p>
<h1 class="page-title">Too many attempts</h1>
<p class="page-subtitle">Please wait a few minutes before trying again.</p>
<div class="form-actions">
    <a class="btn" href="/login">Back to sign in</a>
</div>
