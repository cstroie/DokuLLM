<?php
/**
 * LLM Client for the dokullm plugin
 * 
 * This class provides methods to interact with an LLM API for various
 * text processing tasks such as completion, rewriting, grammar correction,
 * summarization, conclusion creation, and translation.
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

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
    
    /**
     * Initialize the LLM client with configuration settings
     * 
     * Retrieves configuration values from DokuWiki's configuration system
     * for API URL, key, model, and timeout settings.
     */
    public function __construct()
    {
        global $conf;
        $this->api_url = $conf['plugin']['dokullm']['api_url'];
        $this->api_key = $conf['plugin']['dokullm']['api_key'];
        $this->model = $conf['plugin']['dokullm']['model'];
        $this->timeout = $conf['plugin']['dokullm']['timeout'];
    }
    
    /**
     * Complete the provided text using the LLM
     * 
     * Sends a prompt to the LLM asking it to complete the given text.
     * 
     * @param string $text The text to complete
     * @return string The completed text
     */
    public function completeText($text)
    {
        $prompt = $this->loadPrompt('complete', ['text' => $text]);
        return $this->callAPI($prompt);
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
    public function rewriteText($text)
    {
        $prompt = $this->loadPrompt('rewrite', ['text' => $text]);
        return $this->callAPI($prompt);
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
    public function correctGrammar($text)
    {
        $prompt = $this->loadPrompt('grammar', ['text' => $text]);
        return $this->callAPI($prompt);
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
    public function summarizeText($text)
    {
        $prompt = $this->loadPrompt('summarize', ['text' => $text]);
        return $this->callAPI($prompt);
    }
    
    /**
     * Create a conclusion based on the provided text
     * 
     * Sends a prompt to the LLM asking it to create a well-structured
     * conclusion based on the given text.
     * 
     * @param string $text The text to create a conclusion for
     * @return string The generated conclusion
     */
    public function createConclusion($text)
    {
        $prompt = $this->loadPrompt('conclusion', ['text' => $text]);
        return $this->callAPI($prompt);
    }
    
    
    /**
     * Call the LLM API with the specified prompt
     * 
     * Makes an HTTP POST request to the configured API endpoint with
     * the prompt and other parameters. Handles authentication if an
     * API key is configured.
     * 
     * @param string $prompt The prompt to send to the LLM
     * @return string The response content from the LLM
     * @throws Exception If the API request fails or returns unexpected format
     */
    private function callAPI($prompt)
    {
        // Load system prompt
        $systemPrompt = $this->loadPrompt('system', []);
        
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1000
        ];
        
        $headers = [
            'Content-Type: application/json'
        ];
        
        if (!empty($this->api_key)) {
            $headers[] = 'Authorization: Bearer ' . $this->api_key;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception('API request failed with HTTP code: ' . $httpCode);
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['choices'][0]['message']['content'])) {
            return trim($result['choices'][0]['message']['content']);
        }
        
        throw new Exception('Unexpected API response format');
    }
    
    /**
     * Load a prompt template from file and replace placeholders
     * 
     * @param string $promptName The name of the prompt file (without extension)
     * @param array $variables Associative array of placeholder => value pairs
     * @return string The processed prompt
     * @throws Exception If the prompt file cannot be loaded
     */
    private function loadPrompt($promptName, $variables = [])
    {
        global $conf;
        $language = $conf['plugin']['dokullm']['language'];
        
        // Try to load language-specific prompt first
        if ($language !== 'default') {
            $promptFile = DOKU_PLUGIN . 'dokullm/prompts/' . $language . '/' . $promptName . '.txt';
            if (file_exists($promptFile)) {
                $prompt = file_get_contents($promptFile);
                if ($prompt !== false) {
                    // Replace placeholders with actual values
                    foreach ($variables as $placeholder => $value) {
                        $prompt = str_replace('{' . $placeholder . '}', $value, $prompt);
                    }
                    return $prompt;
                }
            }
        }
        
        // Fall back to default language
        $promptFile = DOKU_PLUGIN . 'dokullm/prompts/' . $promptName . '.txt';
        
        if (!file_exists($promptFile)) {
            throw new Exception('Prompt file not found: ' . $promptFile);
        }
        
        $prompt = file_get_contents($promptFile);
        
        if ($prompt === false) {
            throw new Exception('Failed to read prompt file: ' . $promptFile);
        }
        
        // Replace placeholders with actual values
        foreach ($variables as $placeholder => $value) {
            $prompt = str_replace('{' . $placeholder . '}', $value, $prompt);
        }
        
        return $prompt;
    }
}
