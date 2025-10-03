<?php
require_once 'config.php';
require_once 'chromadb_client.php';

/**
 * Display usage information for the CLI tool
 * 
 * Shows all available actions, options, and examples of how to use the tool.
 * This function is called when no arguments are provided or when invalid arguments are given.
 * 
 * @return void
 */
function showUsage() {
    echo "Usage: php dokuwiki_chroma_cli.php [options] [action]\n";
    echo "Actions:\n";
    echo "  send       Send a file or directory to ChromaDB\n";
    echo "  query      Query ChromaDB\n";
    echo "  heartbeat  Check if ChromaDB server is alive\n";
    echo "  identity   Get authentication and identity information\n";
    echo "  list       List all collections\n";
    echo "  get        Get a document by its ID\n";
    echo "\n";
    echo "Options:\n";
    echo "  --host HOST        ChromaDB server host (default: " . CHROMA_HOST . ")\n";
    echo "  --port PORT        ChromaDB server port (default: " . CHROMA_PORT . ")\n";
    echo "  --tenant TENANT    ChromaDB tenant (default: " . CHROMA_TENANT . ")\n";
    echo "  --database DB      ChromaDB database (default: " . CHROMA_DATABASE . ")\n";
    echo "  --collection COLL  Collection name to query (default: documents)\n";
    echo "  --limit NUM        Number of results to return (default: 5)\n";
    echo "\n";
    echo "Send a file:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] send /path/to/file.txt\n";
    echo "\n";
    echo "Send all files in a directory:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] send /path/to/directory\n";
    echo "\n";
    echo "Query ChromaDB:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] [--collection COLL] [--limit 10] query \"search terms\"\n";
    echo "\n";
    echo "Check server status:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] heartbeat\n";
    echo "\n";
    echo "Check identity:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] identity\n";
    echo "\n";
    echo "List collections:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] list\n";
    echo "\n";
    echo "Get a document:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] [--collection COLL] get \"document_id\"\n";
    exit(1);
}

/**
 * Parse a file path and convert it to a DokuWiki ID
 * 
 * Takes a file system path and converts it to the DokuWiki ID format by:
 * 1. Removing the base path prefix
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
    // Remove the base path
    $relativePath = str_replace('/var/www/html/dokuwiki/data/pages/reports/', '', $filePath);
    
    // Remove .txt extension
    $relativePath = preg_replace('/\.txt$/', '', $relativePath);
    
    // Split path into parts and filter out empty parts
    $parts = array_filter(explode('/', $relativePath));
    
    // Build DokuWiki ID (reports:modality:...)
    $idParts = ['reports'];
    foreach ($parts as $part) {
        if (!empty($part)) {
            $idParts[] = $part;
        }
    }
    
    return implode(':', $idParts);
}

/**
 * Send a file or directory of files to ChromaDB
 * 
 * This function determines if the provided path is a file or directory and processes
 * it accordingly. For directories, it processes all .txt files recursively.
 * 
 * @param string $path The file or directory path to process
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @return void
 */
function sendFile($path, $host, $port, $tenant, $database) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    if (is_dir($path)) {
        // Process directory
        echo "Processing directory: $path\n";
        processDirectory($path, $chroma, $host, $port, $tenant, $database);
    } else {
        // Process single file
        if (!file_exists($path)) {
            echo "Error: File does not exist: $path\n";
            exit(1);
        }
        
        // Skip files that start with underscore
        $filename = basename($path);
        if ($filename[0] === '_') {
            echo "Skipping file (starts with underscore): $path\n";
            return;
        }
        
        processSingleFile($path, $chroma, $host, $port, $tenant, $database);
    }
}

/**
 * Process a single DokuWiki file and send it to ChromaDB
 * 
 * This function handles the complete processing of a single DokuWiki file:
 * 1. Parses the file path to extract metadata
 * 2. Reads the file content
 * 3. Ensures the appropriate collection exists
 * 4. Splits the document into chunks (paragraphs)
 * 5. Extracts metadata from the DokuWiki ID format
 * 6. Generates embeddings for each chunk
 * 7. Sends all chunks to ChromaDB
 * 
 * Two ID formats are supported:
 * - Format 1: reports:mri:institution:250620-name-surname (third part is institution name)
 * - Format 2: reports:mri:2024:g287-name-surname (third part is year)
 * 
 * @param string $filePath The path to the file to process
 * @param ChromaDBClient $chroma The ChromaDB client instance
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @param bool $collectionChecked Whether the collection has already been checked/created
 * @return void
 */
