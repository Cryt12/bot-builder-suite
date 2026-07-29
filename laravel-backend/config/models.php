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

        /*
        | Provider to retry on when the primary cannot answer. Set to '' to
        | disable failover and let the error surface.
        |
        | Only transport faults, timeouts, 429 and 5xx trigger it: a 401 means the
        | key is wrong and a 400 means we sent something invalid, and quietly
        | switching providers on those would hide a broken setup indefinitely.
        | While streaming, failover only happens before the first token reaches
        | the visitor -- past that point a retry would replay a half-written reply.
        */
        'fallback_provider' => env('LLM_FALLBACK_PROVIDER', ''),

        /*
        | Stream replies to the widget token-by-token. Total generation time is
        | unchanged, but the visitor sees the first words in well under a second
        | instead of staring at a spinner until the whole answer is finished.
        */
        'stream' => (bool) env('LLM_STREAM', true),

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('OLLAMA_MODEL', 'gemma4:e4b'),

            /*
            | How long Ollama keeps the model resident after a request. Without
            | this it unloads after ~5 minutes idle and the next visitor pays a
            | multi-second cold reload before the first token appears.
            */
            'keep_alive' => env('OLLAMA_KEEP_ALIVE', '30m'),
        ],

        'openrouter' => [
            'api_key' => env('OPENROUTER_API_KEY'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
            'model' => env('OPENROUTER_MODEL', 'google/gemma-4:mini'),
        ],

        /*
        | Models an admin can route between from the dashboard.
        |
        | Keys are stored in app_settings, so renaming one orphans a saved choice
        | -- add and remove entries rather than renaming. Several entries may
        | share a provider: a provider is how a model is reached, not the choice
        | itself, which is why routing is stored per model.
        */
        'catalog' => [
            'deepseek-v4-flash' => [
                'provider' => 'openrouter',
                'model' => 'deepseek/deepseek-v4-flash',
                'label' => 'DeepSeek V4 Flash',
                'note' => 'Hosted · reasoning model, 1M context',
            ],
            'gpt-oss-120b' => [
                'provider' => 'openrouter',
                'model' => 'openai/gpt-oss-120b',
                'label' => 'GPT-OSS 120B',
                'note' => 'Hosted · open-weight MoE, 131K context',
            ],
            'gemma4-local' => [
                'provider' => 'ollama',
                'model' => 'gemma4:e4b',
                'label' => 'Gemma 4',
                'note' => 'Runs locally on this server · no per-token cost',
            ],
        ],
    ],

    'embeddings' => [
        'timeout' => env('EMBEDDING_TIMEOUT', 30),
        'max_input_chars' => env('EMBEDDING_MAX_INPUT_CHARS', 4000),
        'min_similarity' => env('EMBEDDING_MIN_SIMILARITY', 0.35),

        /*
        |----------------------------------------------------------------------
        | Task prefixes
        |----------------------------------------------------------------------
        | nomic-embed-text is documented as being trained asymmetrically, with
        | "search_document: " on corpus passages and "search_query: " on queries.
        | Measured on this corpus, though, they made retrieval WORSE -- against
        | 25 BM25-labelled question/chunk pairs and 150 distractors:
        |
        |     no prefix            MRR 0.424   top1 0.40
        |     search_* prefixes    MRR 0.332   top1 0.28
        |
        | so they default to off. Ollama's nomic-embed-text template is a bare
        | "{{ .Prompt }}" passthrough, so this is not double-prefixing; the
        | prefixes simply do not pay off here. Re-measure before switching them
        | on, and re-measure again if the embedding model ever changes.
        |
        | Changing either value invalidates every stored vector -- run
        | `php artisan knowledge:embed --all` afterwards to rebuild them.
        | Note: quote the value in .env if it needs a trailing space.
        */
        'document_prefix' => env('EMBEDDING_DOCUMENT_PREFIX', ''),
        'query_prefix' => env('EMBEDDING_QUERY_PREFIX', ''),

        /*
        | Cache query vectors briefly. Support bots see the same question over
        | and over, and a repeat hit skips a full round trip to Ollama.
        */
        'query_cache_minutes' => env('EMBEDDING_QUERY_CACHE_MINUTES', 60),

        /** Chunks embedded per pass inside the ingestion job. */
        'batch_size' => env('EMBEDDING_BATCH_SIZE', 25),

        'ollama' => [
            'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
            'model' => env('EMBEDDING_MODEL', 'nomic-embed-text'),
            'keep_alive' => env('EMBEDDING_KEEP_ALIVE', '30m'),
        ],
    ],
];
