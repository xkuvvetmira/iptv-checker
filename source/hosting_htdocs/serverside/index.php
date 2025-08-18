 <?php
// ==================================================================
//  AJAX Request Handler
// ==================================================================
// Bu blok, JavaScript'ten bir AJAX isteği geldiğinde çalışır.
// Form verilerini işler, cURL ile kontrolleri yapar ve sonucu JSON olarak döndürür.
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    // Yanıt başlığını JSON olarak ayarla
    header('Content-Type: application/json');

    // Zaman aşımını artır, çok sayıda hesap kontrol edilebilir
    set_time_limit(300);

    // JS artık tek bir satır gönderdiği için, gelen veriyi doğrudan işliyoruz.
    $line = $_POST['account_line'] ?? '';
    $check_type = $_POST['check_type'] ?? 'url';
    $show_categories = $_POST['show_categories'] ?? '0'; // Kategorilerin gösterilip gösterilmeyeceğini al
    
    $line = trim($line);
    if (empty($line)) {
        echo json_encode(['error' => 'Empty line']);
        exit;
    }

    $api_url = '';
    $original_input = '';
    $host_info = '';

    if ($check_type === 'url') {
        try {
            $url_parts = parse_url($line);
            if (!$url_parts || empty($url_parts['host']) || empty($url_parts['query'])) throw new Exception("Invalid URL");
            parse_str($url_parts['query'], $query_params);
            $username = $query_params['username'] ?? '';
            $password = $query_params['password'] ?? '';
            if (empty($username)) throw new Exception("Invalid URL");
            $scheme = $url_parts['scheme'] ?? 'http';
            $host = $url_parts['host'];
            $port = $url_parts['port'] ?? '';
            $host_info = $host . ($port ? ':' . $port : '');
            $api_url = "{$scheme}://{$host_info}/player_api.php?username=" . urlencode($username) . "&password=" . urlencode($password);
            $original_input = $line;
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
    } else { // user_pass
        list($host_info, $user_pass) = explode('|', $line, 2);
        list($user, $pass) = array_map('trim', explode(':', $user_pass, 2));
        $protocol = (strpos($host_info, 'https://') === 0) ? 'https' : 'http';
        $host_clean = preg_replace('/^https?:\/\//', '', $host_info);
        $api_url = "{$protocol}://{$host_clean}/player_api.php?username=" . urlencode($user) . "&password=" . urlencode($pass);
        $original_input = $host_info . ' | ' . $user . ':' . $pass;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response_time = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000); // Milisaniye
    curl_close($ch);

    $data = json_decode($response, true);
    $result_row = ['original_input' => $original_input, 'response_time' => $response_time];
    
    if ($http_code == 200 && isset($data['user_info']['auth']) && $data['user_info']['auth'] === 1) {
        $user_info = $data['user_info'];
        if ($user_info['status'] === 'Active') {
            $result_row['status_badge'] = ['text' => 'Aktif', 'class' => 'success'];
        } else {
            $result_row['status_badge'] = ['text' => $user_info['status'], 'class' => 'warning'];
        }
        
        $categories_str = 'N/A';
        // Sadece kategoriler isteniyorsa get.php isteği gönder
        if ($user_info['status'] === 'Active' && $show_categories === '1') {
            $get_url = str_replace('player_api.php', 'get.php', $api_url) . '&type=m3u_plus&output=ts';
            $ch_cat = curl_init();
            curl_setopt($ch_cat, CURLOPT_URL, $get_url);
            curl_setopt($ch_cat, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch_cat, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch_cat, CURLOPT_USERAGENT, 'Mozilla/5.0');
            $m3u_content = curl_exec($ch_cat);
            curl_close($ch_cat);

            if ($m3u_content) {
                preg_match_all('/group-title="([^"]+)"/', $m3u_content, $matches);
                if (!empty($matches[1])) {
                    $categories = array_unique($matches[1]);
                    $categories_str = implode(', ', $categories);
                }
            }
        }

        $result_row['user_info'] = [
            'status' => $user_info['status'] ?? 'N/A',
            'created_at' => $user_info['created_at'] ?? 0,
            'exp_date' => $user_info['exp_date'] ?? 0,
            'is_trial' => $user_info['is_trial'] ?? '0',
            'active_cons' => $user_info['active_cons'] ?? 'N/A',
            'max_connections' => $user_info['max_connections'] ?? 'N/A',
            'message' => $data['user_info']['message'] ?? '',
            'categories' => $categories_str
        ];
    } else {
        $status_text = 'Çalışmıyor';
        if ($http_code != 200) $status_text = "Hata: {$http_code}";
        $result_row['status_badge'] = ['text' => $status_text, 'class' => 'danger'];
        $result_row['user_info'] = null;
    }
    
    echo json_encode(['result' => $result_row]);
    exit;
}

