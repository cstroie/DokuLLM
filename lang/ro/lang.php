<?php
/**
 * Fișier de limbă română pentru pluginul DokuLLM
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Costin Stroie <costinstroie@eridu.eu.org>
 */

/**
 * Etichetă buton pentru funcționalitatea de copiere a paginii
 *
 * Acest șir este folosit ca etichetă pentru butonul „copy page” care apare
 * în meniul de instrumente al paginii. Trebuie să fie o frază clară,
 * acționabilă, care să indice scopul butonului.
 */
$lang['copy_page_button'] = "Copiază pagina";

/**
 * Mesaj JavaScript pentru introducerea unui nou ID de pagină
 *
 * Acest mesaj este afișat într-un dialog de tip prompt JavaScript când utilizatorul
 * apasă butonul de copiere a paginii. Îi solicită să introducă ID‑ul pentru
 * noua pagină care va fi creată ca o copie a paginii curente.
 */
$lang['js']['enter_page_id'] = "Introduceţi ID‑ul noii pagini: ";

/**
 * Mesaj de eroare JavaScript pentru validarea ID‑ului
 *
 * Acest mesaj este afișat într-un dialog de tip alert JavaScript când utilizatorul
 * introduce același ID ca al paginii curente. Impune ca noua pagină să aibă un ID diferit
 * de cel al paginii sursă.
 */
$lang['js']['different_id_required'] = "Trebuie să introduceţi un ID diferit de cel al paginii curente.";

$lang['js']['insert_template']          = 'Inserează șablon';
$lang['js']['find_template']            = 'Caută șablon';
$lang['js']['loading_actions']          = 'Se încarcă acţiunile DokuLLM...';
$lang['js']['custom_prompt_placeholder']= 'Introduceţi promptul...';
$lang['js']['send']                     = 'Trimite';
$lang['js']['error_loading_dokullm']    = 'Pagina de profil DokuLLM nu a fost găsită. Verificaţi spaţiul de nume „dokullm:”.';
$lang['js']['no_text_provided']         = 'Vă rugăm să selectaţi text sau să introduceţi conţinut pentru procesare';
$lang['js']['processing']               = 'Se procesează...';
$lang['js']['searching']                = 'Se caută...';
$lang['js']['backend_error']            = 'Răspunsul reţelei nu a fost ok: ';
$lang['js']['thinking_process']         = 'Proces de gândire AI';
$lang['js']['close']                    = 'Închide';
$lang['js']['close_title']              = 'Închide fereastra modală';
$lang['js']['append']                   = 'Adaugă';
$lang['js']['append_title']             = 'Adaugă la raport';
$lang['js']['no_prompt_provided']       = 'Vă rugăm să introduceţi un prompt';
$lang['js']['no_text_provided']         = 'Vă rugăm să selectaţi text sau să introduceţi conţinut pentru procesare';
$lang['js']['template_found']           = 'Șablon găsit şi inserat: ';
$lang['js']['no_template_found']        = 'Nu a fost găsit niciun şablon potrivit pentru acest conţinut.';
$lang['js']['loading_template']         = 'Se încarcă şablonul...';

$lang['template_not_found']     = 'Şablonul nu a fost găsit: ';
$lang['no_text_provided']       = 'Niciun text furnizat';
$lang['unauthorized']           = 'Nu aveţi permisiunea de a citi acest fişier: ';
$lang['error_finding_template'] = 'Eroare la găsirea şablonului: ';