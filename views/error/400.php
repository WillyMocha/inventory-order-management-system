<?php

declare(strict_types=1);

/**
 * 400 — request tidak dapat diproses.
 * Dipakai ketika kegagalan validasi atau aturan bisnis lolos sampai ke front
 * controller tanpa ditangani controller.
 */
?>
<p class="error-code">400</p>
<h1 class="page-title">Request could not be processed</h1>
<p class="page-subtitle">Please check the form and try again.</p>
<div class="form-actions">
    <a class="btn btn--primary" href="/dashboard">Back to dashboard</a>
</div>