// ==================================================================
//  Helper Functions
// ==================================================================
function formatRemainingDuration($end_timestamp) {
    if (!$end_timestamp || $end_timestamp < time()) return 'Süre Dolmuş';
    try {
        $end = new DateTime("@{$end_timestamp}");
        $now = new DateTime();
        $interval = $now->diff($end);
        return $interval->format('%y yıl, %m ay, %d gün, %h saat');
    } catch (Exception $e) {
        return 'Hesaplanamadı';
    }
}
function formatDate($timestamp) {
    if (!$timestamp) return 'N/A';
    return date('d.m.Y H:i', $timestamp);
}

// ==================================================================
//  HTML Page Render
// ==================================================================
?>
<!DOCTYPE html>
<html lang="tr" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KuvvetIPTV - Serverside</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
    <style>
        :root {
            --bs-body-bg: #f0f2f5;
            --bs-body-color: #212529;
            --bs-card-bg: #fff;
            --bs-card-header-bg: #fff;
            --bs-table-striped-bg: rgba(0,0,0,0.03);
            --bs-table-hover-bg: rgba(0,0,0,0.05);
            --bs-table-thead-bg: #e9ecef;
        }
        [data-bs-theme="dark"] {
            --bs-body-bg: #1a1a1a;
            --bs-body-color: #dee2e6;
            --bs-card-bg: #2c2c2c;
            --bs-card-header-bg: #2c2c2c;
            --bs-form-control-bg: #3a3a3a;
            --bs-form-control-color: #fff;
            --bs-form-control-border-color: #555;
            --bs-table-bg: #2c2c2c;
            --bs-table-striped-bg: #3a3a3a;
            --bs-table-hover-bg: #4a4a4a;
            --bs-table-color: #dee2e6;
            --bs-table-border-color: #555;
            --bs-badge-color: #212529;
            --bs-table-thead-bg: #3a3a3a;
        }
        body { transition: background-color 0.3s ease; }
        .card { border: none; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .card-header { background-color: var(--bs-card-header-bg) !important; }
        .form-check-label { cursor: pointer; }
        .results-table th { font-weight: 600; }
        .results-table thead { background-color: var(--bs-table-thead-bg); }
        .btn-check:checked+.btn-outline-primary { color: #fff; }
        #raw-output { font-family: monospace; white-space: pre; background-color: var(--bs-form-control-bg); color: var(--bs-form-control-color); border-color: var(--bs-form-control-border-color); }
        .results-body { max-height: 60vh; overflow-y: auto; }
        @keyframes rgbColor { 0% { color: red; } 33% { color: lime; } 66% { color: blue; } 100% { color: red; } }
    </style>
</head>
<body>
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h1 class="h3 mb-0"><i class="fa-solid fa-satellite-dish text-primary"></i> KuvvetIPTV - Serverside</h1>
            <button class="btn btn-outline-secondary" id="theme-toggler">
                <i class="fa-solid fa-moon"></i>
            </button>
        </div>
        <div class="card">
            <div class="card-body p-4">
                <form id="checker-form">
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="mb-3 text-center">
                                <div class="btn-group" role="group">
                                    <input type="radio" class="btn-check" name="check_type" id="url_check_type" value="url" checked onchange="toggleInput(this.value)">
                                    <label class="btn btn-outline-primary" for="url_check_type"><i class="fa-solid fa-link"></i> M3U URL</label>

                                    <input type="radio" class="btn-check" name="check_type" id="user_pass_check_type" value="user_pass" onchange="toggleInput(this.value)">
                                    <label class="btn btn-outline-primary" for="user_pass_check_type"><i class="fa-solid fa-user-lock"></i> User:Pass</label>
                                </div>
                            </div>
                            <div id="url_input_area">
                                <textarea class="form-control" name="url_list" placeholder="URL'leri buraya yapıştırın" style="height: 200px"></textarea>
                            </div>
                            <div id="user_pass_input_area" style="display: none;">
                                <input type="text" class="form-control mb-2" name="host_port" placeholder="Host:Port (http/https olmadan)">
                                <textarea class="form-control" name="user_pass_list" placeholder="User:Pass" style="height: 155px"></textarea>
                            </div>
                             <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="deduplicate" name="deduplicate" checked>
                                <label class="form-check-label" for="deduplicate">Kopyaları Sil</label>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="card bg-light-subtle h-100">
                                <div class="card-body">
                                    <h5 class="card-title mb-3"><i class="fa-solid fa-list-check"></i> Capturelar</h5>
                                    <div class="row">
                                        <div class="col-6">
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="start_date" id="col_start" name="columns[]" checked><label class="form-check-label" for="col_start">Başlangıç</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="end_date" id="col_end" name="columns[]" checked><label class="form-check-label" for="col_end">Bitiş</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="remaining_time" id="col_remaining" name="columns[]" checked><label class="form-check-label" for="col_remaining">Kalan Süre</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="max_connections" id="col_max" name="columns[]" checked><label class="form-check-label" for="col_max">Max Conn.</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="response_time" id="col_ping" name="columns[]" checked><label class="form-check-label" for="col_ping">Ping (ms)</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="categories" id="col_cat" name="columns[]"><label class="form-check-label" for="col_cat">Kategoriler</label><br><small> (checki yavaşlatır)</small></div>
                                        </div>
                                        <div class="col-6">
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="status" id="col_status" name="columns[]"><label class="form-check-label" for="col_status">Durum</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="is_trial" id="col_trial" name="columns[]"><label class="form-check-label" for="col_trial">Deneme</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="active_cons" id="col_active" name="columns[]"><label class="form-check-label" for="col_active">Aktif Conn.</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="message" id="col_msg" name="columns[]"><label class="form-check-label" for="col_msg">Mesaj</label></div>
                                            <div class="form-check"><input class="form-check-input" type="checkbox" value="checking_date" id="col_check_date" name="columns[]"><label class="form-check-label" for="col_check_date">Check Tarihi</label></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="d-grid mt-3">
                        <button type="submit" id="submit-btn" class="btn btn-primary btn-lg"><i class="fa-solid fa-rocket"></i> Başlat</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="results-container" class="mt-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary active" id="show-table-btn"><i class="fa-solid fa-table-list"></i> Tablo</button>
                        <button class="btn btn-outline-secondary" id="show-raw-btn"><i class="fa-solid fa-file-lines"></i> Raw</button>
                    </div>
                    <div>
                        <span class="badge bg-success fs-6 me-2">Hits: <span id="hit-count">0</span></span>
                        <span class="badge bg-danger fs-6">Decs: <span id="dec-count">0</span></span>
                    </div>
                </div>
                <div class="results-body">
                    <div id="table-view">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover mb-0 results-table">
                                <thead></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="raw-view" style="display: none;">
                        <div class="p-3">
                            <textarea id="raw-output" class="form-control" rows="10" readonly></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <footer class="text-center text-muted mt-4 pb-2">
            <small>
                <strong>
                    <a href="https://kuvvetmira.ct.ws" style="text-decoration:none; animation: rgbColor 3s infinite;">kuvvetmira</a>
                </strong>
            </small>
        </footer>
    </div>

    <script>
        // ==================================================================
        //  UI Logic
        // ==================================================================
        function toggleInput(selectedValue) {
            document.getElementById('url_input_area').style.display = selectedValue === 'url' ? 'block' : 'none';
            document.getElementById('user_pass_input_area').style.display = selectedValue === 'user_pass' ? 'block' : 'none';
        }

        const themeToggler = document.getElementById('theme-toggler');
        themeToggler.addEventListener('click', () => {
            const currentTheme = document.documentElement.getAttribute('data-bs-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            themeToggler.innerHTML = newTheme === 'dark' ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
        });

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-bs-theme', savedTheme);
            themeToggler.innerHTML = savedTheme === 'dark' ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
            buildTableHeader(); // Sayfa yüklendiğinde boş tablo başlığını oluştur
        });
        
        document.getElementById('show-table-btn').addEventListener('click', function() {
            document.getElementById('table-view').style.display = 'block';
            document.getElementById('raw-view').style.display = 'none';
            this.classList.add('active');
            document.getElementById('show-raw-btn').classList.remove('active');
        });

        document.getElementById('show-raw-btn').addEventListener('click', function() {
            document.getElementById('table-view').style.display = 'none';
            document.getElementById('raw-view').style.display = 'block';
            this.classList.add('active');
            document.getElementById('show-table-btn').classList.remove('active');
        });

        // ==================================================================
        //  AJAX Form Submission
        // ==================================================================
        const form = document.getElementById('checker-form');
        const submitBtn = document.getElementById('submit-btn');
        const resultsContainer = document.getElementById('results-container');
        const tableHead = document.querySelector('.results-table thead');
        const tableBody = document.querySelector('.results-table tbody');
        const rawOutput = document.getElementById('raw-output');
        const hitCountEl = document.getElementById('hit-count');
        const decCountEl = document.getElementById('dec-count');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checkleniyor...';
            
            // Reset UI
            tableBody.innerHTML = '';
            rawOutput.value = '';
            hitCountEl.textContent = '0';
            decCountEl.textContent = '0';
            let hitCount = 0;
            let decCount = 0;
            let rowCounter = 0;

            // Prepare account list
            const checkType = form.querySelector('input[name="check_type"]:checked').value;
            let accountsToCheck = [];
            if (checkType === 'url') {
                accountsToCheck = form.querySelector('textarea[name="url_list"]').value.split('\n');
            } else {
                const hostPort = form.querySelector('input[name="host_port"]').value;
                const userPassList = form.querySelector('textarea[name="user_pass_list"]').value.split('\n');
                accountsToCheck = userPassList.map(line => `${hostPort}|${line}`);
            }

            let filteredAccounts = accountsToCheck.map(line => line.trim()).filter(line => line);
            if (form.querySelector('#deduplicate').checked) {
                filteredAccounts = [...new Set(filteredAccounts)];
            }
            
            buildTableHeader();

            for (const accountLine of filteredAccounts) {
                const formData = new FormData();
                formData.append('account_line', accountLine);
                formData.append('check_type', checkType);
                const showCategories = document.getElementById('col_cat').checked;
                formData.append('show_categories', showCategories ? '1' : '0');

                try {
                    const response = await fetch('index.php', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: formData
                    });
                    if (!response.ok) continue;

                    const data = await response.json();
                    if (data.error || !data.result) continue;

                    const rowData = data.result;
                    rowCounter++;
                    
                    if (rowData.status_badge.class === 'success') {
                        hitCount++;
                    } else {
                        decCount++;
                    }
                    hitCountEl.textContent = hitCount;
                    decCountEl.textContent = decCount;

                    appendResult(rowData, rowCounter);

                } catch (error) {
                    console.error('Fetch error for line:', accountLine, error);
                }
            }

            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fa-solid fa-rocket"></i> Başlat';
        });

        function buildTableHeader() {
            const columns = Array.from(document.querySelectorAll('input[name="columns[]"]:checked')).map(cb => cb.value);
            let headerRow = '<tr><th>#</th><th>Iptv</th><th>Durum</th>';
            if (columns.includes('response_time')) headerRow += '<th>Ping</th>';
            if (columns.includes('start_date')) headerRow += '<th>Başlangıç</th>';
            if (columns.includes('end_date')) headerRow += '<th>Bitiş</th>';
            if (columns.includes('remaining_time')) headerRow += '<th>Kalan Süre</th>';
            if (columns.includes('max_connections')) headerRow += '<th>Max Conn.</th>';
            if (columns.includes('active_cons')) headerRow += '<th>Aktif Conn.</th>';
            if (columns.includes('is_trial')) headerRow += '<th>Deneme</th>';
            if (columns.includes('status')) headerRow += '<th>Durum</th>';
            if (columns.includes('message')) headerRow += '<th>Mesaj</th>';
            if (columns.includes('categories')) headerRow += '<th>Kategoriler</th>';
            headerRow += '</tr>';
            tableHead.innerHTML = headerRow;
        }

        function appendResult(row, index) {
            const columns = Array.from(document.querySelectorAll('input[name="columns[]"]:checked')).map(cb => cb.value);
            const checkDate = new Date().toLocaleString('tr-TR');
            const columnLabels = {
                response_time: "Ping", start_date: "Başlangıç Tarihi", end_date: "Bitiş Tarihi",
                remaining_time: "Kalan Süre", max_connections: "Max Bağlantı", active_cons: "Aktif Bağlantı",
                is_trial: "Deneme Sürümü", status: "Durum", message: "Sunucu Mesajı",
                categories: "Kategoriler", checking_date: "Check Tarihi"
            };

            const userInfo = row.user_info;
            let tableRowHTML = `<tr><td>${index}</td><td><small>${escapeHtml(row.original_input)}</small></td><td><span class="badge bg-${row.status_badge.class}">${escapeHtml(row.status_badge.text)}</span></td>`;
            let rawLineParts = [row.original_input];

            if (columns.includes('response_time')) { const val = `${row.response_time} ms`; tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.response_time}: ${val}`); }
            if (userInfo) {
                if (columns.includes('start_date')) { const val = formatDate(userInfo.created_at); tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.start_date}: ${val}`); }
                if (columns.includes('end_date')) { const val = formatDate(userInfo.exp_date); tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.end_date}: ${val}`); }
                if (columns.includes('remaining_time')) { const val = formatRemainingDuration(userInfo.exp_date); tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.remaining_time}: ${val}`); }
                if (columns.includes('max_connections')) { const val = userInfo.max_connections; tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.max_connections}: ${val}`); }
                if (columns.includes('active_cons')) { const val = userInfo.active_cons; tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.active_cons}: ${val}`); }
                if (columns.includes('is_trial')) { const val = userInfo.is_trial == '1' ? 'Evet' : 'Hayır'; tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.is_trial}: ${val}`); }
                if (columns.includes('status')) { const val = escapeHtml(userInfo.status); tableRowHTML += `<td>${val}</td>`; rawLineParts.push(`${columnLabels.status}: ${val}`); }
                if (columns.includes('message')) { const val = escapeHtml(userInfo.message); tableRowHTML += `<td><small>${val}</small></td>`; rawLineParts.push(`${columnLabels.message}: ${val}`); }
                if (columns.includes('categories')) { const val = escapeHtml(userInfo.categories); tableRowHTML += `<td><small>${val}</small></td>`; rawLineParts.push(`${columnLabels.categories}: ${val}`); }
            } else {
                const emptyCells = columns.filter(c => c !== 'response_time').length;
                for(let i=0; i<emptyCells; i++) { tableRowHTML += '<td>N/A</td>'; }
            }
            
            tableRowHTML += '</tr>';
            tableBody.insertAdjacentHTML('beforeend', tableRowHTML);

            if (row.status_badge.class === 'success') {
                if (columns.includes('checking_date')) rawLineParts.push(`${columnLabels.checking_date}: ${checkDate}`);
                rawLineParts.push('Author: Kuvvetmira');
                rawOutput.value += rawLineParts.join(' | ') + '\n';
            }
        }

        // ==================================================================
        //  Helper Functions
        // ==================================================================
        function formatDate(timestamp) {
            if (!timestamp) return 'N/A';
            return new Date(timestamp * 1000).toLocaleString('tr-TR');
        }

        function formatRemainingDuration(end_timestamp) {
            if (!end_timestamp) return 'N/A';
            const now = Math.floor(Date.now() / 1000);
            if (end_timestamp < now) return 'Süre Dolmuş';
            
            let seconds = end_timestamp - now;
            const years = Math.floor(seconds / 31536000); seconds %= 31536000;
            const months = Math.floor(seconds / 2592000); seconds %= 2592000;
            const days = Math.floor(seconds / 86400); seconds %= 86400;
            const hours = Math.floor(seconds / 3600);
            
            return `${years} yıl, ${months} ay, ${days} gün, ${hours} saat`;
        }
        
        function escapeHtml(unsafe) {
            if (typeof unsafe !== 'string') return unsafe;
            return unsafe.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
    </script>
</body>
</html>