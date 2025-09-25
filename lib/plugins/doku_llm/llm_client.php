<?php
/**
 * LLM Client for the doku_llm plugin
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class llm_client_plugin_doku_llm
{
    private $api_url;
    private $api_key;
    private $model;
    private $timeout;
    
    public function __construct()
    {
        global $conf;
        $this->api_url = $conf['plugin']['doku_llm']['api_url'];
        $this->api_key = $conf['plugin']['doku_llm']['api_key'];
        $this->model = $conf['plugin']['doku_llm']['model'];
        $this->timeout = $conf['plugin']['doku_llm']['timeout'];
    }
    
    /**
     * Complete text
     */
    public function completeText($text)
    {
        $prompt = "Complete the following text:\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Rewrite text
     */
    public function rewriteText($text)
    {
        $prompt = "Rewrite the following text to improve clarity and flow:\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Correct grammar
     */
    public function correctGrammar($text)
    {
        $prompt = "Correct the grammar and spelling in the following text:\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Summarize text
     */
    public function summarizeText($text)
    {
        $prompt = "Summarize the following text in a concise manner:\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Create conclusion
     */
    public function createConclusion($text)
    {
        $prompt = "Based on the following text, create a well-structured conclusion:\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Translate text
     */
    public function translateText($text, $targetLanguage)
    {
        $prompt = "Translate the following text to " . $targetLanguage . ":\n\n" . $text;
        return $this->callAPI($prompt);
    }
    
    /**
     * Call the LLM API
     */
    private function callAPI($prompt)
    {
        $data = [
            'model' => $this->model,
            'messages' => [
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
}