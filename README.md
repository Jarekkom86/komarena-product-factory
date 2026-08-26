# KomArena Product Factory v2.2

WordPress/WooCommerce plugin na agentny workflow pre tvorbu, audit a rebuild produktov podla jednotneho KomArena standardu.

## Co plugin robi

- Vytvori WooCommerce produkt z jedneho nazvu.
- Vytvori bulk queue z viacerych nazvov, kazdy na novom riadku.
- Uklada queue stavy: `waiting`, `researching`, `images`, `content`, `uploading`, `creating_product`, `qa`, `ready`, `needs_review`, `failed`.
- Auto agent je predvolene zapnuty a frontu spracovava cez WP-Cron po vlozeni produktov.
- Agent Orchestrator robi preflight, retry failed uloh, spracovanie queue, planovany audit a bezpecny rebuild vlastnych KomArena produktov v `needs_review`.
- Agent Tasks pridava genericky task runner s vlastnou DB tabulkou pre dalsie ulohy mimo samotnej produktovej queue.
- Full-site autopilot z tlacidla `Spustit agenta teraz` automaticky doplni zakladne zdroje, spusti plugin audit, pripravi cleanup plan, skontroluje/upravi homepage sidebar layout a spusti produktovy audit.
- Audit Repair Planner po produktovom audite automaticky opravuje bezpecnu davku draft/pending/private produktov a pre publikovane produkty pripravi plan, kym admin nepovoli live opravy.
- Site Layout Agent vie obalit homepage do dvojstlpcoveho layoutu, vlozit `[komarena_sidebar_panel]`, urobit audit a vratit obsah zo zalohy.
- Plugin Housekeeper skontroluje nainstalovane pluginy, oznaci konflikty, duplicity, neaktivne pluginy a kandidatov na upratanie.
- Supplier URL Import nacita dodavatelsku URL, vytiahne JSON-LD/meta data, obrazky, cenu, SKU/EAN a zalozi queue ulohu.
- Product Builder CSV Agent zapracovava zavazny prompt pre dvojkrokovy WooCommerce CSV workflow: najprv ZIP so 4 pomenovanymi obrazkami, potom po URL jedneho nahrateho obrazka CSV + manifest ZIP.
- CSV Builder generuje stlpce `Type`, `Name`, `Short description`, `Description`, `SKU`, `Regular price`, `Categories`, `EAN`, `Stock`, `Status`, `Tax status`, `Images`, `Image alt text`, `Meta: _yoast_wpseo_title`, `Meta: _yoast_wpseo_metadesc`.
- CSV Builder dodrziava image rezimy `REAL_STANDARDIZED`, `REAL_PARTIAL`, `ILLUSTRATED_FALLBACK`; bez realnych overenych podkladov vytvori oznacenu farebnu kreslenu technicku podobu produktu.
- Research Evidence Engine fetchuje vlozene zdrojove URL, hlada produktove JSON-LD, datasheet/manual odkazy, kandidatske obrazky a tokenovu zhodu s nazvom produktu.
- Agent automaticky pouzije kandidatov obrazkov zo zdrojov, ak admin nezadal vlastne image URL.
- Image Provenance manifest uklada povod kazdeho obrazka: overeny sideload, zachovany existujuci obrazok, farebny kresleny fallback alebo review placeholder.
- QA gate pri prisnej verifikacii kontroluje aj `source_evidence` a `image_provenance`, nielen samotnu existenciu obrazkov.
- REST Bridge pre PC agenta pridava bezpecne endpointy na status, spustenie agenta, queue enqueue, task runner, plugin audit, site layout a logy.
- REST Bridge podporuje aj preflight, queue batch, produktovy audit, review list, QA a rebuild konkretneho produktu.
- Plugin cleanup cez REST vie deaktivovat vybrane pluginy iba s explicitnym potvrdenim `DEACTIVATE`.
- Volitelny sukromny deployment companion vie cez SSH + WP-CLI vykonat zalohu, nasadenie a nasledne spustat povolene REST workflowy; prihlasovacie konfiguracie nie su sucastou tohto repozitara.
- Agent Memory uklada overene produktove/chip/safety profily a pouziva ich pri dalsom researchi.
- Review Center ukazuje produkty `needs_review`, failed queue a posledne memory zaznamy.
- Duplicate Detector brani tichej duplicite podla SKU, EAN, slugu a podobnosti nazvu.
- Attribute Engine doplna WooCommerce technicke atributy ako cip, porty, konektory, kompatibilita a rozhranie.
- Safety Engine sprisni QA a texty pri rele, AC/DC a inych rizikovych produktoch.
- Generuje kratky popis, dlhy inline-safe WooCommerce HTML popis, cenu `.99`, SKU, interny EAN zaciatkom `2998`, kategorie, sklad, Yoast SEO meta, interne odkazy, manifest a QA report.
- Pripravi presne 4 obrazky s nazvami `komarena-[slug]-main.jpg`, `komarena-[slug]-angle.jpg`, `komarena-[slug]-detail.jpg`, `komarena-[slug]-technical.jpg`.
- Ak nie su dostupne overene obrazkove zdroje, agent pouzije oznaceny kresleny fallback podla research profilu produktu. Docasne review placeholdery su vypnute predvolene a nikdy nestacia na `ready_to_publish`.
- Audit existujucich produktov oznaci chyby v obrazkoch, SKU, EAN, SEO, cene, popise, internych odkazoch a KomArena dizajne.
- Pri Woo produkte pridava akciu `Prebudovat podla KomArena standardu`.
- Pri Woo produkte pridava aj okamzitu akciu `Spustit KomArena QA`.
- Platforma je pripravena na blog drafty, projektove balicky, EAN scanner, price monitor, kolekcie a Home Assistant project pages cez task hooky.
- Pluginy sa nikdy nemazu automaticky. Deaktivacia vybranych pluginov vyzaduje explicitne potvrdenie adminom.
- Spustenie agenta je teraz hlavny webovy checklist: zdroje, pluginy, layout, produkty, queue, tasky a rebuild.
- Agent vie zachovat status produktu pri audit repair rebuildoch, aby oprava nezvesila publikovane produkty z webu.

