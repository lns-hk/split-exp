<?php
// Single entry point; the real code lives outside the document root.
// deploy.sh rewrites this path; set SPLIT_EXP_LIB to override it.
require (getenv('SPLIT_EXP_LIB') ?: dirname(__DIR__, 2) . '/server/lib') . '/admin.php';
admin_run();
