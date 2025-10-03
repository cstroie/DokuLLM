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
            'Accept: application/json'
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
        // Use provided name, fallback to 'documents' if empty
        if (empty($name)) {
            $name = 'documents';
        }
        
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
        // Use provided name, fallback to 'documents' if empty
        if (empty($name)) {
            $name = 'documents';
        }
        
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
        // Use provided name, fallback to 'documents' if empty
        if (empty($name)) {
            $name = 'documents';
        }
        
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
        // Use provided name, fallback to 'documents' if empty
        if (empty($collectionName)) {
            $collectionName = 'documents';
        }
        
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
     * Check if a document needs to be updated based on timestamp
     * 
     * Checks if a document exists and if its timestamp is older than the file's modification time.
     * 
     * @param string $collectionName The name of the collection to check documents in
     * @param array $ids The document IDs to check
     * @param int $fileModifiedTime The file's last modification timestamp
     * @return bool True if document needs to be updated, false otherwise
     * @throws Exception If there's an error checking the document
     */
    public function checkDocumentNeedsUpdate($collectionName, $ids, $fileModifiedTime) {
        // Use provided name, fallback to 'documents' if empty
        if (empty($collectionName)) {
            $collectionName = 'documents';
        }
        
        try {
            // First get the collection to find its ID
            $collection = $this->getCollection($collectionName);
            if (!isset($collection['id'])) {
                throw new Exception("Collection ID not found for '{$collectionName}'");
            }
            
            $collectionId = $collection['id'];
            $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/get";
            $data = [
                'ids' => $ids,
                'include' => [
                    "metadatas"
                ],
                'limit' => 1
            ];
            
            // Check if document exists
            $result = $this->makeRequest($endpoint, 'POST', $data);
            
            // If no documents found, return true (needs to be added)
            if (empty($result['ids'])) {
                return true;
            }
            
            // Check if any document has a processed_at timestamp
            if (!empty($result['metadatas']) && is_array($result['metadatas'])) {
                foreach ($result['metadatas'] as $metadata) {
                    if (isset($metadata['processed_at'])) {
                        // Parse the processed_at timestamp
                        $processedTimestamp = strtotime($metadata['processed_at']);
                        
                        // If file is newer than processed time, return true (needs update)
                        if ($fileModifiedTime > $processedTimestamp) {
                            return true;
                        }
                    }
                }
            }
            
            // Document exists and is up to date
            return false;
        } catch (Exception $e) {
            // If there's an error checking the document, assume it needs to be updated
            return true;
        }
    }

    /**
     * Query a collection for similar documents
     * 
     * Queries the specified collection for documents similar to the provided query texts.
     * The function generates embeddings for the query texts and sends them to ChromaDB.
     * Supports filtering results by metadata using the where parameter.
     * 
     * @param string $collectionName The name of the collection to query
     * @param array $queryTexts The query texts to search for
     * @param int $nResults The number of results to return (default: 5)
     * @param array|null $where Optional filter conditions for metadata
     * @return array The query results
     * @throws Exception If the collection ID is not found
     */
    public function queryCollection($collectionName, $queryTexts, $nResults = 5, $where = null) {
        // Use provided name, fallback to 'documents' if empty
        if (empty($collectionName)) {
            $collectionName = 'documents';
        }
        
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
        
        // Add where clause for metadata filtering if provided
        if ($where && is_array($where)) {
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

