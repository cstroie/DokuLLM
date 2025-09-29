<?php

class ChromaDBClient {
    private $baseUrl;
    private $client;
    private $tenant;
    private $database;
    private $ollamaHost;
    private $ollamaPort;
    /**
     * Initialize the ChromaDB client
     * 
     * Creates a new ChromaDB client instance with the specified connection parameters.
     * Also ensures that the specified tenant and database exist.
     * 
     * @param string $host ChromaDB server host (default: CHROMA_HOST)
     * @param int $port ChromaDB server port (default: CHROMA_PORT)
     * @param string $tenant ChromaDB tenant name (default: CHROMA_TENANT)
     * @param string $database ChromaDB database name (default: CHROMA_DATABASE)
     * @param string $ollamaHost Ollama server host (default: OLLAMA_HOST)
     * @param int $ollamaPort Ollama server port (default: OLLAMA_PORT)
     * @param string $ollamaModel Ollama embeddings model (default: OLLAMA_EMBEDDINGS_MODEL)
     */
    public function __construct($host = CHROMA_HOST, $port = CHROMA_PORT, $tenant = CHROMA_TENANT, $database = CHROMA_DATABASE, $ollamaHost = OLLAMA_HOST, $ollamaPort = OLLAMA_PORT, $ollamaModel = OLLAMA_EMBEDDINGS_MODEL) {
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
        
        // Check if tenant and database exist, create them if they don't
        $this->ensureTenantAndDatabase();
    }

    /**
     * Clean up the cURL client when the object is destroyed
     * 
     * @return void
     */
    public function __destruct() {
        curl_close($this->client);
    }

    /**
     * Make an HTTP request to the ChromaDB API
     * 
     * This is a helper function that handles making HTTP requests to the ChromaDB API,
     * including setting the appropriate headers for tenant and database.
     * 
     * @param string $endpoint The API endpoint to call
     * @param string $method The HTTP method to use (default: 'GET')
     * @param array|null $data The data to send with the request (default: null)
     * @return array The JSON response decoded as an array
     * @throws Exception If there's a cURL error or HTTP error
     */
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
            'prompt' => $text,
            'keep_alive' => '30m'
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

    /**
     * List all collections in the database
     * 
     * Retrieves a list of all collections in the specified tenant and database.
     * 
     * @return array List of collections
     */
    public function listCollections() {
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections";
        return $this->makeRequest($endpoint);
    }

    /**
     * Get a collection by name
     * 
     * Retrieves information about a specific collection by its name.
     * 
     * @param string $name The name of the collection to retrieve
     * @return array The collection information
     * @throws Exception If the collection is not found
     */
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

    /**
     * Create a new collection
     * 
     * Creates a new collection with the specified name and optional metadata.
     * 
     * @param string $name The name of the collection to create
     * @param array|null $metadata Optional metadata for the collection
     * @return array The response from the API
     */
    public function createCollection($name, $metadata = null) {
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections";
        $data = ['name' => $name];
        if ($metadata) {
            $data['metadata'] = $metadata;
        }
        return $this->makeRequest($endpoint, 'POST', $data);
    }

    /**
     * Delete a collection by name
     * 
     * Deletes a collection with the specified name.
     * 
     * @param string $name The name of the collection to delete
     * @return array The response from the API
     * @throws Exception If the collection ID is not found
     */
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

    /**
     * Add documents to a collection
     * 
     * Adds documents to the specified collection. Each document must have a corresponding ID.
     * Optional metadata and pre-computed embeddings can also be provided.
     * 
     * @param string $collectionName The name of the collection to add documents to
     * @param array $documents The document contents
     * @param array $ids The document IDs
     * @param array|null $metadatas Optional metadata for each document
     * @param array|null $embeddings Optional pre-computed embeddings for each document
     * @return array The response from the API
     * @throws Exception If the collection ID is not found
     */
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

