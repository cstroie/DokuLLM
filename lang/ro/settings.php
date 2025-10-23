<?php
/**
 * Romanian language file for config of DokuLLM plugin
 *
 */

$lang['api_url']                = 'URL endpoint API LLM';
$lang['api_key']                = 'Cheie API pentru autentificarea cu serviciul LLM';
$lang['model']                  = 'Nume model utilizat pentru procesarea textului';
$lang['timeout']                = 'Timeout cerere (secunde)';
$lang['profile']                = 'Profil prompt';
$lang['temperature']            = 'Temperatură (0.0‑1.0) – valori mai mici produc un output mai concentrat';
$lang['top_p']                  = 'Top‑P (Nucleus Sampling) – controlează diversitatea răspunsurilor';
$lang['top_k']                  = 'Top‑K – limitează selecția token‑urilor la primele K opțiuni';
$lang['min_p']                  = 'Min‑P – pragul minim de probabilitate pentru selecția token‑urilor';
$lang['show_copy_button']       = 'Afișează butonul „Copy Page” în bara de instrumente';
$lang['replace_id']             = 'Înlocuiește ID‑ul șablonului la copiere';
$lang['think']                  = 'Activează „Thinking” în răspunsurile LLM pentru procesare mai profundă';
$lang['use_tools']              = 'Activează utilizarea instrumentelor în răspunsurile LLM pentru capabilități extinse';
$lang['enable_chromadb']        = 'Activează integrarea ChromaDB – când este activată, funcționalitățile ChromaDB vor fi disponibile pentru stocarea și recuperarea documentelor';
$lang['chroma_host']            = 'Host ChromaDB – numele de gazdă sau adresa IP a serverului ChromaDB';
$lang['chroma_port']            = 'Port ChromaDB – numărul portului pe care rulează ChromaDB';
$lang['chroma_tenant']          = 'Tenant ChromaDB – numele tenantului pentru organizarea în ChromaDB';
$lang['chroma_database']        = 'Bază de date ChromaDB – numele bazei de date în cadrul tenantului ChromaDB';
$lang['chroma_collection']      = 'Colecție ChromaDB – numele colecției implicite pentru stocarea documentelor';
$lang['ollama_host']            = 'Host Ollama – numele de gazdă sau adresa IP a serverului Ollama';
$lang['ollama_port']            = 'Port Ollama – numărul portului pe care rulează Ollama';
$lang['ollama_embeddings_model']= 'Model embeddings Ollama – numele modelului utilizat pentru generarea de embeddings text';