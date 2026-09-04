<?php
/**
 * flatbb — a flat, lightweight, AI-friendly forum. https://www.flatbb.com
 * Single web entry point. Everything else lives in core/ and app/.
 */
require __DIR__ . '/core/boot.php';
app_boot();
dispatch();
