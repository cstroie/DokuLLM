<?php
/**
 * LLM Client for the dokullm plugin
 * 
 * This class provides methods to interact with an LLM API for various
 * text processing tasks such as completion, rewriting, grammar correction,
 * summarization, conclusion creation, text analysis, and custom prompts.
 *
 * The client handles:
 * - API configuration and authentication
 * - Prompt template loading and processing
 * - Context-aware requests with metadata
 * - DokuWiki page content retrieval
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

// Include ChromaDB client
require_once DOKU_PLUGIN . 'dokullm/chromadb_client.php';

/**
 * LLM Client class for handling API communications
 * 
 * Manages configuration settings and provides methods for various
 * text processing operations through an LLM API.
 */
class llm_client_plugin_dokullm
{
    /** @var string The API endpoint URL */
    private $api_url;
    
    /** @var string The API authentication key */
    private $api_key;
    
    /** @var string The model identifier to use */
    private $model;
    
    /** @var int The request timeout in seconds */
    private $timeout;
    
    /** @var float The temperature setting for response randomness */
    private $temperature;
    
    /** @var float The top-p setting for nucleus sampling */
    private $top_p;
    
    /** @var int The top-k setting for token selection */
    private $top_k;
    
    /** @var float The min-p setting for minimum probability threshold */
    private $min_p;
    
    /** @var bool Whether to enable thinking in the LLM responses */
    private $think;
    
    /**
     * Initialize the LLM client with configuration settings
     * 
     * Retrieves configuration values from DokuWiki's configuration system
     * for API URL, key, model, timeout, and LLM sampling parameters.
     * 
     * Configuration values:
     * - api_url: The LLM API endpoint URL
     * - api_key: Authentication key for the API (optional)
     * - model: The model identifier to use for requests
     * - timeout: Request timeout in seconds
     * - language: Language code for prompt templates
     * - temperature: Temperature setting for response randomness (0.0-1.0)
     * - top_p: Top-p (nucleus sampling) setting (0.0-1.0)
     * - top_k: Top-k setting (integer >= 1)
     * - min_p: Minimum probability threshold (0.0-1.0)
     * - think: Whether to enable thinking in LLM responses (boolean)
     */
    public function __construct()
    {
        global $conf;
        $this->api_url = $conf['plugin']['dokullm']['api_url'];
        $this->api_key = $conf['plugin']['dokullm']['api_key'];
        $this->model = $conf['plugin']['dokullm']['model'];
        $this->timeout = $conf['plugin']['dokullm']['timeout'];
        $this->temperature = $conf['plugin']['dokullm']['temperature'];
        $this->top_p = $conf['plugin']['dokullm']['top_p'];
        $this->top_k = $conf['plugin']['dokullm']['top_k'];
        $this->min_p = $conf['plugin']['dokullm']['min_p'];
        $this->think = $conf['plugin']['dokullm']['think'];
    }
    
