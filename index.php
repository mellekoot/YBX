<?php
// --- CONFIG ---
// Load API key from config.php
$config = require __DIR__ . '/config.php';
$apiKey = $config['OMDB_API_KEY'] ?? null;
if (!$apiKey) die("<h1 style='color:white; text-align:center;'>Missing API Key</h1>");

$dataDir = __DIR__ . "/Data/"; 

// --- Find latest CSV file ---
$files = glob($dataDir . "*.csv");
if (!$files) die("<h1 style='color:white; text-align:center;'>No movie data found</h1>");
usort($files, fn($a, $b) => strcmp($b, $a));
$foundFile = $files[0];
$filenameParts = explode("-", basename($foundFile, ".csv"));
$yearWeek = count($filenameParts) == 2 ? strtoupper($filenameParts[0] . "-" . $filenameParts[1]) : basename($foundFile, ".csv");

// --- Parse CSV robustly ---
$moviesData = [];
if (($handle = fopen($foundFile, "r")) !== FALSE) {
    fgetcsv($handle, 1000, ";"); // skip headers
    while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
        $data = array_map('trim', $data);

        // Skip rows with missing title or "Other"
        if (!isset($data[6]) || strtolower($data[6]) === 'other' || $data[6] === '') continue;

        $title = $data[6];
        $gen = $data[1] ?? 'Unknown';
        $val = isset($data[7]) ? floatval(str_replace(",", ".", $data[7])) : 0;

        if (!isset($moviesData[$title])) $moviesData[$title] = ["total" => 0, "genz" => 0, "weekly" => 0];

        $moviesData[$title]["total"] += $val;
        if (strcasecmp($gen, "Gen Z") === 0) $moviesData[$title]["genz"] += $val;
        $moviesData[$title]["weekly"] += $val; // weekly visitors
    }
    fclose($handle);
}

// --- Convert to array and sort by Gen Z attendance ---
$moviesArray = [];
foreach ($moviesData as $title => $stats) $moviesArray[] = ['title' => $title, 'stats' => $stats];
usort($moviesArray, fn($a, $b) => $b['stats']['genz'] <=> $a['stats']['genz']);

// --- OMDb fetch with caching ---
function getMovieData($title, $apiKey) {
    static $cache = [];
    if (isset($cache[$title])) return $cache[$title];
    $url = "http://www.omdbapi.com/?apikey=" . $apiKey . "&t=" . urlencode($title);
    $json = @file_get_contents($url);
    return $cache[$title] = $json ? json_decode($json,true) : null;
}

// --- Recommended Price Algorithm (€8–€15 approx) ---
function getRecommendedPrice($runtime, $weeklyVisitors, $totalVisitors, $releaseDate) {
    $base = 8.0; // increased base price

    // Runtime factor: 0.9–1.4
    $runtimeMinutes = 90;
    if (preg_match('/(\d+) min/', $runtime, $m)) $runtimeMinutes = intval($m[1]);
    $runtime_factor = min(1.4, max(0.9, $runtimeMinutes / 100)); 

    // Weekly visitors factor: scale 0.9–1.3
    $attendance_factor = min(1.3, 0.9 + log10(1 + $weeklyVisitors)/8);

    // Total visitors factor: scale 0.9–1.3
    $popularity_factor = min(1.3, 0.9 + log10(1 + $totalVisitors)/15);

    // Age factor: older movies slightly cheaper
    $weeks_since_release = 0;
    if ($releaseDate != 'N/A') {
        $rel = DateTime::createFromFormat('Y', $releaseDate) ?: DateTime::createFromFormat('Y-m-d', $releaseDate);
        if ($rel) {
            $now = new DateTime();
            $weeks_since_release = max(0, intval($now->diff($rel)->days / 7));
        }
    }
    $age_factor = max(0.85, 1 - $weeks_since_release / 104);

    // Calculate price
    $price = $base * $runtime_factor * $attendance_factor * $popularity_factor * $age_factor;

    // Round to nearest 0.50
    return round($price * 2) / 2;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Weekly Movie Showcase</title>
<style>
body {font-family:"Segoe UI",sans-serif;background:#0d1117;color:#e6edf3;margin:0;}
header{text-align:center;padding:25px;background:#161b22;border-bottom:2px solid #30363d;}
header h1{margin:0;font-size:32px;color:#58a6ff;}
header p{color:#8b949e;margin-top:6px;font-size:15px;}
header .subheader{margin-top:10px;display:flex;justify-content:center;align-items:center;gap:15px;}
button{background:#238636;color:#fff;border:none;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:14px;}
button:hover{background:#2ea043;}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:25px;padding:30px;max-width:1800px;margin:auto;}
.card{background:#1c2128;border-radius:14px;overflow:hidden;box-shadow:0 6px 20px rgba(0,0,0,0.5);transition:.25s;}
.card:hover{transform:translateY(-8px);box-shadow:0 10px 28px rgba(0,0,0,0.65);}
.poster{width:100%;height:420px;object-fit:cover;background:#30363d;}
.content{padding:18px;}
.title{font-size:20px;font-weight:bold;margin:0 0 8px;color:#f0f6fc;}
.meta{font-size:14px;color:#8b949e;margin-bottom:8px;}
.percent,.price{font-size:15px;font-weight:bold;color:#58a6ff;margin-bottom:8px;}
.imdb-link{display:inline-block;padding:10px 14px;background:#238636;color:#fff;text-decoration:none;border-radius:8px;font-size:14px;}
.imdb-link:hover{background:#2ea043;}
footer{text-align:center;padding:18px;margin-top:40px;background:#161b22;border-top:2px solid #30363d;color:#8b949e;font-size:14px;}
</style>
</head>
<body>
<header>
<h1>Weekly Movie Showcase</h1>
<div class="subheader">
<p>Data from <strong><?= htmlspecialchars($yearWeek) ?></strong></p>
<form action="alldata.php" method="get">
<button type="submit">All Data</button>
</form>
</div>
</header>

<div class="grid">
<?php foreach ($moviesArray as $movie):
    $title = $movie['title'];
    $stats = $movie['stats'];
    $data = getMovieData($title,$apiKey);
    if($data && $data["Response"]=="True"):
        $percent = $stats["total"]>0 ? round(($stats["genz"]/$stats["total"])*100,2):0;
        $price = getRecommendedPrice($data['Runtime'],$stats['weekly'],$stats['total'],$data['Year']);
?>
<div class="card">
<img class="poster" src="<?= $data['Poster']!=='N/A' ? $data['Poster']:'https://via.placeholder.com/280x420?text=No+Image' ?>" alt="Poster">
<div class="content">
<div class="title"><?= htmlspecialchars($data['Title']) ?> (<?= htmlspecialchars($data['Year']) ?>)</div>
<div class="meta">Director: <?= htmlspecialchars($data['Director']) ?></div>
<div class="meta">Genre: <?= htmlspecialchars($data['Genre']) ?></div>
<div class="meta">Runtime: <?= htmlspecialchars($data['Runtime']) ?></div>
<div class="percent">Gen Z Attendance: <?= number_format($stats['genz'],0,",",".") ?> (<?= $percent ?>%)</div>
<div class="price">Recommended Ticket Price: €<?= number_format($price,2,",",".") ?></div>
<a class="imdb-link" href="https://www.imdb.com/title/<?= $data['imdbID'] ?>" target="_blank">View on IMDb</a>
</div>
</div>
<?php endif; endforeach; ?>
</div>

<footer>
<p>By Melle Koot</p>
</footer>
</body>
</html>
