<?php

use dokuwiki\Extension\CLIPlugin;
use splitbrain\phpcli\Options;

if(!defined('DOKU_INC')) define('DOKU_INC', realpath(dirname(__FILE__) . '/../../../') . '/');

/**
 * DokuWiki CLI plugin for ChromaDB operations
 */
class cli_plugin_dokullm extends CLIPlugin {

    /**
     * Register options and arguments
     * 
     * @param Options $options
     */
    protected function setup(Options $options) {
        // Set help text
        $options->setHelp(
            "ChromaDB CLI plugin for DokuLLM\n\n" .
            "Usage: ./bin/plugin.php dokullm [action] [options]\n\n" .
            "Actions:\n" .
            "  send       Send a file or directory to ChromaDB\n" .
            "  query      Query ChromaDB\n" .
            "  heartbeat  Check if ChromaDB server is alive\n" .
            "  identity   Get authentication and identity information\n" .
            "  list       List all collections\n" .
            "  get        Get a document by its ID\n"
        );

        // Global options
        $options->registerOption('host', 'ChromaDB server host', 'h', 'host');
        $options->registerOption('port', 'ChromaDB server port', 'p', 'port');
        $options->registerOption('tenant', 'ChromaDB tenant', null, 'tenant');
        $options->registerOption('database', 'ChromaDB database', null, 'database');
        $options->registerOption('ollama-host', 'Ollama server host', null, 'ollama-host');
        $options->registerOption('ollama-port', 'Ollama server port', null, 'ollama-port');
        $options->registerOption('ollama-model', 'Ollama embeddings model', null, 'ollama-model');
        $options->registerOption('verbose', 'Enable verbose output', 'v');

        // Action-specific options
        $options->registerCommand('send', 'Send a file or directory to ChromaDB');
        $options->registerArgument('path', 'File or directory path', true, 'send');

        $options->registerCommand('query', 'Query ChromaDB');
        $options->registerOption('collection', 'Collection name to query', 'c', 'collection', 'documents', 'query');
        $options->registerOption('limit', 'Number of results to return', 'l', 'limit', '5', 'query');
        $options->registerArgument('search', 'Search terms', true, 'query');

        $options->registerCommand('heartbeat', 'Check if ChromaDB server is alive');

        $options->registerCommand('identity', 'Get authentication and identity information');

        $options->registerCommand('list', 'List all collections');

        $options->registerCommand('get', 'Get a document by its ID');
        $options->registerOption('collection', 'Collection name', 'c', 'collection', 'documents', 'get');
        $options->registerArgument('id', 'Document ID', true, 'get');
    }

