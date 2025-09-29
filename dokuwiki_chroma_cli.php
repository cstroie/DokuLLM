<?php
require_once 'chromadb_client.php';

function showUsage() {
    echo "Usage: php dokuwiki_chroma_cli.php [action] [options]\n";
    echo "Actions:\n";
    echo "  send    Send a file to ChromaDB\n";
    echo "  query   Query ChromaDB\n";
    echo "\n";
    echo "Send a file:\n";
    echo "  php dokuwiki_chroma_cli.php send /path/to/file.txt\n";
    echo "\n";
    echo "Query ChromaDB:\n";
    echo "  php dokuwiki_chroma_cli.php query \"search terms\"\n";
    echo "  php dokuwiki_chroma_cli.php query \"search terms\" --limit 10\n";
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

function sendFile($filePath) {
    if (!file_exists($filePath)) {
        echo "Error: File does not exist: $filePath\n";
        exit(1);
    }
    
    // Parse file path to extract metadata
    $id = parseFilePath($filePath);
    
    // Read file content
    $content = file_get_contents($filePath);
    
    // Create ChromaDB client
    $chroma = new ChromaDBClient();
    
    // Extract modality from ID (second part after 'reports')
    $idParts = explode(':', $id);
    $modality = isset($idParts[1]) ? $idParts[1] : 'mri'; // Default to 'mri'
    
    try {
        // Create collection if it doesn't exist
        try {
            $chroma->getCollection($modality);
        } catch (Exception $e) {
            // Collection doesn't exist, create it
            $chroma->createCollection($modality);
        }
        
        // Send document to ChromaDB
        $result = $chroma->addDokuWikiDocument($modality, $id, $content);
        echo "Successfully sent file to ChromaDB:\n";
        echo "  ID: $id\n";
        echo "  Modality: $modality\n";
        echo "  File: $filePath\n";
    } catch (Exception $e) {
        echo "Error sending file to ChromaDB: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function queryChroma($searchTerms, $limit = 5) {
    // Create ChromaDB client
    $chroma = new ChromaDBClient();
    
    try {
        // For now, we'll query the 'mri' collection by default
        // In a more advanced version, we could query multiple collections
        $results = $chroma->queryCollection('mri', [$searchTerms], $limit);
        
        echo "Query results for: \"$searchTerms\"\n";
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

// Main script logic
if ($argc < 2) {
    showUsage();
}

$action = $argv[1];

switch ($action) {
    case 'send':
        if ($argc < 3) {
            echo "Error: Missing file path for send action\n";
            showUsage();
        }
        $filePath = $argv[2];
        sendFile($filePath);
        break;
        
    case 'query':
        if ($argc < 3) {
            echo "Error: Missing search terms for query action\n";
            showUsage();
        }
        $searchTerms = $argv[2];
        $limit = 5; // Default limit
        
        // Check for limit option
        for ($i = 3; $i < $argc; $i++) {
            if ($argv[$i] === '--limit' && isset($argv[$i + 1])) {
                $limit = (int)$argv[$i + 1];
                break;
            }
        }
        
        queryChroma($searchTerms, $limit);
        break;
        
    default:
        echo "Error: Unknown action '$action'\n";
        showUsage();
}