    /**
     * Query a collection for similar documents
     * 
     * Queries the specified collection for documents similar to the provided query texts.
     * The function generates embeddings for the query texts and sends them to ChromaDB.
     * 
     * @param string $collectionName The name of the collection to query
     * @param array $queryTexts The query texts to search for
     * @param int $nResults The number of results to return (default: 5)
     * @param array|null $where Optional filter conditions
     * @return array The query results
     * @throws Exception If the collection ID is not found
     */
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
    /**
     * Check if the ChromaDB server is alive
     * 
     * Sends a heartbeat request to verify that the ChromaDB server is running.
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
    /**
     * Get authentication and identity information
     * 
     * Retrieves authentication and identity information from the ChromaDB server.
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
     * This function processes DokuWiki documents with two possible ID formats:
     * Format 1: reports:mri:institution:250620-ivan-aisha (5 parts)
     * Format 2: reports:mri:2024:g287-criveanu-cristian-andrei (4 parts)
     * 
     * The differentiation is based on the third part of the ID:
     * - If it's a word (institution name), it's format 1
     * - If it's numeric (year), it's format 2
     * 
     * @param string $collectionName The name of the collection to add to
     * @param string $id The DokuWiki document ID (e.g. 'reports:mri:medima:250620-ivan-aisha' or 'reports:mri:2024:g287-criveanu-cristian-andrei')
     * @param string $content The document content
     * @return array The response from the ChromaDB API
     */
    public function addDokuWikiDocument($collectionName, $id, $content) {
        // Split document into chunks (paragraphs separated by two newlines)
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $chunks = [];
        $chunkMetadata = [];
        
        // Parse the DokuWiki ID to extract base metadata
        $parts = explode(':', $id);
        
        // Extract metadata from the last part of the ID
        $lastPart = end($parts);
        $baseMetadata = [];
        
        // Add the document ID as metadata
        $baseMetadata['document_id'] = $id;
        
        // Check if any part of the ID is 'templates' and set template metadata
        if (in_array('templates', $parts)) {
            $baseMetadata['template'] = true;
        }
        
        // Extract modality from the second part
        if (isset($parts[1])) {
            $baseMetadata['modality'] = $parts[1];
        }
        
        // Handle different ID formats based on the third part: word (institution) or numeric (year)
        // Format 1: reports:mri:institution:250620-ivan-aisha (third part is institution name)
        // Format 2: reports:mri:2024:g287-criveanu-cristian-andrei (third part is year)
        if (isset($parts[2])) {
            // Check if third part is numeric (year) or word (institution)
            if (is_numeric($parts[2])) {
                // Format: reports:mri:2024:g287-criveanu-cristian-andrei (year format)
                // Extract year from the third part
                $baseMetadata['year'] = $parts[2];
                
                // Set default institution from config
                $baseMetadata['institution'] = DEFAULT_INSTITUTION;
                
                // Extract registration and name from the last part
                // Registration should start with one letter or number and contain numbers before the '-' character
                if (preg_match('/^([a-zA-Z0-9]+[0-9]*)-(.+)$/', $lastPart, $matches)) {
                    // Check if the first part contains at least one digit to be considered a registration
                    if (preg_match('/[0-9]/', $matches[1])) {
                        $baseMetadata['registration'] = $matches[1];
                        $baseMetadata['name'] = str_replace('-', ' ', $matches[2]);
                    } else {
                        // If no registration pattern found, treat entire part as patient name
                        $baseMetadata['name'] = str_replace('-', ' ', $lastPart);
                    }
                } else {
                    // If no match, treat entire part as patient name
                    $baseMetadata['name'] = str_replace('-', ' ', $lastPart);
                }
            } else {
                // Format: reports:mri:institution:250620-ivan-aisha (institution format)
                // Extract institution from the third part
                $baseMetadata['institution'] = $parts[2];
                
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
                    
                    $baseMetadata['date'] = $formattedDate;
                    $baseMetadata['name'] = str_replace('-', ' ', $name);
                }
            }
        }
        
