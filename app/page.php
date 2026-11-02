<?php
declare(strict_types=1);
/** Minimal standalone page shell for the legal pages Meta requires. Light by default, follows the user's saved theme. */
function legal_page(string $title, string $html): void {
    $app = e((string)cfg('APP_NAME')); $base = base_url();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . e($title) . ' · ' . $app . '</title><link rel="icon" href="' . $base . '/assets/img/favicon.png">'
       . '<link rel="stylesheet" href="' . $base . '/assets/css/legal.css">'
       . '<script>try{var t=localStorage.getItem("recap.theme");if(t==="dark")document.documentElement.dataset.theme="dark"}catch(e){}</script></head><body>'
       . '<header><a href="' . $base . '/"><img src="' . $base . '/assets/img/logo.png" alt="' . $app . '"></a>'
       . '<nav><a href="' . $base . '/privacy.php">Privacy</a><a href="' . $base . '/terms.php">Terms</a><a href="' . $base . '/delete-data.php">Delete my data</a></nav></header>'
       . '<main>' . $html . '</main><footer>' . $app . ' is an independent app and is not affiliated with, endorsed by or sponsored by Meta Platforms, Inc. '
       . 'Facebook, Instagram and Threads are trademarks of Meta Platforms, Inc.</footer></body></html>';
}
