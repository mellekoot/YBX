<?php
// --- CONFIGURATIE EN INITIËLE SETUP ---

$config = require __DIR__ . '/config.php';
$apiKey = $config['OMDB_API_KEY'] ?? null;
$apiKeyValid = false; // Fix: Initialiseer de variabele

// Vertalingen
$T = [
    'title' => 'YBX Film Aanbeveling',
    'subtitle' => 'Vind de beste films voor het **Gen Z (Tiener) Publiek** op basis van de **%d** meest recente weken aan box office data.',
    'select_label' => 'Weken om te Analyseren (Meest Recent Eerst):',
    'select_option' => '%d Week%s (Tot Week %s)',
    'update_button' => 'Bijwerken',
    'api_error_title' => 'OMDb API Sleutel is Ongeldig of Foutief',
    'api_error_message' => 'Filmposters en details kunnen niet worden opgehaald. Controleer uw API-sleutel in <code>config.php</code>.',
    'config_error' => 'Configuratie Fout: API Sleutel Mist in config.php',
    'data_error' => 'Geen CSV-bestanden gevonden in de /Data/ map.',
    'movie_data_error' => 'Er is geen filmdata verwerkt. Controleer uw CSV-bestanden en zorg ervoor dat het scheidingsteken een komma (\',\') is.',
    'director' => 'Regisseur',
    'genre' => 'Genre',
    'runtime' => 'Speelduur',
    'teen_attendance' => 'Tiener (Gen Z) Bezoekers',
    'teen_share' => 'Tiener Aandeel',
    'total_attendance' => 'Totaal Aantal Bezoekers',
    'recommended_price' => 'Aanbevolen Ticketprijs',
    'max_price_note' => 'Max €14,00',
    'imdb_link' => 'Bekijk op IMDb',
    'footer' => 'YBX Film Aanbeveling door Melle Koot &bull; Data verwerkt van weken: %s',
];

// Error Handling for Missing API Key
if (!$apiKey) {
    http_response_code(500);
    die("<h1 style='color:#f00; text-align:center;'>{$T['config_error']}</h1>");
}

$dataDir = __DIR__ . "/Data/"; 

// --- BESTANDSELECTIE LOGICA ---
$files = glob($dataDir . "*.csv");
$fileWeeks = [];

// Verzamel weeknummers en jaar voor sortering
foreach ($files as $file) {
    // Extrahert jaar en week van de bestandsnaam (e.g., '25-38')
    if (preg_match('/(\d{2})-(\d{2})/', basename($file), $matches)) {
        $year = intval($matches[1]);
        $week = intval($matches[2]);
        // Sorteersleutel (YYYYWW)
        $fileWeeks[$file] = $year * 100 + $week;
    }
}

// Sorteer bestanden aflopend (meest recente week eerst)
arsort($fileWeeks);
$sortedFiles = array_keys($fileWeeks);
$allWeeks = count($sortedFiles);

// Bepaal de meest recente week voor weergave in de dropdown
$latestWeekDisplay = $allWeeks > 0 ? (preg_match('/(\d{2}-\d{2})/', basename($sortedFiles[0]), $m) ? $m[1] : 'N/A') : 'N/A';

// Vraag om gebruikersinvoer voor het aantal weken, standaard alle
$weeksInput = filter_input(INPUT_GET, 'weeks', FILTER_VALIDATE_INT);
$weeksToUse = min($weeksInput ?: $allWeeks, $allWeeks);

// Bepaal de te verwerken bestanden
$filesToProcess = array_slice($sortedFiles, 0, $weeksToUse);

// Bepaal de weer te geven weken in de footer (meest recent eerst)
$processedWeeks = [];
foreach ($filesToProcess as $file) {
    if (preg_match('/(\d{2})-(\d{2})/', basename($file), $matches)) {
        $processedWeeks[] = $matches[1] . "-" . $matches[2];
    }
}
$displayWeeks = empty($processedWeeks) ? "Geen data geselecteerd" : implode(", ", $processedWeeks);


