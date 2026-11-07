<?php

/*
 * Forms (docs/superpowers/specs/2026-11-07-forms-design.md): offices build
 * their own forms, filled by signed-in officials.
 */
return [
    // Off hides the Forms page, the projects' Forms tabs and every route.
    'enabled' => (bool) env('FORMS_ENABLED', true),

    // The largest file a question or a content block takes, in MB.
    'max_upload_mb' => (int) env('FORMS_MAX_UPLOAD_MB', 20),

    // The most an export or a ZIP of files takes at once.
    'max_export_rows' => 5000,
    'max_zip_mb' => 500,

    // How often a respondent may be reminded about one form.
    'remind_every_hours' => 24,
];
