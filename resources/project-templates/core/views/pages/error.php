<h1><?= h((string) ($headline ?? "Application error")) ?></h1>
<p><?= h((string) ($message ?? "An unexpected error occurred.")) ?></p>
<?php if (!empty($requestReference)): ?><p>Reference: <?= h((string) $requestReference) ?></p><?php endif; ?>
<p><a href="<?= h(url()) ?>">Home</a></p>