    /**
     * Main plugin logic
     * 
     * @param Options $options
     */
    protected function main(Options $options) {
        // Include the ChromaDBClient class
        require_once dirname(__FILE__) . '/ChromaDBClient.php';

        // Get global options with defaults
        $host = $options->getOpt('host', 'localhost');
        $port = (int)$options->getOpt('port', 8000);
        $tenant = $options->getOpt('tenant', 'default_tenant');
        $database = $options->getOpt('database', 'default_database');
        $ollamaHost = $options->getOpt('ollama-host', 'localhost');
        $ollamaPort = (int)$options->getOpt('ollama-port', 11434);
        $ollamaModel = $options->getOpt('ollama-model', 'nomic-embed-text');
        $verbose = $options->getOpt('verbose');
        
        $action = $options->getCmd();

        switch ($action) {
            case 'send':
                $path = $options->getArgs()[0] ?? null;
                if (!$path) {
                    $this->fatal('Missing file path for send action');
                }
                $this->sendFile($path, $host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            case 'query':
                $searchTerms = $options->getArgs()[0] ?? null;
                if (!$searchTerms) {
                    $this->fatal('Missing search terms for query action');
                }
                $collection = $options->getOpt('collection', 'documents');
                $limit = (int)$options->getOpt('limit', 5);
                $this->queryChroma($searchTerms, $limit, $host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            case 'heartbeat':
                $this->checkHeartbeat($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            case 'identity':
                $this->checkIdentity($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            case 'list':
                $this->listCollections($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            case 'get':
                $documentId = $options->getArgs()[0] ?? null;
                if (!$documentId) {
                    $this->fatal('Missing document ID for get action');
                }
                $collection = $options->getOpt('collection', null);
                $this->getDocument($documentId, $host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel, $verbose);
                break;

            default:
                echo $options->help();
                exit(1);
        }
    }

    /**
     * Send a file or directory of files to ChromaDB
     */
    private function sendFile($path, $host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, 'documents', $ollamaHost, $ollamaPort, $ollamaModel);
        
        if (is_dir($path)) {
            // Process directory
            $this->processDirectory($path, $chroma, $host, $port, $tenant, $database, $verbose);
        } else {
            // Process single file
            if (!file_exists($path)) {
                $this->error("File does not exist: $path");
                return;
            }
            
            // Skip files that start with underscore
            $filename = basename($path);
            if ($filename[0] === '_') {
                if ($verbose) {
                    $this->info("Skipping file (starts with underscore): $path");
                }
                return;
            }
            
            $this->processSingleFile($path, $chroma, $host, $port, $tenant, $database, false, $verbose);
        }
    }

    /**
     * Process a single DokuWiki file and send it to ChromaDB
     */
    private function processSingleFile($filePath, $chroma, $host, $port, $tenant, $database, $collectionChecked = false, $verbose = false) {
        // Parse file path to extract metadata
        $id = \dokuwiki\plugin\dokullm\parseFilePath($filePath);
            
        // Use the first part of the document ID as collection name, fallback to 'documents'
        $idParts = explode(':', $id);
        $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
        
        // Clean the ID and check ACL
        $cleanId = cleanID($id);
        if (auth_quickaclcheck($cleanId) < AUTH_READ) {
            $this->error("You are not allowed to read this file: $id");
            return;
        }
            
        try {
            // Process the file using the class method
            $result = $chroma->processSingleFile($filePath, $collectionName, $collectionChecked);
            
            // Handle the result with verbose output
            if ($verbose && !empty($result['collection_status'])) {
                $this->info($result['collection_status']);
            }
            
            switch ($result['status']) {
                case 'success':
                    if ($verbose) {
                        $this->info("Adding " . $result['details']['chunks'] . " chunks to ChromaDB...");
                    }
                    $this->success("Successfully sent file to ChromaDB:");
                    $this->info("  Document ID: " . $result['details']['document_id']);
                    if ($verbose) {
                        $this->info("  Chunks: " . $result['details']['chunks']);
                        $this->info("  Host: $host:$port");
                        $this->info("  Tenant: $tenant");
                        $this->info("  Database: $database");
                        $this->info("  Collection: " . $result['details']['collection']);
                    }
                    break;
                    
                case 'skipped':
                    if ($verbose) {
                        $this->info($result['message']);
                    }
                    break;
                    
                case 'error':
                    $this->error($result['message']);
                    break;
            }
        } catch (Exception $e) {
            $this->error("Error sending file to ChromaDB: " . $e->getMessage());
            return;
        }
    }

    /**
     * Process all DokuWiki files in a directory and send them to ChromaDB
     */
    private function processDirectory($dirPath, $chroma, $host, $port, $tenant, $database, $verbose = false) {
        if ($verbose) {
            $this->info("Processing directory: $dirPath");
        }
        
        // Check if directory exists
        if (!is_dir($dirPath)) {
            $this->error("Directory does not exist: $dirPath");
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
        
        // Skip if no files
        if (empty($files)) {
            if ($verbose) {
                $this->info("No .txt files found in directory: $dirPath");
            }
            return;
        }
        
        if ($verbose) {
            $this->info("Found " . count($files) . " files to process.");
        }
        
        // Use the first part of the document ID as collection name, fallback to 'documents'
        $sampleFile = $files[0];
        $id = \dokuwiki\plugin\dokullm\parseFilePath($sampleFile);
        $idParts = explode(':', $id);
        $collectionName = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
        
        try {
            $collectionStatus = $chroma->ensureCollectionExists($collectionName);
            if ($verbose) {
                $this->info($collectionStatus);
            }
            $collectionChecked = true;
        } catch (Exception $e) {
            $collectionChecked = true;
        }
        
        // Process each file
        $processedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;
        
        foreach ($files as $file) {
            if ($verbose) {
                $this->info("\nProcessing file: $file");
            }
            
            try {
                $result = $chroma->processSingleFile($file, $collectionName, $collectionChecked);
                
                // Handle the result with verbose output
                if ($verbose && !empty($result['collection_status'])) {
                    $this->info($result['collection_status']);
                }
                
                switch ($result['status']) {
                    case 'success':
                        $processedCount++;
                        if ($verbose) {
                            $this->info("Adding " . $result['details']['chunks'] . " chunks to ChromaDB...");
                        }
                        $this->success("Successfully sent file to ChromaDB:");
                        $this->info("  Document ID: " . $result['details']['document_id']);
                        if ($verbose) {
                            $this->info("  Chunks: " . $result['details']['chunks']);
                            $this->info("  Host: $host:$port");
                            $this->info("  Tenant: $tenant");
                            $this->info("  Database: $database");
                            $this->info("  Collection: " . $result['details']['collection']);
                        }
                        break;
                        
                    case 'skipped':
                        $skippedCount++;
                        if ($verbose) {
                            $this->info($result['message']);
                        }
                        break;
                        
                    case 'error':
                        $errorCount++;
                        $this->error($result['message']);
                        break;
                }
            } catch (Exception $e) {
                $errorCount++;
                $this->error("Error processing file $file: " . $e->getMessage());
            }
        }
        
        if ($verbose) {
            $this->info("\nFinished processing directory.");
            $this->info("Processing summary:");
            $this->info("  Processed: $processedCount files");
            $this->info("  Skipped: $skippedCount files");
            $this->info("  Errors: $errorCount files");
        } else {
            // Even in non-verbose mode, show summary stats if there were processed files
            if ($processedCount > 0 || $skippedCount > 0 || $errorCount > 0) {
                $this->info("Processing summary:");
                if ($processedCount > 0) {
                    $this->info("  Processed: $processedCount files");
                }
                if ($skippedCount > 0) {
                    $this->info("  Skipped: $skippedCount files");
                }
                if ($errorCount > 0) {
                    $this->info("  Errors: $errorCount files");
                }
            }
        }
    }

    /**
     * Query ChromaDB for similar documents
     */
    private function queryChroma($searchTerms, $limit, $host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel);
        
        try {
            // Query the specified collection by collection
            $results = $chroma->queryCollection($collection, [$searchTerms], $limit);
            
            $this->info("Query results for: \"$searchTerms\"");
            $this->info("Host: $host:$port");
            $this->info("Tenant: $tenant");
            $this->info("Database: $database");
            $this->info("Collection: $collection");
            $this->info("==========================================");
            
            if (empty($results['ids'][0])) {
                $this->info("No results found.");
                return;
            }
            
            for ($i = 0; $i < count($results['ids'][0]); $i++) {
                $this->info("Result " . ($i + 1) . ":");
                $this->info("  ID: " . $results['ids'][0][$i]);
                $this->info("  Distance: " . $results['distances'][0][$i]);
                $this->info("  Document: " . substr($results['documents'][0][$i], 0, 255) . "...");
                
                if (isset($results['metadatas'][0][$i])) {
                    $this->info("  Metadata: " . json_encode($results['metadatas'][0][$i]));
                }
                $this->info("");
            }
        } catch (Exception $e) {
            $this->error("Error querying ChromaDB: " . $e->getMessage());
            return;
        }
    }

    /**
     * Check if the ChromaDB server is alive
     */
    private function checkHeartbeat($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, 'documents', $ollamaHost, $ollamaPort, $ollamaModel);
        
        try {
            if ($verbose) {
                $this->info("Checking ChromaDB server status...");
                $this->info("Host: $host:$port");
                $this->info("Tenant: $tenant");
                $this->info("Database: $database");
                $this->info("==========================================");
            }
            
            $result = $chroma->heartbeat();
            
            $this->success("Server is alive!");
            $this->info("Response: " . json_encode($result));
        } catch (Exception $e) {
            $this->error("Error checking ChromaDB server status: " . $e->getMessage());
            return;
        }
    }

    /**
     * Get authentication and identity information from ChromaDB
     */
    private function checkIdentity($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, 'documents', $ollamaHost, $ollamaPort, $ollamaModel);
        
        try {
            if ($verbose) {
                $this->info("Checking ChromaDB identity...");
                $this->info("Host: $host:$port");
                $this->info("Tenant: $tenant");
                $this->info("Database: $database");
                $this->info("==========================================");
            }
            
            $result = $chroma->getIdentity();
            
            $this->info("Identity information:");
            $this->info("Response: " . json_encode($result, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            $this->error("Error checking ChromaDB identity: " . $e->getMessage());
            return;
        }
    }

    /**
     * List all collections in the ChromaDB database
     */
    private function listCollections($host, $port, $tenant, $database, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, 'documents', $ollamaHost, $ollamaPort, $ollamaModel);
        
        try {
            if ($verbose) {
                $this->info("Listing ChromaDB collections...");
                $this->info("Host: $host:$port");
                $this->info("Tenant: $tenant");
                $this->info("Database: $database");
                $this->info("==========================================");
            }
            
            $result = $chroma->listCollections();
            
            if (empty($result)) {
                $this->info("No collections found.");
                return;
            }
            
            $this->info("Collections:");
            foreach ($result as $collection) {
                $this->info("  - " . (isset($collection['name']) ? $collection['name'] : json_encode($collection)));
            }
        } catch (Exception $e) {
            $this->error("Error listing ChromaDB collections: " . $e->getMessage());
            return;
        }
    }

    /**
     * Get a document by its ID from ChromaDB
     */
    private function getDocument($documentId, $host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel, $verbose = false) {
        // If no collection specified, derive it from the first part of the document ID
        if (empty($collection)) {
            $idParts = explode(':', $documentId);
            $collection = isset($idParts[0]) && !empty($idParts[0]) ? $idParts[0] : 'documents';
        }
        
        // Create ChromaDB client
        $chroma = new \dokuwiki\plugin\dokullm\ChromaDBClient($host, $port, $tenant, $database, $collection, $ollamaHost, $ollamaPort, $ollamaModel);
        
        try {
            // Get the specified document by ID
            $results = $chroma->getDocument($collection, $documentId);
            
            if ($verbose) {
                $this->info("Document retrieval results for: \"$documentId\"");
                $this->info("Host: $host:$port");
                $this->info("Tenant: $tenant");
                $this->info("Database: $database");
                $this->info("Collection: $collection");
                $this->info("==========================================");
            }
            
            if (empty($results['ids'])) {
                $this->info("No document found with ID: $documentId");
                return;
            }
            
            for ($i = 0; $i < count($results['ids']); $i++) {
                $this->info("Document " . ($i + 1) . ":");
                $this->info("  ID: " . $results['ids'][$i]);
                
                if (isset($results['documents'][$i])) {
                    $this->info("  Content: " . $results['documents'][$i]);
                }
                
                if (isset($results['metadatas'][$i])) {
                    $this->info("  Metadata: " . json_encode($results['metadatas'][$i], JSON_PRETTY_PRINT));
                }
                $this->info("");
            }
        } catch (Exception $e) {
            $this->error("Error retrieving document from ChromaDB: " . $e->getMessage());
            return;
        }
    }
}
