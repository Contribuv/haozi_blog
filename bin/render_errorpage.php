<?php
require_once __DIR__ . '/../core/bootstrap.php';
\Blog\Service\ErrorPage::render((int) ($argv[1] ?? 404));