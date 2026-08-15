<?php
declare(strict_types=1);

return [
    // Použite absolútnu cestu mimo verejného web rootu.
    // V produkcii má prednosť environment premenná DIAGNOSTICS_STORAGE_ROOT.
    'storage_root' => '/srv/doktorhaus-private/diagnostics',

    // Povinné tajomstvá nemajú default ani ukážkovú hodnotu. Nastavte ich cez
    // DIAGNOSTICS_PIN_PEPPER a DIAGNOSTICS_AUDIT_HMAC_KEY (každé min. 32 bytes),
    // alebo ich doplňte iba do ignorovaného api/diagnostics.config.php.

    // Nasledujúce necitlivé hodnoty sú voliteľné; uvedené sú bezpečné defaulty.
    'session_idle_seconds' => 3600,
    'session_absolute_seconds' => 43200,
    'rate_window_seconds' => 900,
    'rate_access_ip_max' => 6,
    'rate_ip_max' => 30,
    'rate_lockout_seconds' => 900,

    // Mitti API shadow ingest. Secrets nechajte prázdne v example súbore a v produkcii
    // ich nastavte cez MITTI_API_TOKEN a OPENAI_API_KEY alebo iba v ignorovanom configu.
    'mitti_api_token' => '',
    'mitti_template_id' => '',
    'mitti_ingest_mode' => 'shadow', // off | shadow | active; active zatiaľ tiež končí na human QA
    'openai_api_key' => '',
    'diagnostics_llm_model' => 'gpt-5.6-terra',
    'diagnostics_llm_vision' => false,
];
