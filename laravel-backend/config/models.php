<?php

return [
    /*
    |--------------------------------------------------------------------------
    | System-wide LLM Defaults
    |--------------------------------------------------------------------------
    | These values act as the fallback for any bot that hasn't overridden
    | its provider or model via the admin panel.  They also drive the
    | admin "System Defaults" section.
    |
    | Valid providers: "ollama", "openrouter"
    */
    'llm' => [
        'default_provider' => env('LLM_DEFAULT_PROVIDER', 'ollama'),

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('OLLAMA_MODEL', 'gemma4:e4b'),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'model' => env('OPENROUTER_MODEL', 'google/gemma-4:mini'),
        ],
    ],

    'embeddings' => [
        'timeout' => env('EMBEDDING_TIMEOUT', 30),
        'max_input_chars' => env('EMBEDDING_MAX_INPUT_CHARS', 4000),
        'min_similarity' => env('EMBEDDING_MIN_SIMILARITY', 0.35),

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('EMBEDDING_MODEL', 'nomic-embed-text'),
        ],
    ],
];