// --- DATA AGGREGATIE ---
$moviesData = [];
foreach ($filesToProcess as $file) {
    if (($handle = fopen($file, "r")) !== FALSE) {
        fgetcsv($handle, 1000, ","); // Sla headers over, gebruikt komma als scheidingsteken
        
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $data = array_map('trim', $data);

            if (count($data) < 8) continue;
            
            $title = $data[6];
            if (empty($title) || strcasecmp($title, 'other') === 0 || strcasecmp($title, 'weekend total') === 0) {
                continue;
            }

            $gen = $data[1] ?? 'Unknown';
            $val = floatval(str_replace(",", ".", $data[7]));

            if (!isset($moviesData[$title])) {
                $moviesData[$title] = ["total" => 0, "teen" => 0];
            }

            $moviesData[$title]["total"] += $val;
            if (strcasecmp($gen, "Gen Z") === 0) {
                $moviesData[$title]["teen"] += $val;
            }
        }
        fclose($handle);
    }
}

// --- SORTERING ---
$moviesArray = [];
foreach ($moviesData as $title => $stats) {
    $moviesArray[] = ['title' => $title, 'stats' => $stats];
}
// Sorteer op 'teen' (Gen Z) statistieken aflopend
usort($moviesArray, fn($a, $b) => $b['stats']['teen'] <=> $a['stats']['teen']);


// --- API FUNCTIES ---
function getMovieData($title, $apiKey) {
    static $cache = [];
    $cacheKey = strtolower($title);
    
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];
    
    // Maak de titel schoon voor de API-zoekopdracht (FIX voor titels zoals "Superman (2025)")
    $searchTitle = preg_replace('/\s*\(\d{4}\)$/', '', $title);

    $url = "http://www.omdbapi.com/?apikey=" . $apiKey . "&t=" . urlencode($searchTitle);
    
    // Fetch
    $json = @file_get_contents($url);
    if ($json === FALSE) return null;

    $data = json_decode($json, true);
    if ($data && $data["Response"] == "True") {
        return $cache[$cacheKey] = $data;
    }
    
    return null;
}

// --- PRIJS ALGORITME ---
function getRecommendedPrice($runtime, $totalVisitors, $releaseDate) {
    $base = 8.0; 
    $MAX_PRICE = 14.0; 

    // Speelduur factor (Standaard 90 min)
    $runtimeMinutes = 90;
    if ($runtime && preg_match('/(\d+) min/', $runtime, $m)) {
        $runtimeMinutes = intval($m[1]);
    }
    $runtime_factor = min(1.4, max(0.9, $runtimeMinutes / 100)); 

    // Bezoekers factoren
    $logBase = log10(1 + $totalVisitors);
    $attendance_factor = min(1.3, 0.9 + $logBase / 8);
    $popularity_factor = min(1.3, 0.9 + $logBase / 15);

    // Leeftijdsfactor
    $age_factor = 1.0; 
    if ($releaseDate && $releaseDate != 'N/A') {
        $rel = DateTime::createFromFormat('Y-m-d', $releaseDate) ?: DateTime::createFromFormat('Y', $releaseDate);
        if ($rel) {
            $now = new DateTime();
            $weeks_since_release = max(0, intval($now->diff($rel)->days / 7));
            $age_factor = max(0.85, 1 - $weeks_since_release / 104);
        }
    }

    // Bereken prijs
    $price = $base * $runtime_factor * $attendance_factor * $popularity_factor * $age_factor;
    
    // Max Prijs Opleggen
    $price = min($price, $MAX_PRICE);

    // Afronden op het dichtstbijzijnde 0.50
    return round($price * 2) / 2;
}