        // Process each paragraph as a chunk
        $chunkIds = [];
        $chunkContents = [];
        $chunkMetadatas = [];
        $chunkEmbeddings = [];
        
        foreach ($paragraphs as $index => $paragraph) {
            // Skip empty paragraphs
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }
            
            // Create chunk ID
            $chunkId = $id . '@' . ($index + 1);
            
            // Generate embeddings for the chunk
            $embeddings = $this->generateEmbeddings($paragraph);
            
            // Add chunk-specific metadata
            $metadata = $baseMetadata;
            $metadata['chunk_id'] = $chunkId;
            $metadata['chunk_number'] = $index + 1;
            $metadata['total_chunks'] = count($paragraphs);
            
            // Store chunk data
            $chunkIds[] = $chunkId;
            $chunkContents[] = $paragraph;
            $chunkMetadatas[] = $metadata;
            $chunkEmbeddings[] = $embeddings;
        }
        
        // Send all chunks to ChromaDB
        return $this->addDocuments($collectionName, $chunkContents, $chunkIds, $chunkMetadatas, $chunkEmbeddings);
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
    /**
     * Build DokuWiki ID from path parts and filename
     * 
     * Constructs a DokuWiki ID from the directory structure and filename.
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
    
    /**
     * Ensure that the specified tenant and database exist
     * 
     * @return void
     */
    /**
     * Ensure that the specified tenant and database exist
     * 
     * Checks if the specified tenant and database exist, and creates them if they don't.
     * 
     * @return void
     */
    private function ensureTenantAndDatabase() {
        // Check if tenant exists, create if it doesn't
        try {
            $this->getTenant($this->tenant);
        } catch (Exception $e) {
            // Tenant doesn't exist, create it
            $this->createTenant($this->tenant);
        }
        
        // Check if database exists, create if it doesn't
        try {
            $this->getDatabase($this->database, $this->tenant);
        } catch (Exception $e) {
            // Database doesn't exist, create it
            $this->createDatabase($this->database, $this->tenant);
        }
    }
    
    /**
     * Get tenant information
     * 
     * @param string $tenantName The tenant name
     * @return array The tenant information
     */
    /**
     * Get tenant information
     * 
     * Retrieves information about the specified tenant.
     * 
     * @param string $tenantName The tenant name
     * @return array The tenant information
     */
    public function getTenant($tenantName) {
        $endpoint = "/tenants/{$tenantName}";
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Create a new tenant
     * 
     * @param string $tenantName The tenant name
     * @return array The response from the API
     */
    /**
     * Create a new tenant
     * 
     * Creates a new tenant with the specified name.
     * 
     * @param string $tenantName The tenant name
     * @return array The response from the API
     */
    public function createTenant($tenantName) {
        $endpoint = "/tenants";
        $data = ['name' => $tenantName];
        return $this->makeRequest($endpoint, 'POST', $data);
    }
    
    /**
     * Get database information
     * 
     * @param string $databaseName The database name
     * @param string $tenantName The tenant name
     * @return array The database information
     */
    /**
     * Get database information
     * 
     * Retrieves information about the specified database within a tenant.
     * 
     * @param string $databaseName The database name
     * @param string $tenantName The tenant name
     * @return array The database information
     */
    public function getDatabase($databaseName, $tenantName) {
        $endpoint = "/tenants/{$tenantName}/databases/{$databaseName}";
        return $this->makeRequest($endpoint, 'GET');
    }
    
    /**
     * Create a new database
     * 
     * @param string $databaseName The database name
     * @param string $tenantName The tenant name
     * @return array The response from the API
     */
    /**
     * Create a new database
     * 
     * Creates a new database with the specified name within a tenant.
     * 
     * @param string $databaseName The database name
     * @param string $tenantName The tenant name
     * @return array The response from the API
     */
    public function createDatabase($databaseName, $tenantName) {
        $endpoint = "/tenants/{$tenantName}/databases";
        $data = ['name' => $databaseName];
        return $this->makeRequest($endpoint, 'POST', $data);
    }
}

