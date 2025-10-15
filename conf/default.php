<?php
/**
 * Default settings for the dokullm plugin
 * 
 * This file defines the default configuration values for the LLM integration plugin.
 * These values can be overridden by the user in the plugin configuration.
 */

/**
 * The API endpoint URL for the LLM service
 * 
 * This should be the full URL to the chat completions endpoint of your LLM provider.
 * The default is set to OpenAI's GPT API endpoint.
 * 
 * @var string
 */
$conf['api_url'] = 'https://api.openai.com/v1/chat/completions';

/**
 * The API authentication key
 * 
 * This is the secret key used to authenticate with the LLM service.
 * For security, this should be left empty in the default config and set by the user.
 * 
 * @var string
 */
$conf['api_key'] = '';

/**
 * The model identifier to use for text processing
 * 
 * Specifies which LLM model to use for processing requests.
 * The default is gpt-3.5-turbo, but can be changed to other models like gpt-4.
 * 
 * @var string
 */
$conf['model'] = 'gpt-3.5-turbo';

/**
 * The request timeout in seconds
 * 
 * Maximum time to wait for a response from the LLM API before timing out.
 * Set to 30 seconds by default, which should be sufficient for most requests.
 * 
 * @var int
 */
$conf['timeout'] = 30;

/**
 * The language for prompts
 * 
 * Specifies which language to use for the prompts.
 * 'default' uses English prompts, 'ro' uses Romanian prompts.
 * 
 * @var string
 */
$conf['language'] = 'default';

/**
 * The temperature setting for the LLM
 * 
 * Controls the randomness of the LLM output. Lower values (0.0-0.5) make the output
 * more deterministic and focused, while higher values (0.5-1.0) make it more random
 * and creative. Default is 0.3 for consistent, high-quality responses.
 * 
 * @var float
 */
$conf['temperature'] = 0.3;

/**
 * The top-p (nucleus sampling) setting for the LLM
 * 
 * Controls the cumulative probability of token selection. Lower values (0.1-0.5) make
 * the output more focused, while higher values (0.5-1.0) allow for more diverse outputs.
 * Default is 0.8 for a good balance between creativity and coherence.
 * 
 * @var float
 */
$conf['top_p'] = 0.8;

/**
 * The top-k setting for the LLM
 * 
 * Limits the number of highest probability tokens considered for each step.
 * Lower values (1-10) make the output more focused, while higher values (10-50)
 * allow for more diverse outputs. Default is 20 for balanced diversity.
 * 
 * @var int
 */
$conf['top_k'] = 20;

/**
 * The min-p setting for the LLM
 * 
 * Sets a minimum probability threshold for token selection. Tokens with probabilities
 * below this threshold are filtered out. Default is 0.0 (no filtering).
 * 
 * @var float
 */
$conf['min_p'] = 0.0;

/**
 * Show copy button in the toolbar
 * 
 * Controls whether the copy page button is displayed in the LLM toolbar.
 * When true, the copy button will be visible; when false, it will be hidden.
 * 
 * @var bool
 */
$conf['show_copy_button'] = true;

/**
 * Replace ID in template content
 * 
 * Controls whether the template page ID should be replaced with the new page ID
 * when copying a page with a template. When true, the template ID will be replaced;
 * when false, it will be left as is.
 * 
 * @var bool
 */
$conf['replace_id'] = true;

/**
 * Enable thinking in LLM responses
 * 
 * Controls whether the LLM should engage in deeper thinking processes before responding.
 * When true, the LLM will use thinking capabilities and may take longer to respond;
 * when false, it will provide direct responses without extended thinking.
 * 
 * @var bool
 */
$conf['think'] = false;

/**
 * Enable tool usage in LLM responses
 * 
 * Controls whether the LLM can use tools to enhance its responses.
 * When true, the LLM can call tools like get_document, get_template, and get_examples;
 * when false, these tools will not be available to the LLM.
 * 
 * @var bool
 */
$conf['use_tools'] = false;

/**
 * ChromaDB Host
 * 
 * The hostname or IP address of your ChromaDB server.
 * This is used for document storage and retrieval.
 * 
 * @var string
 */
$conf['chroma_host'] = '10.200.8.16';

/**
 * ChromaDB Port
 * 
 * The port number on which ChromaDB is running.
 * Default ChromaDB port is 8000, but can be customized.
 * 
 * @var int
 */
$conf['chroma_port'] = 8087;

/**
 * ChromaDB Tenant
 * 
 * The tenant name for ChromaDB organization.
 * Used to isolate data between different organizations or projects.
 * 
 * @var string
 */
$conf['chroma_tenant'] = 'dokullm';

/**
 * ChromaDB Database
 * 
 * The database name within the ChromaDB tenant.
 * Used to organize collections within a tenant.
 * 
 * @var string
 */
$conf['chroma_database'] = 'dokullm';

/**
 * ChromaDB Collection
 * 
 * The default collection name for document storage.
 * Collections are used to group related documents.
 * 
 * @var string
 */
$conf['chroma_collection'] = 'reports';

/**
 * Default Institution
 * 
 * The default institution identifier for templates.
 * Used to determine which templates to use by default.
 * 
 * @var string
 */
$conf['default_institution'] = 'scuc';

/**
 * Ollama Host
 * 
 * The hostname or IP address of your Ollama server.
 * Used for generating embeddings for document search.
 * 
 * @var string
 */
$conf['ollama_host'] = '10.200.8.16';

/**
 * Ollama Port
 * 
 * The port number on which Ollama is running.
 * Default Ollama port is 11434.
 * 
 * @var int
 */
$conf['ollama_port'] = 11434;

/**
 * Ollama Embeddings Model
 * 
 * The model name used for generating text embeddings.
 * Embeddings are used for semantic search in ChromaDB.
 * 
 * @var string
 */
$conf['ollama_embeddings_model'] = 'nomic-embed-text';