// --- API SLEUTEL TEST ---
$testUrl = "http://www.omdbapi.com/?apikey=" . $apiKey . "&t=Inception";
$testJson = @file_get_contents($testUrl);
if ($testJson) {
    $testData = json_decode($testJson, true);
    if ($testData && ($testData["Response"] ?? "False") == "True") {
        $apiKeyValid = true;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<title><?= $T['title'] ?></title>
<style>
/* --- STYLING REDESIGN --- */
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #0c0c0e; /* Dark background */
    color: #e0e0e5;
    margin: 0;
    padding: 0;
}
header {
    background: linear-gradient(135deg, #2b3a4a, #1a1a20);
    padding: 30px 20px;
    text-align: center;
    border-bottom: 3px solid #6c757d;
}
header h1 {
    margin: 0;
    font-size: 2.5em;
    color: #6cf; /* Light Blue Accent */
    letter-spacing: 1px;
}
.subheader {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 20px;
    margin-top: 15px;
}
.subheader p {
    margin: 0;
    font-size: 0.9em;
    color: #a0a0a5;
}
.selection-form {
    display: flex;
    gap: 10px;
    align-items: center;
    background-color: #1a1a20;
    padding: 8px 15px;
    border-radius: 8px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
}
.selection-form label {
    font-size: 0.9em;
    color: #6cf;
}
.selection-form select, .selection-form button {
    padding: 8px 12px;
    border-radius: 6px;
    border: 1px solid #4a4a50;
    font-size: 0.9em;
    cursor: pointer;
}
.selection-form select {
    background-color: #1a1a20;
    color: #e0e0e5;
}
.selection-form button {
    background-color: #6cf;
    color: #1a1a20;
    font-weight: bold;
    transition: background-color 0.2s;
}
.selection-form button:hover {
    background-color: #399;
}
.api-error {
    background: #3a1f1f;
    color: #f7aaaa;
    border: 1px solid #7c3a3a;
    padding: 15px;
    margin: 20px auto;
    width: 90%;
    max-width: 1400px;
    border-radius: 8px;
    text-align: center;
}

/* --- GRID LAYOUT --- */
.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    padding: 40px;
    max-width: 1600px;
    margin: auto;
}
.card {
    background-color: #1a1a20;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.4);
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex;
    flex-direction: column;
}
.card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(0, 0, 0, 0.6);
}
.poster {
    width: 100%;
    height: 450px;
    object-fit: cover;
    background-color: #2b3a4a;
}
.content {
    padding: 20px;
    flex-grow: 1;
    display: flex;
    flex-direction: column;
}
.title {
    font-size: 1.4em;
    font-weight: 700;
    margin-bottom: 5px;
    color: #f0f0f5;
}
.meta {
    font-size: 0.9em;
    color: #a0a0a5;
    margin-bottom: 4px;
}
.stats {
    margin-top: 10px;
    border-top: 1px solid #2a2a30;
    padding-top: 10px;
    display: flex;
    flex-direction: column;
}
.stat-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 5px;
}
.stat-label {
    font-size: 0.9em;
    color: #6cf;
    font-weight: 600;
}
.stat-value {
    font-size: 1.1em;
    font-weight: 700;
    color: #ff9; /* Yellowish accent */
}

/* Price specific styling */
.price-label {
    font-size: 1.1em;
    color: #3c3; /* Green accent */
    font-weight: bold;
}
.price-value {
    font-size: 1.5em;
    font-weight: 900;
    color: #3c3;
}

.imdb-link {
    display: block;
    text-align: center;
    padding: 12px;
    background-color: #6cf;
    color: #1a1a20;
    text-decoration: none;
    border-radius: 8px;
    font-weight: bold;
    margin-top: 20px;
    transition: background-color 0.2s;
}
.imdb-link:hover {
    background-color: #399;
}
footer {
    text-align: center;
    padding: 20px;
    background-color: #1a1a20;
    border-top: 3px solid #6c757d;
    color: #6c757d;
    font-size: 0.8em;
    margin-top: 40px;
}
</style>
</head>
<body>
<header>
    <h1><?= $T['title'] ?></h1>
    <p><?= sprintf($T['subtitle'], $weeksToUse) ?></p>
    
    <div class="subheader">
        <div class="selection-form">
            <form method="get" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>">
                <label for="weeks"><?= $T['select_label'] ?></label>
                <select id="weeks" name="weeks">
                    <?php for ($i = 1; $i <= $allWeeks; $i++): ?>
                        <option value="<?= $i ?>" <?= $i == $weeksToUse ? 'selected' : '' ?>>
                            <?= sprintf($T['select_option'], $i, $i > 1 ? 'ken' : 'k', $latestWeekDisplay) ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <button type="submit"><?= $T['update_button'] ?></button>
            </form>
        </div>
        
    </div>