function processSingleFile($filePath, $chroma, $host, $port, $tenant, $database, $collectionChecked = false) {
    // Parse file path to extract metadata
    $id = parseFilePath($filePath);
        
    // Use the first part of the document ID as collection name, fallback to 'reports'
    $idParts = explode(':', $id);
    $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'reports';
        
    try {
        // Create collection if it doesn't exist (only if not already checked)
        if (!$collectionChecked) {
            try {
                echo "Checking if collection '$collectionName' exists...\n";
                $collection = $chroma->getCollection($collectionName);
                echo "Collection '$collectionName' already exists.\n";
            } catch (Exception $e) {
                // Collection doesn't exist, create it
                echo "Creating collection '$collectionName'...\n";
                $created = $chroma->createCollection($collectionName);
                echo "Collection created.\n";
            }
        }
            
        // Get file modification time
        $fileModifiedTime = filemtime($filePath);
        
        // Get collection ID
        $collection = $chroma->getCollection($collectionName);
        if (!isset($collection['id'])) {
            throw new Exception("Collection ID not found for '{$collectionName}'");
        }
        $collectionId = $collection['id'];
        
        // Check if document needs update
        $needsUpdate = $chroma->needsUpdate($collectionId, $id, $fileModifiedTime);
            
        // If document is up to date, skip processing
        if (!$needsUpdate) {
            echo "Document '$id' is up to date in collection '$collectionName'. Skipping...\n";
            return;
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
        
        // Process each paragraph as a chunk
        $chunkIds = [];
        $chunkContents = [];
        $chunkMetadatas = [];
        $chunkEmbeddings = [];
        $currentTags = [];
        
        foreach ($paragraphs as $index => $paragraph) {
            // Skip empty paragraphs
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }
            
            // Check if this is a DokuWiki title (starts and ends with =)
            if (preg_match('/^=+(.*?)=+$/', $paragraph, $matches)) {
                // Extract title content
                $titleContent = trim($matches[1]);
                
                // Split into words and create tags
                $words = preg_split('/\s+/', $titleContent);
                $tags = [];
                
                foreach ($words as $word) {
                    // Only use words longer than 3 characters
                    if (strlen($word) >= 3) {
                        $tags[] = strtolower($word);
                    }
                }
                
                // Remove duplicate tags
                $currentTags = array_unique($tags);
                continue; // Skip storing title chunks
            }
            
            // Create chunk ID
            $chunkId = $id . '@' . ($index + 1);
            
            // Generate embeddings for the chunk
            echo "Generating embeddings for chunk " . ($index + 1) . "...\n";
            $embeddings = $chroma->generateEmbeddings($paragraph);
            
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
            echo "No valid chunks found in file '$id'. Skipping...\n";
            return;
        }
        
        // Send all chunks to ChromaDB
        echo "Adding " . count($chunkIds) . " chunks to ChromaDB...\n";
        $result = $chroma->addDocuments($collectionName, $chunkContents, $chunkIds, $chunkMetadatas, $chunkEmbeddings);
        echo "Successfully sent file to ChromaDB:\n";
        echo "  Document ID: $id\n";
        echo "  Chunks: " . count($chunkIds) . "\n";
        echo "  Host: $host:$port\n";
        echo "  Tenant: $tenant\n";
        echo "  Database: $database\n";
        echo "  Collection: $collectionName\n";
    } catch (Exception $e) {
        echo "Error sending file to ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
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
 * @param ChromaDBClient $chroma The ChromaDB client instance
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @return void
 */
function processDirectory($dirPath, $chroma, $host, $port, $tenant, $database) {
    // Check if directory exists
    if (!is_dir($dirPath)) {
        echo "Error: Directory does not exist: $dirPath\n";
        exit(1);
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
        echo "No .txt files found in directory: $dirPath\n";
        return;
    }
    
    echo "Found " . count($files) . " files to process.\n";
    
    // Use the first part of the document ID as collection name, fallback to 'reports'
    $sampleFile = $files[0];
    $id = parseFilePath($sampleFile);
    $idParts = explode(':', $id);
    $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'reports';
    
    try {
        echo "Checking if collection '$collectionName' exists...\n";
        $collection = $chroma->getCollection($collectionName);
        echo "Collection '$collectionName' already exists.\n";
        $collectionChecked = true;
    } catch (Exception $e) {
        // Collection doesn't exist, create it
        echo "Creating collection '$collectionName'...\n";
        $created = $chroma->createCollection($collectionName);
        echo "Collection created.\n";
        $collectionChecked = true;
    }
    
    foreach ($files as $file) {
        echo "\nProcessing file: $file\n";
        processSingleFile($file, $chroma, $host, $port, $tenant, $database, $collectionChecked);
    }
    
    echo "\nFinished processing directory.\n";
}

/**
 * Query ChromaDB for similar documents
 * 
 * This function queries the specified collection in ChromaDB for documents
 * similar to the provided search terms. It displays the results including
 * document IDs, distances, and metadata.
 * 
 * @param string $searchTerms The search terms to query for
 * @param int $limit The maximum number of results to return
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @param string $collection The collection to query (default: 'documents')
 * @return void
 */
function queryChroma($searchTerms, $limit, $host, $port, $tenant, $database, $collection = 'documents') {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        // Query the specified collection by collection
        $results = $chroma->queryCollection($collection, [$searchTerms], $limit);
        
        echo "Query results for: \"$searchTerms\"\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "Collection: $collection\n";
        echo "==========================================\n";
        
        if (empty($results['ids'][0])) {
            echo "No results found.\n";
            return;
        }
        
        for ($i = 0; $i < count($results['ids'][0]); $i++) {
            echo "Result " . ($i + 1) . ":\n";
            echo "  ID: " . $results['ids'][0][$i] . "\n";
            echo "  Distance: " . $results['distances'][0][$i] . "\n";
            echo "  Document: " . substr($results['documents'][0][$i], 0, 255) . "...\n";
            
            if (isset($results['metadatas'][0][$i])) {
                echo "  Metadata: " . json_encode($results['metadatas'][0][$i]) . "\n";
            }
            echo "\n";
        }
    } catch (Exception $e) {
        echo "Error querying ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Check if the ChromaDB server is alive
 * 
 * This function sends a heartbeat request to the ChromaDB server to verify
 * that it's running and accessible.
 * 
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @return void
 */
function checkHeartbeat($host, $port, $tenant, $database) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        echo "Checking ChromaDB server status...\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "==========================================\n";
        
        $result = $chroma->heartbeat();
        
        echo "Server is alive!\n";
        echo "Response: " . json_encode($result) . "\n";
    } catch (Exception $e) {
        echo "Error checking ChromaDB server status: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Get authentication and identity information from ChromaDB
 * 
 * This function retrieves authentication and identity information from the
 * ChromaDB server, which can be useful for debugging connection issues.
 * 
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @return void
 */
function checkIdentity($host, $port, $tenant, $database) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        echo "Checking ChromaDB identity...\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "==========================================\n";
        
        $result = $chroma->getIdentity();
        
        echo "Identity information:\n";
        echo "Response: " . json_encode($result, JSON_PRETTY_PRINT) . "\n";
    } catch (Exception $e) {
        echo "Error checking ChromaDB identity: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * List all collections in the ChromaDB database
 * 
 * This function retrieves and displays a list of all collections in the
 * specified ChromaDB database.
 * 
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @return void
 */
function listCollections($host, $port, $tenant, $database) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        echo "Listing ChromaDB collections...\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "==========================================\n";
        
        $result = $chroma->listCollections();
        
        if (empty($result)) {
            echo "No collections found.\n";
            return;
        }
        
        echo "Collections:\n";
        foreach ($result as $collection) {
            echo "  - " . (isset($collection['name']) ? $collection['name'] : json_encode($collection)) . "\n";
        }
    } catch (Exception $e) {
        echo "Error listing ChromaDB collections: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Parse command line arguments
 * 
 * This function parses the command line arguments provided to the script,
 * extracting options and action parameters. It handles both global options
 * (like host, port, tenant, database) and action-specific arguments.
 * 
 * @param array $argv The command line arguments array
 * @return array The parsed arguments
 */
function parseArgs($argv) {
    $args = [
        'action' => null,
        'filepath' => null,
        'query' => null,
        'limit' => 5,
        'collection' => 'documents',
        'host' => CHROMA_HOST,
        'port' => CHROMA_PORT,
        'tenant' => CHROMA_TENANT,
        'database' => CHROMA_DATABASE
    ];
    
    if (count($argv) < 2) {
        showUsage();
    }
    
    // Parse options first (before action)
    $i = 1;
    while ($i < count($argv)) {
        switch ($argv[$i]) {
            case '--limit':
                if (isset($argv[$i + 1])) {
                    $args['limit'] = (int)$argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            case '--collection':
                if (isset($argv[$i + 1])) {
                    $args['collection'] = $argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            case '--host':
                if (isset($argv[$i + 1])) {
                    $args['host'] = $argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            case '--port':
                if (isset($argv[$i + 1])) {
                    $args['port'] = (int)$argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            case '--tenant':
                if (isset($argv[$i + 1])) {
                    $args['tenant'] = $argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            case '--database':
                if (isset($argv[$i + 1])) {
                    $args['database'] = $argv[$i + 1];
                    $i += 2;
                } else {
                    $i++;
                }
                break;
            default:
                // If it's not an option, it must be the action
                $args['action'] = $argv[$i];
                $i++;
                break 2; // Break out of the while loop
        }
    }
    
    // Parse remaining arguments based on action
    for (; $i < count($argv); $i++) {
        // Handle positional arguments based on action
        if ($args['action'] === 'send' && !$args['filepath']) {
            $args['filepath'] = $argv[$i];
        } else if ($args['action'] === 'query' && !$args['query']) {
            $args['query'] = $argv[$i];
        } else if ($args['action'] === 'get' && !$args['query']) {
            $args['query'] = $argv[$i];
        }
    }
    
    return $args;
}

/**
 * Get a document by its ID from ChromaDB
 * 
 * This function retrieves a document from the specified collection in ChromaDB
 * using its ID. It displays the document content and metadata.
 * 
 * @param string $documentId The document ID to retrieve
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @param string $collection The collection to query (default: 'documents')
 * @return void
 */
function getDocument($documentId, $host, $port, $tenant, $database, $collection = 'documents') {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        // Get the specified document by ID
        $results = $chroma->getDocument($collection, $documentId);
        
        echo "Document retrieval results for: \"$documentId\"\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "Collection: $collection\n";
        echo "==========================================\n";
        
        if (empty($results['ids'])) {
            echo "No document found with ID: $documentId\n";
            return;
        }
        
        for ($i = 0; $i < count($results['ids']); $i++) {
            echo "Document " . ($i + 1) . ":\n";
            echo "  ID: " . $results['ids'][$i] . "\n";
            
            if (isset($results['documents'][$i])) {
                echo "  Content: " . $results['documents'][$i] . "\n";
            }
            
            if (isset($results['metadatas'][$i])) {
                echo "  Metadata: " . json_encode($results['metadatas'][$i], JSON_PRETTY_PRINT) . "\n";
            }
            echo "\n";
        }
    } catch (Exception $e) {
        echo "Error retrieving document from ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Main script logic
$args = parseArgs($argv);

switch ($args['action']) {
    case 'send':
        if (!$args['filepath']) {
            echo "Error: Missing file path for send action\n";
            showUsage();
        }
        sendFile($args['filepath'], $args['host'], $args['port'], $args['tenant'], $args['database']);
        break;
        
    case 'query':
        if (!$args['query']) {
            echo "Error: Missing search terms for query action\n";
            showUsage();
        }
        queryChroma($args['query'], $args['limit'], $args['host'], $args['port'], $args['tenant'], $args['database'], $args['collection']);
        break;
        
    case 'heartbeat':
        checkHeartbeat($args['host'], $args['port'], $args['tenant'], $args['database']);
        break;
        
    case 'identity':
        checkIdentity($args['host'], $args['port'], $args['tenant'], $args['database']);
        break;
        
    case 'list':
        listCollections($args['host'], $args['port'], $args['tenant'], $args['database']);
        break;
        
    case 'get':
        if (!$args['query']) {
            echo "Error: Missing document ID for get action\n";
            showUsage();
        }
        getDocument($args['query'], $args['host'], $args['port'], $args['tenant'], $args['database'], $args['collection']);
        break;
        
    default:
        echo "Error: Unknown action '{$args['action']}'\n";
        showUsage();
}
