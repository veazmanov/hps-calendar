<?php require __DIR__ . '/lib.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About · HPS Talks Utrecht</title>
    <?php head_assets(); ?>
</head>
<body>
<main>
    <header>
        <?php page_header(); ?>
        <h1 class="page-title">About</h1>
    </header>

    <div class="notice">
        <p>Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
        <p>This is an unofficial overview compiled by a student. Always check the details with the organisers.</p>
        <?php if ($updated = trim((string) @file_get_contents(__DIR__ . '/last-deploy.txt'))): ?>
            <?php $u = DateTimeImmutable::createFromFormat('Y-m-d H:i', $updated); ?>
            <p>Last updated <?= e($u ? $u->format('j F Y \a\t H:i') : $updated) ?>.</p>
        <?php endif; ?>
        <p>Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>.</p>
        <p>The source code is <a href="https://github.com/veazmanov/hps-calendar" target="_blank" rel="noopener">on GitHub</a>.</p>
    </div>
</main>
</body>
</html>
