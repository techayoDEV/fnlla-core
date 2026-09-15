<!doctype html>
<html lang="<?= h((string) config("app.locale", "en")) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h((string) ($pageTitle ?? config("app.name"))) ?></title>
  <link rel="stylesheet" href="<?= h(asset("assets/app.css")) ?>">
</head>
<body><main><?= $content ?></main></body>
</html>
