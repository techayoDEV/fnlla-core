<!doctype html>
<html lang="<?= h((string) config("app.locale", "en")) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="turbo-cache-control" content="no-cache">
  <meta name="turbo-prefetch" content="false">
  <link rel="stylesheet" href="<?= h(asset('assets/fnlla/navigation.css')) ?>" data-turbo-track="reload">
  <meta name="csp-nonce" content="<?= h(csp_nonce()) ?>">
  <script nonce="<?= h(csp_nonce()) ?>" src="<?= h(asset('assets/fnlla/navigation.js')) ?>" data-turbo-track="reload"></script>
  <title><?= h((string) ($pageTitle ?? config("app.name"))) ?></title>
  <link rel="stylesheet" href="<?= h(asset("assets/app.css")) ?>">
</head>
<body><a class="fnlla-navigation-skip" data-fnlla-skip href="#main-content">Skip to content</a><main id="main-content" tabindex="-1"><?= $content ?></main></body>
</html>
