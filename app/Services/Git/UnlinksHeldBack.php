<?php

namespace App\Services\Git;

use RuntimeException;

/**
 * RepositorySync held a reconcile's repository unlinks back: the bot no
 * longer owns the forge organization (so every repository looks gone), or
 * more would go than a night's deletions explain. Nothing was unlinked; a
 * person should look.
 */
class UnlinksHeldBack extends RuntimeException {}