    /**
     * Create the provided text using the LLM
     * 
     * Sends a prompt to the LLM asking it to create the given text.
     * First queries ChromaDB for relevant documents to include as examples.
     * If no template is defined, queries ChromaDB for a template.
     * 
     * @param string $text The text to create
     * @param array $metadata Optional metadata containing template and examples
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The created text
     */
    public function createReport($text, $metadata = [], $useContext = true)
    {
        // If no template is defined, try to find one using ChromaDB
        if (empty($metadata['template'])) {
            $templateResult = $this->queryChromaDBForTemplate($text);
            if (!empty($templateResult)) {
                $metadata['template'] = $templateResult[0]; // Use the first result as template
            }
        }

        // Query ChromaDB for relevant documents to use as examples
        $chromaResults = $this->queryChromaDBWithSnippets($text, 10);
        
        // Add ChromaDB results to metadata as snippets
        if (!empty($chromaResults)) {
            // Merge with existing snippets
            $metadata['snippets'] = array_merge(
                isset($metadata['snippets']) ? $metadata['snippets'] : [],
                $chromaResults
            );
        }
        
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('create', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Rewrite text to improve clarity and flow
     * 
     * Sends a prompt to the LLM asking it to rewrite the text for better
     * clarity, structure, and readability.
     * 
     * @param string $text The text to rewrite
     * @return string The rewritten text
     */
    public function rewriteText($text, $metadata = [], $useContext = true)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('rewrite', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Correct grammar and spelling in the provided text
     * 
     * Sends a prompt to the LLM asking it to correct grammatical errors
     * and spelling mistakes in the text.
     * 
     * @param string $text The text to correct
     * @return string The corrected text
     */
    public function correctGrammar($text, $metadata = [], $useContext = true)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('grammar', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Summarize the provided text concisely
     * 
     * Sends a prompt to the LLM asking it to create a concise summary
     * of the given text.
     * 
     * @param string $text The text to summarize
     * @return string The summarized text
     */
    public function summarizeText($text, $metadata = [], $useContext = true)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('summarize', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Create a conclusion based on the provided text
     * 
     * Sends a prompt to the LLM asking it to create a well-structured
     * conclusion based on the given text.
     * 
     * @param string $text The text to create a conclusion for
     * @param array $metadata Optional metadata containing template and examples
     * @param bool $useContext Whether to include template and examples in the context (default: false)
     * @return string The generated conclusion
     */
    public function createConclusion($text, $metadata = [], $useContext = false)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('conclusion', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Analyze the provided text in detail
     * 
     * Sends a prompt to the LLM asking it to perform a detailed analysis
     * of the given text, identifying key themes, patterns, and insights.
     * 
     * @param string $text The text to analyze
     * @param array $metadata Optional metadata containing template and examples
     * @return string The analysis results
     */
    public function analyzeText($text, $metadata = [], $useContext = false)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('analyze', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Continue writing from the provided text
     * 
     * Sends a prompt to the LLM asking it to continue writing from
     * the given text, maintaining the same style and tone.
     * 
     * @param string $text The text to continue from
     * @return string The continued text
     */
    public function continueText($text, $metadata = [], $useContext = true)
    {
        $think = $this->think ? '/think' : '/no_think';
        $prompt = $this->loadPrompt('continue', ['text' => $text, 'think' => $think]);
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    /**
     * Process text with a custom user prompt
     * 
     * Sends a custom prompt to the LLM along with the provided text.
     * 
     * @param string $text The text to process
     * @param string $customPrompt The custom prompt to use
     * @param array $metadata Optional metadata containing template and examples
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The processed text
     */
    public function processCustomPrompt($text, $customPrompt, $metadata = [], $useContext = true)
    {
        // Format the prompt with the text and custom prompt
        $prompt = $customPrompt . "\n\nText to process:\n" . $text;
        return $this->callAPI($prompt, $metadata, $useContext);
    }
    
    
    /**
     * Call the LLM API with the specified prompt
     * 
     * Makes an HTTP POST request to the configured API endpoint with
     * the prompt and other parameters. Handles authentication if an
     * API key is configured.
     * 
     * The method constructs a conversation with system and user messages,
     * including context information from metadata when available.
     * 
     * Complex logic includes:
     * 1. Loading and enhancing the system prompt with metadata context
     * 2. Building the API request with model parameters
     * 3. Handling authentication with API key if configured
     * 4. Making the HTTP request with proper error handling
     * 5. Parsing and validating the API response
     * 
     * @param string $prompt The prompt to send to the LLM as user message
     * @param array $metadata Optional metadata containing template and examples
     * @param bool $useContext Whether to include template and examples in the context (default: true)
     * @return string The response content from the LLM
     * @throws Exception If the API request fails or returns unexpected format
     */
    private function callAPI($prompt, $metadata = [], $useContext = true)
    {
        // Load system prompt which provides general instructions to the LLM
        $systemPrompt = $this->loadPrompt('system', []);
        
        // Enhance system prompt with context information from metadata
        // This provides the LLM with additional context about templates and examples
        if ($useContext && !empty($metadata)) {
            $contextInfo = "\n\nThis is the context information for this request:\n";
            
            // Add template content if specified in metadata
            if (!empty($metadata['template'])) {
                $templateContent = $this->getPageContent($metadata['template']);
                if ($templateContent !== false) {
                    $contextInfo .= "\nStart from this template page (" . $metadata['template'] . "):\n" . $templateContent . "\n";
                }
            }
            
            // Add example pages content if specified in metadata
            if (!empty($metadata['examples'])) {
                $examplesContent = [];
                foreach ($metadata['examples'] as $example) {
                    $content = $this->getPageContent($example);
                    if ($content !== false) {
                        $examplesContent[] = "- Example page (" . $example . "):\n" . $content;
                    }
                }
                if (!empty($examplesContent)) {
                    $contextInfo .= "\n\nHere are some example pages:\n" . implode("\n\n", $examplesContent) . "\n";
                }
            }
            
            // Add text snippets if specified in metadata
            if (!empty($metadata['snippets'])) {
                $snippetsContent = [];
                foreach ($metadata['snippets'] as $index => $snippet) {
                    // These are text snippets from ChromaDB
                    $snippetsContent[] = "- Example " . ($index + 1) . ":\n" . $snippet;
                }
                if (!empty($snippetsContent)) {
                    $contextInfo .= "\n\nHere are some relevant text examples:\n" . implode("\n\n", $snippetsContent) . "\n";
                }
            }
            
            // Append context information to system prompt
            $systemPrompt .= "\n\n" . $contextInfo;
        }
        
        // Prepare API request data with model parameters
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'max_tokens' => 4096,
            'stream' => false,
            'think' => true
        ];
        
        // Only add parameters if they are defined and not null
        if ($this->temperature !== null) {
            $data['temperature'] = $this->temperature;
        }
        if ($this->top_p !== null) {
            $data['top_p'] = $this->top_p;
        }
        if ($this->top_k !== null) {
            $data['top_k'] = $this->top_k;
        }
        if ($this->min_p !== null) {
            $data['min_p'] = $this->min_p;
        }
        
        // Set up HTTP headers, including authentication if API key is configured
        $headers = [
            'Content-Type: application/json'
        ];
        
        if (!empty($this->api_key)) {
            $headers[] = 'Authorization: Bearer ' . $this->api_key;
        }
        
        // Initialize and configure cURL for the API request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Execute the API request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Handle cURL errors
        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }
        
        // Handle HTTP errors
        if ($httpCode !== 200) {
            throw new Exception('API request failed with HTTP code: ' . $httpCode);
        }
        
        // Parse and validate the JSON response
        $result = json_decode($response, true);
        
        // Extract the content from the response if available
        if (isset($result['choices'][0]['message']['content'])) {
            $content = trim($result['choices'][0]['message']['content']);
            // Remove content between <think> and </think> tags
            $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
            return $content;
        }
        
        // Throw exception for unexpected response format
        throw new Exception('Unexpected API response format');
    }
    
    /**
     * Load a prompt template from a DokuWiki page and replace placeholders
     * 
     * Loads prompt templates from DokuWiki pages with IDs in the format
     * dokullm:prompts:LANGUAGE:PROMPT_NAME
     * 
     * The method implements a language fallback mechanism:
     * 1. First tries to load the prompt in the configured language
     * 2. If not found, falls back to English prompts
     * 3. Throws an exception if neither is available
     * 
     * After loading the prompt, it replaces placeholders with actual values
     * using a simple string replacement mechanism.
     * 
     * @param string $promptName The name of the prompt (e.g., 'create', 'rewrite')
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The processed prompt with placeholders replaced
     * @throws Exception If the prompt page cannot be loaded in any language
     */
    private function loadPrompt($promptName, $variables = [])
    {
        global $conf;
        $language = $conf['plugin']['dokullm']['language'];
        
        // Default to 'en' if language is 'default' or not set
        if ($language === 'default' || empty($language)) {
            $language = 'en';
        }
        
        // Construct the page ID for the prompt in the configured language
        $promptPageId = 'dokullm:prompts:' . $language . ':' . $promptName;
        
        // Try to get the content of the prompt page in the configured language
        $prompt = $this->getPageContent($promptPageId);
        
        // If the language-specific prompt doesn't exist, try English as fallback
        if ($prompt === false && $language !== 'en') {
            $promptPageId = 'dokullm:prompts:en:' . $promptName;
            $prompt = $this->getPageContent($promptPageId);
        }
        
        // If still no prompt found, throw an exception
        if ($prompt === false) {
            throw new Exception('Prompt page not found: ' . $promptPageId);
        }
        
        // Replace placeholders with actual values
        // Placeholders are in the format {placeholder_name}
        foreach ($variables as $placeholder => $value) {
            $prompt = str_replace('{' . $placeholder . '}', $value, $prompt);
        }
        
        // Return the processed prompt
        return $prompt;
    }
    
    /**
     * Get the content of a DokuWiki page
     * 
     * Retrieves the raw content of a DokuWiki page by its ID.
     * Used for loading template and example page content for context.
     * 
     * @param string $pageId The page ID to retrieve
     * @return string|false The page content or false if not found/readable
     */
    public function getPageContent($pageId)
    {
        // Convert page ID to file path
        $pageFile = wikiFN($pageId);
        
        // Check if file exists and is readable
        if (file_exists($pageFile) && is_readable($pageFile)) {
            return file_get_contents($pageFile);
        }
        
        return false;
    }
    
    /**
     * Get ChromaDB client with configuration
     * 
     * Creates and returns a ChromaDB client with the appropriate configuration.
     * Extracts modality from the current page ID to use as the collection name.
     * 
     * @return array Array containing the ChromaDB client and collection name
     */
    private function getChromaDBClient()
    {
        // Include config.php to get ChromaDB configuration
        require_once 'config.php';
        
        // Get ChromaDB configuration from config.php
        $chromaHost = defined('CHROMA_HOST') ? CHROMA_HOST : 'localhost';
        $chromaPort = defined('CHROMA_PORT') ? CHROMA_PORT : 8000;
        $chromaTenant = defined('CHROMA_TENANT') ? CHROMA_TENANT : 'dokullm';
        $chromaDatabase = defined('CHROMA_DATABASE') ? CHROMA_DATABASE : 'dokullm';
        $chromaDefaultCollection = defined('CHROMA_COLLECTION') ? CHROMA_COLLECTION : 'documents';
        
        // Use the first part of the current page ID as collection name, fallback to default
        global $ID;
        $chromaCollection = $chromaDefaultCollection; // Default collection name
        
        if (!empty($ID)) {
            // Split the page ID by ':' and take the first part as collection name
            $parts = explode(':', $ID);
            if (isset($parts[0]) && !empty($parts[0])) {
                // If the first part is 'playground', use the default collection
                // Otherwise, use the first part as the collection name
                if ($parts[0] === 'playground') {
                    $chromaCollection = $chromaDefaultCollection;
                } else {
                    $chromaCollection = $parts[0];
                }
            }
        }
        
        // Create ChromaDB client
        $chromaClient = new ChromaDBClient($chromaHost, $chromaPort, $chromaTenant, $chromaDatabase);
        

        return [$chromaClient, $chromaCollection];
    }
    
    /**
     * Query ChromaDB for relevant documents
     * 
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Extracts modality from the current page ID to use as the collection name.
     * 
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 5)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of document IDs
     */
    private function queryChromaDB($text, $limit = 5, $where = null)
    {
        try {
            // Get ChromaDB client and collection name
            list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
            // Query for similar documents
            $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);
            
            // Extract document IDs from results
            $documentIds = [];
            if (isset($results['ids'][0]) && is_array($results['ids'][0])) {
                foreach ($results['ids'][0] as $id) {
                    // Use the ChromaDB ID directly without conversion
                    $documentIds[] = $id;
                }
            }
            
            return $documentIds;
        } catch (Exception $e) {
            // Log error but don't fail the operation
            error_log('ChromaDB query failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query ChromaDB for relevant documents and return text snippets
     * 
     * Generates embeddings for the input text and queries ChromaDB for similar documents.
     * Returns the actual text snippets instead of document IDs.
     * 
     * @param string $text The text to find similar documents for
     * @param int $limit Maximum number of documents to retrieve (default: 5)
     * @param array|null $where Optional filter conditions for metadata
     * @return array List of text snippets
     */
    private function queryChromaDBWithSnippets($text, $limit = 5, $where = null)
    {
        try {
            // Get ChromaDB client and collection name
            list($chromaClient, $chromaCollection) = $this->getChromaDBClient();
            // Query for similar documents
            $results = $chromaClient->queryCollection($chromaCollection, [$text], $limit, $where);
            
            // Extract document texts from results
            $snippets = [];
            if (isset($results['documents'][0]) && is_array($results['documents'][0])) {
                foreach ($results['documents'][0] as $document) {
                    $snippets[] = $document;
                }
            }
            
            return $snippets;
        } catch (Exception $e) {
            // Log error but don't fail the operation
            error_log('ChromaDB query failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query ChromaDB for a template document
     * 
     * Generates embeddings for the input text and queries ChromaDB for a template document
     * by filtering with metadata 'template=true'.
     * 
     * @param string $text The text to find a template for
     * @return array List of template document IDs (maximum 1)
     */
    private function queryChromaDBForTemplate($text)
    {
        $templateIds = $this->queryChromaDB($text, 1, ['type' => 'template']);
        
        // Remove chunk number (e.g., "@2") from the ID to get the base document ID
        if (!empty($templateIds)) {
            $templateIds[0] = preg_replace('/@\\d+$/', '', $templateIds[0]);
        }
        
        return $templateIds;
    }
}
