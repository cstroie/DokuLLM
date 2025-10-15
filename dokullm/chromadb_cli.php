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
            // Don't exit, just return to continue if called from other contexts
            return;
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
 * @param ChromaDBClient $chroma The ChromaDB client instance
 * @param string $host ChromaDB server host
 * @param int $port ChromaDB server port
 * @param string $tenant ChromaDB tenant name
 * @param string $database ChromaDB database name
 * @param bool $collectionChecked Whether the collection has already been checked/created (optimization for batch processing)
 * @return void
 */
function processSingleFile($filePath, $chroma, $host, $port, $tenant, $database, $collectionChecked = false) {
    // Parse file path to extract metadata
    $id = parseFilePath($filePath);
        
    // Use the first part of the document ID as collection name, fallback to 'documents'
    $idParts = explode(':', $id);
    $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
        
    try {
        // Process the file using the class method
        $result = $chroma->processSingleFile($filePath, $collectionName, $collectionChecked);
        
        // Handle the result with verbose output
        if (!empty($result['collection_status'])) {
            echo $result['collection_status'] . "\n";
        }
        
        switch ($result['status']) {
            case 'success':
                echo "Adding " . $result['details']['chunks'] . " chunks to ChromaDB...\n";
                echo "Successfully sent file to ChromaDB:\n";
                echo "  Document ID: " . $result['details']['document_id'] . "\n";
                echo "  Chunks: " . $result['details']['chunks'] . "\n";
                echo "  Host: $host:$port\n";
                echo "  Tenant: $tenant\n";
                echo "  Database: $database\n";
                echo "  Collection: " . $result['details']['collection'] . "\n";
                break;
                
            case 'skipped':
                echo $result['message'] . "\n";
                break;
                
            case 'error':
                echo $result['message'] . "\n";
                break;
        }
    } catch (Exception $e) {
        echo "Error sending file to ChromaDB: " . $e->getMessage() . "\n";
        // Don't exit, just return to continue processing other files
        return;
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
    
    // Use the first part of the document ID as collection name, fallback to 'documents'
    $sampleFile = $files[0];
    $id = parseFilePath($sampleFile);
    $idParts = explode(':', $id);
    $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
    
    try {
        $status = $chroma->ensureCollectionExists($collectionName);
        echo $status . "\n";
        $collectionChecked = true;
    } catch (Exception $e) {
        echo "Error ensuring collection exists: " . $e->getMessage() . "\n";
        // Don't exit, just continue processing
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
function getDocument($documentId, $host, $port, $tenant, $database, $collection = null) {
    // If no collection specified, derive it from the first part of the document ID
    if (empty($collection)) {
        $idParts = explode(':', $documentId);
        $collection = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
    }
    
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
        // Don't exit, just return to continue if called from other contexts
        return;
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
        getDocument($args['query'], $args['host'], $args['port'], $args['tenant'], $args['database']);
        break;
        
    default:
        echo "Error: Unknown action '{$args['action']}'\n";
        showUsage();
}