</header>

<?php if (!$apiKeyValid): ?>
<div class="api-error">
    <strong><?= $T['api_error_title'] ?></strong>
    <?= $T['api_error_message'] ?>
    De sleutel <code><?= htmlspecialchars($apiKey) ?></code> lijkt inactief.
</div>
<?php endif; ?>

<div class="grid">
<?php
if (empty($moviesArray)) {
    echo "<p style='grid-column: 1 / -1; text-align: center; font-size: 1.2em; color: #f7aaaa;'>{$T['movie_data_error']}</p>";
}

// Render alleen films waarvoor de API-oproep succesvol was
$hasMovies = false;
foreach ($moviesArray as $movie):
    $title = $movie['title'];
    $stats = $movie['stats'];
    $data = getMovieData($title, $apiKey);
    
    $isApiSuccessful = $data && ($data["Response"] ?? "False") == "True";

    // --- ENKEL DOORGAAN ALS API SUCCESVOL IS ---
    if ($isApiSuccessful):
        $hasMovies = true;
        
        $percent = $stats["total"] > 0 ? round(($stats["teen"] / $stats["total"]) * 100, 2) : 0;
        
        $runtime = $data['Runtime'] ?? "90 min";
        $releaseDate = $data['Released'] ?? ($data['Year'] ?? date("Y"));
        
        $price = getRecommendedPrice($runtime, $stats['total'], $releaseDate);
        
        // Formatteer grote nummers
        $teenAttendance = number_format($stats['teen'], 0, ",", ".");
        $totalAttendance = number_format($stats['total'], 0, ",", ".");

        $posterUrl = $data['Poster'] !== 'N/A' ? $data['Poster'] : 'https://via.placeholder.com/300x450/1a1a20/6cf?text=' . urlencode($title);
        $imdbLink = "https://www.imdb.com/title/" . $data['imdbID'];
?>
<div class="card">
<img class="poster" src="<?= htmlspecialchars($posterUrl) ?>" alt="<?= htmlspecialchars($title) ?> Poster">
<div class="content">
    <div class="title"><?= htmlspecialchars($data['Title']) ?> (<?= htmlspecialchars($data['Year'] ?? 'N/A') ?>)</div>
    
    <div class="meta"><?= $T['director'] ?>: <?= htmlspecialchars($data['Director'] ?? 'N/A') ?></div>
    <div class="meta"><?= $T['genre'] ?>: <?= htmlspecialchars($data['Genre'] ?? 'N/A') ?></div>
    <div class="meta"><?= $T['runtime'] ?>: <?= htmlspecialchars($data['Runtime'] ?? 'N/A') ?></div>
    
    <div class="stats">
        <div class="stat-row">
            <span class="stat-label"><?= $T['teen_attendance'] ?></span>
            <span class="stat-value"><?= $teenAttendance ?></span>
        </div>
        <div class="stat-row">
            <span class="stat-label"><?= $T['teen_share'] ?></span>
            <span class="stat-value"><?= $percent ?>%</span>
        </div>
        <div class="stat-row">
            <span class="stat-label"><?= $T['total_attendance'] ?></span>
            <span class="stat-value"><?= $totalAttendance ?></span>
        </div>
        <div class="stat-row" style="margin-top: 15px;">
            <span class="price-label"><?= $T['recommended_price'] ?></span>
            <span class="price-value">€<?= number_format($price, 2, ",", ".") ?></span>
        </div>
    </div>

    <a class="imdb-link" href="<?= htmlspecialchars($imdbLink) ?>" target="_blank"><?= $T['imdb_link'] ?></a>
</div>
</div>
<?php 
    endif;
endforeach; 

if (!$hasMovies && $apiKeyValid): ?>
    <p style='grid-column: 1 / -1; text-align: center; font-size: 1.2em; color: #a0a0a5;'>Geen films gevonden voor de geselecteerde periode of uw API-sleutel is onlangs verlopen.</p>
<?php endif; ?>
</div>

<footer>
    <p><?= sprintf($T['footer'], htmlspecialchars($displayWeeks)) ?></p>
</footer>
</body>
</html>