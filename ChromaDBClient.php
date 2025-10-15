<?php

namespace dokuwiki\plugin\dokullm;

class ChromaDBClient {
    private $baseUrl;
    private $client;
    private $ollamaClient;
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
     * @param string $host ChromaDB server host
     * @param int $port ChromaDB server port
     * @param string $tenant ChromaDB tenant name
     * @param string $database ChromaDB database name
     * @param string $ollamaHost Ollama server host
     * @param int $ollamaPort Ollama server port
     * @param string $ollamaModel Ollama embeddings model
     */
    public function __construct($host = null, $port = null, $tenant = null, $database = null, $ollamaHost = null, $ollamaPort = null, $ollamaModel = null) {
        // Load DokuWiki plugin configuration
        global $conf;
        
        // Use provided parameters or fall back to configuration values
        $chromaHost = $host ?? ($conf['plugin']['dokullm']['chroma_host'] ?? '127.0.0.1');
        $chromaPort = $port ?? ($conf['plugin']['dokullm']['chroma_port'] ?? 8000);
        $this->tenant = $tenant ?? ($conf['plugin']['dokullm']['chroma_tenant'] ?? 'dokullm');
        $this->database = $database ?? ($conf['plugin']['dokullm']['chroma_database'] ?? 'dokullm');
        $this->ollamaHost = $ollamaHost ?? ($conf['plugin']['dokullm']['ollama_host'] ?? '127.0.0.1');
        $this->ollamaPort = $ollamaPort ?? ($conf['plugin']['dokullm']['ollama_port'] ?? 11434);
        $this->ollamaModel = $ollamaModel ?? ($conf['plugin']['dokullm']['ollama_embeddings_model'] ?? 'nomic-embed-text');
        
        $this->baseUrl = "http://{$chromaHost}:{$chromaPort}";
        $this->client = curl_init();
        curl_setopt($this->client, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->client, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        
        // Initialize Ollama client
        $this->ollamaClient = curl_init();
        curl_setopt($this->ollamaClient, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->ollamaClient, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
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
        curl_close($this->ollamaClient);
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
            throw new \Exception('Curl error: ' . curl_error($this->client));
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("HTTP Error: $httpCode, Response: $response");
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
        
        curl_setopt($this->ollamaClient, CURLOPT_URL, $ollamaUrl);
        
        $data = [
            'model' => $this->ollamaModel,
            'prompt' => $text,
            'keep_alive' => '30m'
        ];
        
        curl_setopt($this->ollamaClient, CURLOPT_POSTFIELDS, json_encode($data));
        
        $response = curl_exec($this->ollamaClient);
        $httpCode = curl_getinfo($this->ollamaClient, CURLINFO_HTTP_CODE);
        
        if (curl_error($this->ollamaClient)) {
            throw new \Exception('Ollama Curl error: ' . curl_error($this->ollamaClient));
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("Ollama HTTP Error: $httpCode, Response: $response");
        }
        
        $result = json_decode($response, true);
        
        if (!isset($result['embedding'])) {
            throw new \Exception("Ollama response missing embedding: " . $response);
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
        throw new \Exception("Collection '{$name}' not found");
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
            throw new \Exception("Collection ID not found for '{$name}'");
        }
        
        $collectionId = $collection['id'];
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}";
        return $this->makeRequest($endpoint, 'DELETE');
    }

    /**
     * Get a document by its ID from a collection
     * 
     * Retrieves a document from the specified collection using its ID.
     * 
     * @param string $collectionName The name of the collection to get the document from
     * @param string $documentId The document ID to retrieve
     * @param array $include What to include in the response (default: ["metadatas", "documents"])
     * @return array The retrieved document
     * @throws Exception If the collection ID is not found
     */
    public function getDocument($collectionName, $documentId, $include = ["metadatas", "documents"]) {
        // Use provided name, fallback to 'documents' if empty
        if (empty($collectionName)) {
            $collectionName = 'documents';
        }
        
        // First get the collection to find its ID
        $collection = $this->getCollection($collectionName);
        if (!isset($collection['id'])) {
            throw new \Exception("Collection ID not found for '{$collectionName}'");
        }
        
        $collectionId = $collection['id'];
        $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/get";
        $data = [
            'ids' => [$documentId],
            'include' => $include
        ];
        
        return $this->makeRequest($endpoint, 'POST', $data);
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
            throw new \Exception("Collection ID not found for '{$collectionName}'");
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
     * Check if a document needs to be updated based on timestamp comparison
     * 
     * Determines whether a document should be reprocessed by comparing the file's last modification
     * time with the processed_at timestamp stored in the document's metadata. The function checks
     * the first 3 chunk IDs (@1, @2, @3) since the first chunks might be titles and therefore 
     * not included in the database.
     * 
     * @param string $collectionId The ID of the collection to check documents in
     * @param string $documentId The base document ID to check (without chunk suffixes)
     * @param int $fileModifiedTime The file's last modification timestamp (from filemtime)
     * @return bool True if document needs to be updated (doesn't exist, has no timestamp, or is outdated), false if up to date
     * @throws Exception If there's an error checking the document
     */
    public function needsUpdate($collectionId, $documentId, $fileModifiedTime) {
        try {
            $endpoint = "/tenants/{$this->tenant}/databases/{$this->database}/collections/{$collectionId}/get";
            
            // Check first 3 chunk numbers (@1, @2, @3) since first chunks might be titles and skipped
            $chunkIdsToCheck = [
                $documentId . '@1',
                $documentId . '@2', 
                $documentId . '@3'
            ];
            
            $data = [
                'ids' => $chunkIdsToCheck,
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
                // Check the first metadata entry directly
                $metadata = $result['metadatas'][0];
                
                // If processed_at is not set, return true (needs update)
                if (!isset($metadata['processed_at'])) {
                    return true;
                }
                
                // Parse the processed_at timestamp
                $processedTimestamp = strtotime($metadata['processed_at']);
                
                // If file is newer than processed time, return true (needs update)
                if ($fileModifiedTime > $processedTimestamp) {
                    return true;
                }
            }
            
            // Document exists and is up to date
            return false;
        } catch (\Exception $e) {
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
            throw new \Exception("Collection ID not found for '{$collectionName}'");
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
     * Checks if the specified tenant and database exist, and creates them if they don't.
     * 
     * @return void
     */
    private function ensureTenantAndDatabase() {
        // Check if tenant exists, create if it doesn't
        try {
            $this->getTenant($this->tenant);
        } catch (\Exception $e) {
            // Tenant doesn't exist, create it
            $this->createTenant($this->tenant);
        }
        
        // Check if database exists, create if it doesn't
        try {
            $this->getDatabase($this->database, $this->tenant);
        } catch (\Exception $e) {
            // Database doesn't exist, create it
            $this->createDatabase($this->database, $this->tenant);
        }
    }
    
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
    
    /**
     * Ensure a collection exists, creating it if necessary
     * 
     * This helper function checks if a collection exists and creates it if it doesn't.
     * 
     * @param string $collectionName The name of the collection to check/create
     * @return string Status message indicating what happened
     */
    public function ensureCollectionExists($collectionName) {
        try {
            $collection = $this->getCollection($collectionName);
            return "Collection '$collectionName' already exists.";
        } catch (\Exception $e) {
            // Collection doesn't exist, create it
            $created = $this->createCollection($collectionName);
            return "Collection '$collectionName' created.";
        }
    }
    
    /**
     * Process a single DokuWiki file and send it to ChromaDB with intelligent update checking
     * 
     * This function handles the complete processing of a single DokuWiki file:
     * 1. Parses the file path to extract metadata and document ID
     * 2. Determines the appropriate collection based on document ID
     * 3. Checks if the document needs updating using timestamp comparison
     * 4. Reads and processes file content only if update is needed
     * 5. Splits the document into chunks (paragraphs)
     * 6. Extracts rich metadata from the DokuWiki ID format
     * 7. Generates embeddings for each chunk
     * 8. Sends all chunks to ChromaDB with metadata
     * 
     * Supported ID formats:
     * - Format 1: reports:mri:institution:250620-name-surname (third part is institution name)
     * - Format 2: reports:mri:2024:g287-name-surname (third part is year)
     * - Templates: reports:mri:templates:name-surname (contains 'templates' part)
     * 
     * The function implements smart update checking by comparing file modification time
     * with the 'processed_at' timestamp in document metadata to avoid reprocessing unchanged files.
     * 
     * @param string $filePath The path to the file to process
     * @param string $collectionName The name of the collection to use
     * @param bool $collectionChecked Whether the collection has already been checked/created
     * @return array Result with status and details
     */
    public function processSingleFile($filePath, $collectionName, $collectionChecked = false) {
        // Parse file path to extract metadata
        $id = parseFilePath($filePath);
        
        try {
            // Create collection if it doesn't exist (only if not already checked)
            $collectionStatus = '';
            if (!$collectionChecked) {
                $collectionStatus = $this->ensureCollectionExists($collectionName);
            }
        
            // Get collection ID
            $collection = $this->getCollection($collectionName);
            if (!isset($collection['id'])) {
                return [
                    'status' => 'error',
                    'message' => "Collection ID not found for '{$collectionName}'"
                ];
            }
            $collectionId = $collection['id'];
            
            // Get file modification time
            $fileModifiedTime = filemtime($filePath);
            
            // Check if document needs update
            $needsUpdate = $this->needsUpdate($collectionId, $id, $fileModifiedTime);
            
            // If document is up to date, skip processing
            if (!$needsUpdate) {
                return [
                    'status' => 'skipped',
                    'message' => "Document '$id' is up to date in collection '$collectionName'. Skipping..."
                ];
            }
            
            // Read file content
            $content = file_get_contents($filePath);
            
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
            
            // Add current timestamp
            $baseMetadata['processed_at'] = date('Y-m-d H:i:s');
            
            // Check if any part of the ID is 'templates' and set template metadata
            $isTemplate = in_array('templates', $parts);
            if ($isTemplate) {
                $baseMetadata['type'] = 'template';
            } else {
                $baseMetadata['type'] = 'report';
            }
            
            // Extract modality from the second part
            if (isset($parts[1])) {
                $baseMetadata['modality'] = $parts[1];
            }
            
            // Handle different ID formats based on the third part: word (institution) or numeric (year)
            // Format 1: reports:mri:institution:250620-name-surname (third part is institution name)
            // Format 2: reports:mri:2024:g287-name-surname (third part is year)
            // For templates, don't set institution, date or year
            if (isset($parts[2]) && !$isTemplate) {
                // Check if third part is numeric (year) or word (institution)
                if (is_numeric($parts[2])) {
                    // Format: reports:mri:2024:g287-name-surname (year format)
                    // Extract year from the third part
                    $baseMetadata['year'] = $parts[2];
                    
                    // Set default institution from config
                    global $conf;
                    $baseMetadata['institution'] = $conf['plugin']['dokullm']['default_institution'] ?? 'default';
                    
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
                    // Format: reports:mri:institution:250620-name-surname (institution format)
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
                        // Assuming 20xx for years 00-69 and 19xx for years 70-99
                        $fullYear = (int)$year <= 70 ? '20' . $year : '19' . $year;
                        $formattedDate = $fullYear . '-' . $month . '-' . $day;
                        
                        $baseMetadata['date'] = $formattedDate;
                        $baseMetadata['name'] = str_replace('-', ' ', $name);
                    }
                }
            }
            
            // For templates, always extract name from the last part
            if ($isTemplate && isset($lastPart)) {
                // Extract name from the last part (everything after the last colon)
                if (preg_match('/^([a-zA-Z0-9]+[0-9]*)-(.+)$/', $lastPart, $matches)) {
                    // Check if the first part contains at least one digit to be considered a registration
                    if (preg_match('/[0-9]/', $matches[1])) {
                        $baseMetadata['registration'] = $matches[1];
                        $baseMetadata['name'] = str_replace('-', ' ', $matches[2]);
                    } else {
                        // If no registration pattern found, treat entire part as template name
                        $baseMetadata['name'] = str_replace('-', ' ', $lastPart);
                    }
                } else {
                    // If no match, treat entire part as template name
                    $baseMetadata['name'] = str_replace('-', ' ', $lastPart);
                }
            }
            
            // Process each paragraph as a chunk with intelligent metadata handling
            $chunkIds = [];
            $chunkContents = [];
            $chunkMetadatas = [];
            $chunkEmbeddings = [];
            $currentTags = [];
            
            foreach ($paragraphs as $index => $paragraph) {
                // Skip empty paragraphs to avoid processing whitespace-only content
                $paragraph = trim($paragraph);
                if (empty($paragraph)) {
                    continue;
                }
                
                // Check if this is a DokuWiki title (starts and ends with =)
                // Titles are converted to tags for better searchability but not stored as content chunks
                if (preg_match('/^=+(.*?)=+$/', $paragraph, $matches)) {
                    // Extract title content and clean it
                    $titleContent = trim($matches[1]);
                    
                    // Split into words and create searchable tags
                    $words = preg_split('/\s+/', $titleContent);
                    $tags = [];
                    
                    foreach ($words as $word) {
                        // Only use words longer than 3 characters to reduce noise
                        if (strlen($word) >= 3) {
                            $tags[] = strtolower($word);
                        }
                    }
                    
                    // Remove duplicate tags and store for use in subsequent chunks
                    $currentTags = array_unique($tags);
                    continue; // Skip storing title chunks as content
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
                
                // Add current tags to metadata if any exist
                if (!empty($currentTags)) {
                    $metadata['tags'] = implode(',', $currentTags);
                }
                
                // Store chunk data
                $chunkIds[] = $chunkId;
                $chunkContents[] = $paragraph;
                $chunkMetadatas[] = $metadata;
                $chunkEmbeddings[] = $embeddings;
            }
            
            // If no chunks were created, skip this file
            if (empty($chunkIds)) {
                return [
                    'status' => 'skipped',
                    'message' => "No valid chunks found in file '$id'. Skipping..."
                ];
            }
            
            // Send all chunks to ChromaDB
            $result = $this->addDocuments($collectionName, $chunkContents, $chunkIds, $chunkMetadatas, $chunkEmbeddings);
            
            return [
                'status' => 'success',
                'message' => "Successfully sent file to ChromaDB",
                'details' => [
                    'document_id' => $id,
                    'chunks' => count($chunkIds),
                    'collection' => $collectionName
                ],
                'collection_status' => $collectionStatus
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => "Error sending file to ChromaDB: " . $e->getMessage()
            ];
        }
    }
    
    /**
     * Process all DokuWiki files in a directory and send them to ChromaDB
     * 
     * This function recursively processes all .txt files in a directory and its subdirectories.
     * It first checks if the appropriate collection exists and creates it if needed.
     * Then it processes each file individually.
     * 
     * @param string $dirPath The directory path to process
     * @return array Result with status and details
     */
    public function processDirectory($dirPath) {
        // Check if directory exists
        if (!is_dir($dirPath)) {
            return [
                'status' => 'error',
                'message' => "Directory does not exist: $dirPath"
            ];
        }
        
        // Create RecursiveIteratorIterator to process directories recursively
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        $files = [];
        foreach ($iterator as $file) {
            // Process only .txt files that don't start with underscore
            if ($file->isFile() && $file->getExtension() === 'txt' && $file->getFilename()[0] !== '_') {
                $files[] = $file->getPathname();
            }
        }
        
        if (empty($files)) {
            return [
                'status' => 'skipped',
                'message' => "No .txt files found in directory: $dirPath"
            ];
        }
        
        // Use the first part of the document ID as collection name, fallback to 'documents'
        $sampleFile = $files[0];
        $id = parseFilePath($sampleFile);
        $idParts = explode(':', $id);
        $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
        
        try {
            $this->ensureCollectionExists($collectionName);
            $collectionChecked = true;
        } catch (Exception $e) {
            $collectionChecked = true;
        }
        
        $results = [];
        foreach ($files as $file) {
            $result = $this->processSingleFile($file, $collectionName, $collectionChecked);
            $results[] = [
                'file' => $file,
                'result' => $result
            ];
        }
        
        return [
            'status' => 'success',
            'message' => "Finished processing directory.",
            'files_count' => count($files),
            'results' => $results
        ];
    }
}

/**
 * Parse a file path and convert it to a DokuWiki ID
 * 
 * Takes a file system path and converts it to the DokuWiki ID format by:
 * 1. Removing the base path prefix (using DokuWiki's pages directory)
 * 2. Removing the .txt extension
 * 3. Converting directory separators to colons
 * 
 * Example: /var/www/html/dokuwiki/data/pages/reports/mri/2024/g287-name-surname.txt
 * Becomes: reports:mri:2024:g287-name-surname
 * 
 * @param string $filePath The full file path to parse
 * @return string The DokuWiki ID
 */
function parseFilePath($filePath) {
    // Use DokuWiki's constant to get the pages directory if available
    if (defined('DOKU_INC')) {
        $pagesDir = DOKU_INC . 'data/pages/';
    } else {
        // Fallback to common DokuWiki installation path
        $pagesDir = '/var/www/html/dokuwiki/data/pages/';
    }
        
    // Remove the base path
    $relativePath = str_replace($pagesDir, '', $filePath);
        
    // Remove .txt extension
    $relativePath = preg_replace('/\.txt$/', '', $relativePath);
        
    // Split path into parts and filter out empty parts
    $parts = array_filter(explode('/', $relativePath));
        
    // Build DokuWiki ID (use first part as namespace)
    $idParts = [];
    foreach ($parts as $part) {
        if (!empty($part)) {
            $idParts[] = $part;
        }
    }
        
    return implode(':', $idParts);
}

