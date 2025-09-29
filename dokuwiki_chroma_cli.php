<?php
require_once 'chromadb_client.php';

function showUsage() {
    echo "Usage: php dokuwiki_chroma_cli.php [action] [options]\n";
    echo "Actions:\n";
    echo "  send      Send a file to ChromaDB\n";
    echo "  query     Query ChromaDB\n";
    echo "  heartbeat Check if ChromaDB server is alive\n";
    echo "  identity  Get authentication and identity information\n";
    echo "\n";
    echo "Options:\n";
    echo "  --host HOST        ChromaDB server host (default: 10.200.8.16)\n";
    echo "  --port PORT        ChromaDB server port (default: 8087)\n";
    echo "  --tenant TENANT    ChromaDB tenant (default: default_tenant)\n";
    echo "  --database DB      ChromaDB database (default: default_database)\n";
    echo "\n";
    echo "Send a file:\n";
    echo "  php dokuwiki_chroma_cli.php send /path/to/file.txt [--host HOST] [--port PORT] [--tenant TENANT] [--database DB]\n";
    echo "\n";
    echo "Query ChromaDB:\n";
    echo "  php dokuwiki_chroma_cli.php query \"search terms\" [--limit 10] [--host HOST] [--port PORT] [--tenant TENANT] [--database DB]\n";
    echo "\n";
    echo "Check server status:\n";
    echo "  php dokuwiki_chroma_cli.php heartbeat [--host HOST] [--port PORT] [--tenant TENANT] [--database DB]\n";
    echo "\n";
    echo "Check identity:\n";
    echo "  php dokuwiki_chroma_cli.php identity [--host HOST] [--port PORT] [--tenant TENANT] [--database DB]\n";
    exit(1);
}

function parseFilePath($filePath) {
    // Remove the base path
    $relativePath = str_replace('/var/www/html/dokuwiki/data/pages/reports/', '', $filePath);
    
    // Remove .txt extension
    $relativePath = preg_replace('/\.txt$/', '', $relativePath);
    
    // Split path into parts
    $parts = explode('/', $relativePath);
    
    // Build DokuWiki ID (reports:modality:...)
    $idParts = ['reports'];
    foreach ($parts as $part) {
        $idParts[] = $part;
    }
    
    return implode(':', $idParts);
}

function sendFile($filePath, $host, $port, $tenant, $database) {
    if (!file_exists($filePath)) {
        echo "Error: File does not exist: $filePath\n";
        exit(1);
    }
    
    // Parse file path to extract metadata
    $id = parseFilePath($filePath);
    
    // Read file content
    $content = file_get_contents($filePath);
    
    // Create ChromaDB client
    $chroma = new ChromaDBClient($host, $port, $tenant, $database);
    
    // Extract modality from ID (second part after 'reports')
    $idParts = explode(':', $id);
    $modality = isset($idParts[1]) ? $idParts[1] : 'mri'; // Default to 'mri'
    
    try {
        // Create collection if it doesn't exist
        try {
            echo "Checking if collection '$modality' exists...\n";
            $collection = $chroma->getCollection($modality);
            echo "Collection '$modality' already exists.\n";
            echo "Collection details: " . json_encode($collection) . "\n";
        } catch (Exception $e) {
            // Collection doesn't exist, create it
            echo "Creating collection '$modality'...\n";
            $created = $chroma->createCollection($modality);
            echo "Collection created: " . json_encode($created) . "\n";
        }
        
        // Send document to ChromaDB
        echo "Adding document with ID: $id\n";
        $result = $chroma->addDokuWikiDocument($modality, $id, $content);
        echo "Successfully sent file to ChromaDB:\n";
        echo "  ID: $id\n";
        echo "  Modality: $modality\n";
        echo "  File: $filePath\n";
        echo "  Host: $host:$port\n";
        echo "  Tenant: $tenant\n";
        echo "  Database: $database\n";
        echo "  Result: " . json_encode($result) . "\n";
    } catch (Exception $e) {
        echo "Error sending file to ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
    }
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
    
    $args['action'] = $argv[1];
    
    // Parse options
    for ($i = 2; $i < count($argv); $i++) {
        switch ($argv[$i]) {
            case '--limit':
                if (isset($argv[$i + 1])) {
                    $args['limit'] = (int)$argv[$i + 1];
                    $i++; // Skip next argument
                }
                break;
            case '--host':
                if (isset($argv[$i + 1])) {
                    $args['host'] = $argv[$i + 1];
                    $i++; // Skip next argument
                }
                break;
            case '--port':
                if (isset($argv[$i + 1])) {
                    $args['port'] = (int)$argv[$i + 1];
                    $i++; // Skip next argument
                }
                break;
            case '--tenant':
                if (isset($argv[$i + 1])) {
                    $args['tenant'] = $argv[$i + 1];
                    $i++; // Skip next argument
                }
                break;
            case '--database':
                if (isset($argv[$i + 1])) {
                    $args['database'] = $argv[$i + 1];
                    $i++; // Skip next argument
                }
                break;
            default:
                // Handle positional arguments based on action
                if ($args['action'] === 'send' && !$args['filepath']) {
                    $args['filepath'] = $argv[$i];
                } else if ($args['action'] === 'query' && !$args['query']) {
                    $args['query'] = $argv[$i];
                }
                break;
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
        
    default:
        echo "Error: Unknown action '{$args['action']}'\n";
        showUsage();
}
