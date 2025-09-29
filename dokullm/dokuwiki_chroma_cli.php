<?php
require_once 'chromadb_client.php';

function showUsage() {
    echo "Usage: php dokuwiki_chroma_cli.php [options] [action]\n";
    echo "Actions:\n";
    echo "  send       Send a file or directory to ChromaDB\n";
    echo "  query      Query ChromaDB\n";
    echo "  heartbeat  Check if ChromaDB server is alive\n";
    echo "  identity   Get authentication and identity information\n";
    echo "  list       List all collections\n";
    echo "\n";
    echo "Options:\n";
    echo "  --host HOST        ChromaDB server host (default: 10.200.8.16)\n";
    echo "  --port PORT        ChromaDB server port (default: 8087)\n";
    echo "  --tenant TENANT    ChromaDB tenant (default: default_tenant)\n";
    echo "  --database DB      ChromaDB database (default: default_database)\n";
    echo "\n";
    echo "Send a file:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] send /path/to/file.txt\n";
    echo "\n";
    echo "Send all files in a directory:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] send /path/to/directory\n";
    echo "\n";
    echo "Query ChromaDB:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] [--limit 10] query \"search terms\"\n";
    echo "\n";
    echo "Check server status:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] heartbeat\n";
    echo "\n";
    echo "Check identity:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] identity\n";
    echo "\n";
    echo "List collections:\n";
    echo "  php dokuwiki_chroma_cli.php [--host HOST] [--port PORT] [--tenant TENANT] [--database DB] list\n";
    exit(1);
}

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
        
        processSingleFile($path, $chroma, $host, $port, $tenant, $database);
    }
}

function processSingleFile($filePath, $chroma, $host, $port, $tenant, $database, $collectionChecked = false) {
    // Parse file path to extract metadata
    $id = parseFilePath($filePath);
    
    // Read file content
    $content = file_get_contents($filePath);
    
    // Extract modality from ID (second part after 'reports')
    $idParts = explode(':', $id);
    $modality = isset($idParts[1]) ? $idParts[1] : 'other';
    
    try {
        // Create collection if it doesn't exist (only if not already checked)
        if (!$collectionChecked) {
            try {
                echo "Checking if collection '$modality' exists...\n";
                $collection = $chroma->getCollection($modality);
                echo "Collection '$modality' already exists.\n";
            } catch (Exception $e) {
                // Collection doesn't exist, create it
                echo "Creating collection '$modality'...\n";
                $created = $chroma->createCollection($modality);
                echo "Collection created.\n";
            }
        }
        
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
        
        // Extract modality from the second part
        if (isset($parts[1])) {
            $baseMetadata['modality'] = $parts[1];
        }
        
        // Handle different ID formats
        if (count($parts) == 5) {
            // Format: reports:mri:institution:250620-name-surname
            // Extract institution from the third part
            if (isset($parts[2])) {
                $baseMetadata['institution'] = $parts[2];
            }
            
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
        } else if (count($parts) == 4) {
            // Format: reports:mri:2024:g287-name-surname
            // Extract year from the third part
            if (isset($parts[2])) {
                $baseMetadata['year'] = $parts[2];
            }
            
            // Set default institution
            $baseMetadata['institution'] = 'scuc';
            
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
            echo "Generating embeddings for chunk " . ($index + 1) . "...\n";
            $embeddings = $chroma->generateEmbeddings($paragraph);
            
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
        echo "Adding " . count($chunkIds) . " chunks to ChromaDB...\n";
        $result = $chroma->addDocuments($modality, $chunkContents, $chunkIds, $chunkMetadatas, $chunkEmbeddings);
        echo "Successfully sent file to ChromaDB:\n";
        echo "  Original ID: $id\n";
        echo "  Chunks: " . count($chunkIds) . "\n";
        echo "  Modality: $modality\n";
        echo "  File: $filePath\n";
        echo "  Host: $host:$port\n";
        echo "  Tenant: $tenant\n";
        echo "  Database: $database\n";
    } catch (Exception $e) {
        echo "Error sending file to ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
    }
}

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
        // Process only .txt files
        if ($file->isFile() && $file->getExtension() === 'txt') {
            $files[] = $file->getPathname();
        }
    }
    
    if (empty($files)) {
        echo "No .txt files found in directory: $dirPath\n";
        return;
    }
    
    echo "Found " . count($files) . " files to process.\n";
    
    // Check if collection exists once for the directory
    $sampleFile = $files[0];
    $id = parseFilePath($sampleFile);
    $idParts = explode(':', $id);
    $modality = isset($idParts[1]) ? $idParts[1] : 'mri'; // Default to 'mri'
    
    try {
        echo "Checking if collection '$modality' exists...\n";
        $collection = $chroma->getCollection($modality);
        echo "Collection '$modality' already exists.\n";
        $collectionChecked = true;
    } catch (Exception $e) {
        // Collection doesn't exist, create it
        echo "Creating collection '$modality'...\n";
        $created = $chroma->createCollection($modality);
        echo "Collection created.\n";
        $collectionChecked = true;
    }
    
    foreach ($files as $file) {
        echo "\nProcessing file: $file\n";
        processSingleFile($file, $chroma, $host, $port, $tenant, $database, $collectionChecked);
    }
    
    echo "\nFinished processing directory.\n";
}

function queryChroma($searchTerms, $limit, $host, $port, $tenant, $database) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    try {
        // For now, we'll query the 'mri' collection by default
        // In a more advanced version, we could query multiple collections
        $results = $chroma->queryCollection('mri', [$searchTerms], $limit);
        
        echo "Query results for: \"$searchTerms\"\n";
        echo "Host: $host:$port\n";
        echo "Tenant: $tenant\n";
        echo "Database: $database\n";
        echo "==========================================\n";
        
        if (empty($results['ids'][0])) {
            echo "No results found.\n";
            return;
        }
        
        for ($i = 0; $i < count($results['ids'][0]); $i++) {
            echo "Result " . ($i + 1) . ":\n";
            echo "  ID: " . $results['ids'][0][$i] . "\n";
            echo "  Distance: " . $results['distances'][0][$i] . "\n";
            echo "  Document: " . substr($results['documents'][0][$i], 0, 100) . "...\n";
            
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

// Parse command line arguments
function parseArgs($argv) {
    $args = [
        'action' => null,
        'filepath' => null,
        'query' => null,
        'limit' => 5,
        'host' => '10.200.8.16',
        'port' => 8087,
        'tenant' => 'default_tenant',
        'database' => 'default_database'
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
        }
    }
    
    return $args;
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
        queryChroma($args['query'], $args['limit'], $args['host'], $args['port'], $args['tenant'], $args['database']);
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
        
    default:
        echo "Error: Unknown action '{$args['action']}'\n";
        showUsage();
}
