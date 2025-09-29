<?php

class ChromaDBClient {
    private $baseUrl;
    private $client;
    private $tenant;
    private $database;
    private $ollamaHost;
    private $ollamaPort;
    private $ollamaModel;

    public function __construct($host = '10.200.8.16', $port = 8087, $tenant = 'default_tenant', $database = 'default_database', $ollamaHost = '10.200.8.16', $ollamaPort = 11434, $ollamaModel = 'nomic-embed-text') {
        $this->baseUrl = "http://{$host}:{$port}";
        $this->tenant = $tenant;
        $this->database = $database;
        $this->ollamaHost = $ollamaHost;
        $this->ollamaPort = $ollamaPort;
        $this->ollamaModel = $ollamaModel;
        $this->client = curl_init();
        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->client, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
    }

    public function __destruct() {
        curl_close($this->client);
    }

    private function makeRequest($endpoint, $method = 'GET', $data = null) {
        // Add tenant and database as headers instead of query parameters for v2 API
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Chroma-Tenant: ' . $this->tenant,
            'X-Chroma-Database: ' . $this->database
        ];
        
        $url = $this->baseUrl . '/api/v2' . $endpoint;
        
        curl_setopt($this->client, CURLOPT_URL, $url);
        curl_setopt($this->client, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->client, CURLOPT_HTTPHEADER, $headers);
        
        if ($data) {
            curl_setopt($this->client, CURLOPT_POSTFIELDS, json_encode($data));
        } else {
            curl_setopt($this->client, CURLOPT_POSTFIELDS, null);
        }

        $response = curl_exec($this->client);
        $httpCode = curl_getinfo($this->client, CURLINFO_HTTP_CODE);
        
        if (curl_error($this->client)) {
            throw new Exception('Curl error: ' . curl_error($this->client));
        }
        
        if ($httpCode >= 400) {
            throw new Exception("HTTP Error: $httpCode, Response: $response");
        }
        