## Bezpecnostny model

Plugin pouziva capability checks, nonces, sanitizaciu vstupov, escaping vystupov a WordPress media upload API. Produkty su vytvarane ako `draft`. Interny stav `ready_to_publish` sa uklada do meta. Publikovanie sa nespusti, pokial ho admin explicitne nezapne v nastaveniach. Auto agent spracovava queue automaticky, ale stale dodrziava QA gate. Ak chyba overeny zdroj, produkt zostane v `needs_review`; ak chybaju realne obrazky, agent moze pouzit jasne oznaceny kresleny fallback.

## Odporucane doplnky platformy

Agent vie fungovat samostatne s WooCommerce, ale pre robustnu prevadzku odporucame:

- WooCommerce - jadro produktov a objednavok.
- Yoast SEO - meta title, meta description a SEO workflow.
- WP Crontrol - kontrola WP-Cron udalosti agenta.
- Activity Log alebo WP Activity Log - audit administracnych zmien.
- UpdraftPlus, WPvivid alebo hosting backup - nezavisle zalohy pred vacsimi upravami.
- SMTP plugin - spolahlive systemove emaily.
- Query Monitor - docasne na diagnostiku, nie ako trvalo zapnuty produkcny nastroj.

## Overene zdroje

Research engine je konzervativny. Bez produktovych zdrojov alebo globalnych overenych referencii ulozi varovanie a pri zapnutej prisnej verifikacii produkt skonci ako `needs_review`. Uklada aj evidence manifest: status `verified`, `weak`, `unreachable` alebo `none`, pocet nacitanych zdrojov, zhodu tokenov, najdene datasheety a kandidatov obrazkov.

Od v2.5.8 plati tvrda fact-verification brana: konkretne technicke tvrdenia v popise, CSV a SEO nesmu byt finalne, pokial nie su naviazane na overeny zdroj. Pri klonoch nestaci iba dokumentacia originalu; musi existovat aj realny klon/distributor listing alebo interna KomArena referencia. Inak sa presne parametre maskuju ako cakanie na overenie a QA vrati `needs_review`.

Od v2.5.9 safety engine nerozpoznava vyvojovu dosku ako sietovy produkt len preto, ze v typickom pouziti spomina rele modul. Image research navyse filtruje socialne ikonky, loga, bannery, placeholdery a Instagram/social obrazky mimo produktovych kandidatov.

Od v2.5.10 image research berie z jedneho zdrojoveho listingu maximalne prve 4 najblizsie produktove kandidatne obrazky, aby do manifestu netahal suvisiace produkty z odporucanych blokov.

Od v2.5.11 status API vracia aj `require_verified_product_facts`, aby PC agent a dashboard vedeli zobrazit novu faktovu branu.

Podporovane zdroje:

- vyrobca
- dokumentacia
- datasheet
- overeny distributor
- interna referencia KomArena

## Integracne hooky

Na realne agentne rozsirena su pripravene filtre:

```php
add_filter('komarena_pf_research_result', function($result, $product_name, $options) {
	// Enrich only from verified manufacturer docs, datasheets, distributors, or internal KomArena references.
	return $result;
}, 10, 3);

add_filter('komarena_pf_image_sources', function($sources, $product_id, $research) {
	// Return main/angle/detail/technical image URLs from verified sources.
	return array(
		'main' => 'https://example.com/main.jpg',
		'angle' => 'https://example.com/angle.jpg',
		'detail' => 'https://example.com/detail.jpg',
		'technical' => 'https://example.com/technical.jpg',
	);
}, 10, 3);

add_filter('komarena_pf_competitor_prices', function($prices, $research) {
	// Return numeric competitor prices from a verified pricing connector.
	return $prices;
}, 10, 2);

add_filter('komarena_pf_task_types', function($types) {
	$types['my_custom_agent_task'] = array(
		'label' => 'My custom agent task',
		'description' => 'Runs a custom KomArena workflow.',
		'future' => false,
	);
	return $types;
});

add_filter('komarena_pf_task_dispatch_my_custom_agent_task', function($result, $payload, $plugin, $task_manager, $task) {
	// Return array('status' => 'done') or WP_Error.
	return array('status' => 'done', 'message' => 'Custom task completed.');
}, 10, 5);

add_filter('komarena_pf_required_plugins', function($plugins) {
	$plugins['my-required/plugin.php'] = array(
		'role' => 'custom_required',
		'reason' => 'Tento plugin je potrebny pre aktualny KomArena web.',
	);
	return $plugins;
});

add_filter('komarena_pf_builder_csv_row', function($row, $research, $image_urls, $options, $builder) {
	// Uprav CSV riadok pred zapisom, napriklad vlastne kategorie alebo interny EAN provider.
	return $row;
}, 10, 5);

add_filter('komarena_pf_builder_manifest', function($manifest, $row, $research, $options, $builder) {
	// Doplni vlastne manifest polia pre dodavatelsky import, image pipeline alebo QA.
	return $manifest;
}, 10, 5);
```

## PC agent / REST bridge

Plugin registruje REST namespace `komarena-pf/v1`. Prihlasovanie odporucame cez WordPress Application Password pouzivatela, ktory ma `manage_woocommerce` alebo `manage_options`.

Endpointy:

- `GET /wp-json/komarena-pf/v1/status`
- `GET /wp-json/komarena-pf/v1/preflight`
- `POST /wp-json/komarena-pf/v1/agent/run`
- `POST /wp-json/komarena-pf/v1/queue/enqueue`
- `POST /wp-json/komarena-pf/v1/queue/run`
- `POST /wp-json/komarena-pf/v1/tasks/create`
- `POST /wp-json/komarena-pf/v1/tasks/run`
- `POST /wp-json/komarena-pf/v1/audit/run`
- `GET /wp-json/komarena-pf/v1/review/list`
- `POST /wp-json/komarena-pf/v1/products/rebuild`
- `POST /wp-json/komarena-pf/v1/products/qa`
- `POST /wp-json/komarena-pf/v1/plugin-audit/run`
- `POST /wp-json/komarena-pf/v1/plugin-audit/deactivate`
- `POST /wp-json/komarena-pf/v1/site-layout/run`
- `GET /wp-json/komarena-pf/v1/logs`

Lokalny Windows deployment companion a jeho konfiguracia nie su sucastou verejneho repozitara. Pluginove REST rozhranie je pripravene na autentifikovane integracie, pricom skutocne pristupove udaje maju ostat iba v lokalnom prostredi.

## Subory

- `komarena-product-factory.php` - plugin bootstrap
- `includes/class-komarena-pf-admin-ui.php` - admin dashboard, forms, row actions
- `includes/class-komarena-pf-queue-manager.php` - bulk queue
- `includes/class-komarena-pf-research-engine.php` - conservative research model
- `includes/class-komarena-pf-image-engine.php` - image upload/generation layer
- `includes/class-komarena-pf-content-engine.php` - KomArena HTML content standard
- `includes/class-komarena-pf-csv-builder-engine.php` - dvojkrokovy WooCommerce CSV + image ZIP Product Builder agent
- `includes/class-komarena-pf-pricing-engine.php` - pricing `.99`
- `includes/class-komarena-pf-sku-ean-engine.php` - SKU/EAN
- `includes/class-komarena-pf-link-engine.php` - internal link pills
- `includes/class-komarena-pf-product-creator.php` - WooCommerce product creation
- `includes/class-komarena-pf-audit-engine.php` - existing product audit
- `includes/class-komarena-pf-rebuild-engine.php` - rebuild preserving product ID/slug
- `includes/class-komarena-pf-agent-orchestrator.php` - autopilot mozog agenta
- `includes/class-komarena-pf-task-manager.php` - genericky task runner a extension platforma
- `includes/class-komarena-pf-rest-controller.php` - REST bridge pre PC agenta a externe automatizacie
- `includes/class-komarena-pf-site-layout-engine.php` - homepage/sidebar layout agent
- `includes/class-komarena-pf-plugin-housekeeper-engine.php` - audit a bezpecne upratanie pluginov
- `includes/class-komarena-pf-qa-engine.php` - ready/needs_review gate
- `includes/class-komarena-pf-logger.php` - run logs
- `includes/class-komarena-pf-settings.php` - settings
- `includes/class-komarena-pf-memory-engine.php` - agent memory
- `includes/class-komarena-pf-supplier-import-engine.php` - supplier URL import
- `includes/class-komarena-pf-duplicate-detector.php` - duplicate detection
- `includes/class-komarena-pf-attribute-engine.php` - WooCommerce attributes
- `includes/class-komarena-pf-safety-engine.php` - safety profile rules
