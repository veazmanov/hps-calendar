<?php
require __DIR__ . '/lib.php';

page_start('About');
?>
    <header>
        <?php page_header(); ?>
        <h1 class="page-title">About</h1>
    </header>

    <div class="notice">
        <p>Extracurricular talks related to History and Philosophy of Science in Utrecht.</p>
        <p>This is an unofficial overview compiled by a student. Always check the details with the organisers.</p>
        <?php compiled_note(); ?>
        <p class="nav">
            <a href="subscribe.php">Subscribe in your calendar</a> ·
            <a href="contributing.php">Submit your talk</a> ·
            <a href="correction.php">Submit a correction</a>
        </p>
        <p>Spotted an issue? E-mail <a href="mailto:victor@vjbe.net">victor@vjbe.net</a>.</p>
        <p>The source code is <a href="https://github.com/veazmanov/hps-calendar" target="_blank" rel="noopener">on GitHub</a>.</p>
    </div>
<?php page_end(); ?>