        return json_decode($response, true);
    }

    /**
     * Generate embeddings for text using Ollama
     * 
     * @param string $text The text to generate embeddings for
     * @return array The embeddings vector
     */
    public function generateEmbeddings($text) {
        $ollamaUrl = "http://{$this->ollamaHost}:{$this->ollamaPort}/api/embeddings";
        $ollamaClient = curl_init();
        
        curl_setopt($ollamaClient, CURLOPT_URL, $ollamaUrl);
        curl_setopt($ollamaClient, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ollamaClient, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        
        $data = [
            'model' => $this->ollamaModel,
            'prompt' => $text
        ];
        
        curl_setopt($ollamaClient, CURLOPT_POSTFIELDS, json_encode($data));
        
        $response = curl_exec($ollamaClient);
        $httpCode = curl_getinfo($ollamaClient, CURLINFO_HTTP_CODE);
        
        if (curl_error($ollamaClient)) {
            curl_close($ollamaClient);
            throw new Exception('Ollama Curl error: ' . curl_error($ollamaClient));
        }
        
        curl_close($ollamaClient);
        
        if ($httpCode >= 400) {
            throw new Exception("Ollama HTTP Error: $httpCode, Response: $response");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['embedding'])) {
            throw new Exception("Ollama response missing embedding: " . $response);
        }
        
        return $result['embedding'];
    }

    public function listCollections() {
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections";
        return $this->makeRequest($endpoint);
    }

    public function getCollection($name) {
        // First try to get collection by name
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections";
        $collections = $this->makeRequest($endpoint);
        
        // Find collection by name
        foreach ($collections as $collection) {
            if (isset($collection['name']) && $collection['name'] === $name) {
                return $collection;
            }
        }
        
        // If not found, throw exception
        throw new Exception("Collection '{$name}' not found");
    }

    public function createCollection($name, $metadata = null) {
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections";
        $data = ['name' => $name];
        if ($metadata) {
            $data['metadata'] = $metadata;
        }
        return $this->makeRequest($endpoint, 'POST', $data);
    }

    public function deleteCollection($name) {
        // First get the collection to find its ID
        $collection = $this->getCollection($name);
        if (!isset($collection['id'])) {
            throw new Exception("Collection ID not found for '{$name}'");
        }
        
        $collectionId = $collection['id'];
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}";
        return $this->makeRequest($endpoint, 'DELETE');
    }

    public function addDocuments($collectionName, $documents, $ids, $metadatas = null, $embeddings = null) {
        // First get the collection to find its ID
        $collection = $this->getCollection($collectionName);
        if (!isset($collection['id'])) {
            throw new Exception("Collection ID not found for '{$collectionName}'");
        }
        
        $collectionId = $collection['id'];
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/upsert";
        $data = [
            'ids' => $ids,
            'documents' => $documents
        ];
        
        if ($metadatas) {
            $data['metadatas'] = $metadatas;
        }
        
        if ($embeddings) {
            $data['embeddings'] = $embeddings;
        }
        
        return $this->makeRequest($endpoint, 'POST', $data);
    }

    public function queryCollection($collectionName, $queryTexts, $nResults = 5, $where = null) {
        // First get the collection to find its ID
        $collection = $this->getCollection($collectionName);
        if (!isset($collection['id'])) {
            throw new Exception("Collection ID not found for '{$collectionName}'");
        }
        
        $collectionId = $collection['id'];
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/query";
        
        // Generate embeddings for query texts
        $queryEmbeddings = [];
        foreach ($queryTexts as $text) {
            $queryEmbeddings[] = $this->generateEmbeddings($text);
        }
        
        $data = [
            'query_embeddings' => $queryEmbeddings,
            'n_results' => $nResults
        ];
        
        if ($where) {
            $data['where'] = $where;
        }
        
        return $this->makeRequest($endpoint, 'POST', $data);
    }

    /**
     * Check if the ChromaDB server is alive
     * 
     * @return array The response from the heartbeat endpoint
     */
    public function heartbeat() {
        $endpoint = "/heartbeat";
        return $this->makeRequest($endpoint, 'GET');
    }

    /**
     * Get authentication and identity information
     * 
     * @return array The response from the auth/identity endpoint
     */
    public function getIdentity() {
        $endpoint = "/identity";
        return $this->makeRequest($endpoint, 'GET');
    }

    /**
     * Convenience function to add a DokuWiki document to the Chroma database
     * 
     * @param string $collectionName The name of the collection to add to
     * @param string $id The DokuWiki document ID (e.g. 'reports:mri:medima:250620-ivan-aisha' or 'reports:mri:2024:g287-criveanu-cristian-andrei')
     * @param string $content The document content
     * @return array The response from the ChromaDB API
     */
    public function addDokuWikiDocument($collectionName, $id, $content) {
        // Parse the DokuWiki ID to extract metadata
        $parts = explode(':', $id);
        
        // Extract metadata from the last part of the ID
        $lastPart = end($parts);
        $metadata = [];
        
        // Add the document ID as metadata
        $metadata['document_id'] = $id;
        
        // Extract modality from the second part
        if (isset($parts[1])) {
            $metadata['modality'] = $parts[1];
        }
        
        // Handle different ID formats
        if (count($parts) == 5) {
            // Format: reports:mri:medima:250620-ivan-aisha
            // Extract institution from the third part
            if (isset($parts[2])) {
                $metadata['institution'] = $parts[2];
            }
            
            // Extract date and name from the last part
            if (preg_match('/^(\d{6})-(.+)$/', $lastPart, $matches)) {
                $dateStr = $matches[1];
                $name = $matches[2];
                
                // Convert date format (250620 -> 2025-06-20)
                $day = substr($dateStr, 0, 2);
                $month = substr($dateStr, 2, 2);
                $year = substr($dateStr, 4, 2);
                // Assuming 20xx for years 20-99 and 19xx for years 00-19
                $fullYear = (int)$year >= 20 ? '20' . $year : '19' . $year;
                $formattedDate = $fullYear . '-' . $month . '-' . $day;
                
                $metadata['date'] = $formattedDate;
                $metadata['name'] = str_replace('-', ' ', $name);
            }
        } else if (count($parts) == 4) {
            // Format: reports:mri:2024:g287-criveanu-cristian-andrei
            // Extract year from the third part
            if (isset($parts[2])) {
                $metadata['year'] = $parts[2];
            }
            
            // Set default institution
            $metadata['institution'] = 'scuc';
            
            // Extract registration and name from the last part
            // Registration should start with one letter or number and contain numbers before the '-' character
            if (preg_match('/^([a-zA-Z0-9]+[0-9]*)-(.+)$/', $lastPart, $matches)) {
                // Check if the first part contains at least one digit to be considered a registration
                if (preg_match('/[0-9]/', $matches[1])) {
                    $metadata['registration'] = $matches[1];
                    $metadata['name'] = str_replace('-', ' ', $matches[2]);
                } else {
                    // If no registration pattern found, treat entire part as patient name
                    $metadata['name'] = str_replace('-', ' ', $lastPart);
                }
            } else {
                // If no match, treat entire part as patient name
                $metadata['name'] = str_replace('-', ' ', $lastPart);
            }
        }
        
        return $this->addDocuments($collectionName, [$content], [$id], [$metadata]);
    }

    /**
     * Process all DokuWiki files in the reports/mri directory and subdirectories
     * 
     * @param string $basePath The base path to the DokuWiki pages directory
     * @return array Summary of processed files
     */
    public function processDokuWikiReports($basePath = '/var/www/html/dokuwiki/data/pages/reports/mri/') {
        $summary = [
            'processed' => 0,
            'errors' => 0,
            'error_details' => []
        ];

        // Check if base path exists
        if (!is_dir($basePath)) {
            throw new Exception("Base path does not exist: $basePath");
        }

        // Iterate through directories
        $directories = new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator($directories);

        foreach ($iterator as $file) {
            // Process only .txt files
            if ($file->isFile() && $file->getExtension() === 'txt') {
                try {
                    // Get relative path from base path
                    $relativePath = str_replace($basePath, '', $file->getPathname());
                    $pathParts = explode('/', trim($relativePath, '/'));
                    
                    // Extract filename without extension
                    $filename = basename($file->getFilename(), '.txt');
                    
                    // Determine the ID format based on path structure
                    $id = $this->buildDokuWikiId($pathParts, $filename);
                    
                    // Read file content
                    $content = file_get_contents($file->getPathname());
                    
                    // Add document to ChromaDB using "mri" as collection name
                    $this->addDokuWikiDocument('mri', $id, $content);
                    
                    $summary['processed']++;
                } catch (Exception $e) {
                    $summary['errors']++;
                    $summary['error_details'][] = [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage()
                    ];
                }
            }
        }
        
        return $summary;
    }

    /**
     * Build DokuWiki ID from path parts and filename
     * 
     * @param array $pathParts The path parts from the directory structure
     * @param string $filename The filename without extension
     * @return string The DokuWiki ID
     */
    private function buildDokuWikiId($pathParts, $filename) {
        // The first part is always 'reports'
        $idParts = ['reports', 'mri'];
        
        // Add intermediate parts (year or institution)
        for ($i = 0; $i < count($pathParts) - 1; $i++) {
            if (!empty($pathParts[$i])) {
                $idParts[] = $pathParts[$i];
            }
        }
        
        // Add the filename
        $idParts[] = $filename;
        
        return implode(':', $idParts);
    }
}

