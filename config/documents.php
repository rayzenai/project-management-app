<?php

/*
 * Documents: a project's Word, Excel and PowerPoint files, edited together
 * in ONLYOFFICE Docs (onlyoffice/, docs/onlyoffice.md). The editor keeps no
 * files: they are on the `documents` disk (RustFS in production), one object
 * per saved version.
 */

$serverUrl = rtrim((string) env('ONLYOFFICE_URL', ''), '/');
$secret = (string) env('ONLYOFFICE_JWT_SECRET', '');

return [

    // On only when the editor is configured: its address and the secret it
    // shares with the tracker (onlyoffice/.env.example's JWT_SECRET).
    'enabled' => $serverUrl !== '' && strlen($secret) >= 32,

    // https://docs.sdoc.gov.np, no trailing slash. Saved files are fetched
    // only from this host.
    'server_url' => $serverUrl,

    'jwt_secret' => $secret,

    'disk' => 'documents',

    // Uploads, and a saved file fetched back from the editor.
    'max_upload_kilobytes' => 50 * 1024,
    'max_saved_bytes' => 100 * 1024 * 1024,

    // How long the editor may use a file link after the page handed it out.
    'file_link_minutes' => 60,

];
