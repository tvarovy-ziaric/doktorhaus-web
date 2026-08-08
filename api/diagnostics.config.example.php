<?php
declare(strict_types=1);

return [
    // Použite absolútnu cestu mimo verejného web rootu.
    // V produkcii má prednosť environment premenná DIAGNOSTICS_STORAGE_ROOT.
    'storage_root' => '/srv/doktorhaus-private/diagnostics',
];
