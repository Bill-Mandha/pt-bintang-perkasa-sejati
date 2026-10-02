
<?php
session_start();

/*
|--------------------------------------------------------------------------
| KONFIGURASI DATABASE
|--------------------------------------------------------------------------
*/

$dbHost = 'localhost';
$dbName = 'pt_bintang_perkasa_sejati';
$dbUser = 'root';
$dbPass = '';

/*
|--------------------------------------------------------------------------
| KONFIGURASI SYNLOGY DRIVE
|--------------------------------------------------------------------------
*/

    $synologyPath = __DIR__ . '/uploads';

/*
|--------------------------------------------------------------------------
| KONFIGURASI GEMINI
|--------------------------------------------------------------------------
| Isi API key melalui environment variable GEMINI_API_KEY.
*/

$geminiApiKey = getenv('GEMINI_API_KEY') ?: '';

/*
|--------------------------------------------------------------------------
| KONEKSI DATABASE
|--------------------------------------------------------------------------
*/

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal: ' . $e->getMessage());
}

/*
|--------------------------------------------------------------------------
| FUNGSI BANTU
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectHome()
{
    header('Location: index.php');
    exit;
}

function getReports(PDO $pdo)
{
    $sql = "
        SELECT
            r.id,
            r.report_number,
            r.title,
            r.report_date,
            r.description,
            r.status,
            r.created_at,
            u.name AS creator
        FROM reports r
        LEFT JOIN users u
            ON u.id = r.created_by
        ORDER BY r.created_at DESC
        LIMIT 100
    ";

    return $pdo->query($sql)->fetchAll();
}

function getCurrentUser(PDO $pdo)
{
    // Gunakan ID dari session login jika tersedia.
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare(
            "SELECT id, name, role
             FROM users
             WHERE id = ?"
        );

        $stmt->execute([
            (int) $_SESSION['user_id']
        ]);

        $user = $stmt->fetch();

        if ($user) {
            return $user;
        }
    }

    // Fallback untuk pengujian lokal.
    // Gunakan akun pertama yang tersedia.
    return $pdo->query(
        "SELECT id, name, role
         FROM users
         ORDER BY id ASC
         LIMIT 1"
    )->fetch() ?: null;
}

function getLatestReportTitles(PDO $pdo)
{
    $stmt = $pdo->query(
        "SELECT title, report_date, status
         FROM reports
         ORDER BY created_at DESC
         LIMIT 10"
    );

    return $stmt->fetchAll();
}

/*
|--------------------------------------------------------------------------
| USER AKTIF
|--------------------------------------------------------------------------
*/

$currentUser = getCurrentUser($pdo);

$message = '';
$error = '';

/*
|--------------------------------------------------------------------------
| UPLOAD LAPORAN
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload') {

    $title = trim($_POST['title'] ?? '');
    $reportDate = $_POST['report_date'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if (!$currentUser) {
        $error = 'Belum ada pengguna. Tambahkan akun pada tabel users terlebih dahulu.';
    } elseif ($title === '') {
        $error = 'Judul laporan wajib diisi.';
    } elseif ($reportDate === '') {
        $error = 'Tanggal laporan wajib diisi.';
    } elseif (
        empty($_FILES['report_file'])
        || $_FILES['report_file']['error'] !== UPLOAD_ERR_OK
    ) {
        $error = 'File laporan wajib diunggah.';
    } else {
        $file = $_FILES['report_file'];

        $maxSize = 20 * 1024 * 1024;

        $allowedExtensions = [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'csv',
            'txt',
            'jpg',
            'jpeg',
            'png'
        ];

        $originalName = basename($file['name']);
        $extension = strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );

        if ($file['size'] > $maxSize) {
            $error = 'Ukuran file maksimal 20 MB.';
        } elseif (
            !in_array($extension, $allowedExtensions, true)
        ) {
            $error = 'Jenis file tidak diperbolehkan.';
        } else {
            $uploadDir = __DIR__ . '/uploads/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0775, true);
            }

            $newFileName =
                bin2hex(random_bytes(16))
                . '.'
                . $extension;

            $localPath = $uploadDir . $newFileName;

            if (!move_uploaded_file(
                $file['tmp_name'],
                $localPath
            )) {
                $error = 'Gagal menyimpan file di server.';
            } else {
                $relativePath = 'uploads/' . $newFileName;

                // Salin file ke folder Synology Drive.
                $synologySynced = false;

                if (!is_dir($synologyPath)) {
                    @mkdir($synologyPath, 0775, true);
                }

                if (is_dir($synologyPath)
                    && is_writable($synologyPath)) {

                    $synologyFile =
                        rtrim($synologyPath, '/\\')
                        . DIRECTORY_SEPARATOR
                        . $newFileName;

                    $synologySynced = copy(
                        $localPath,
                        $synologyFile
                    );
                }

                try {
                    $pdo->beginTransaction();

                    /*
                    |------------------------------------------------------
                    | SIMPAN DATA LAPORAN
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO reports (
                            title,
                            created_by,
                            report_date,
                            description
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $title,
                        $currentUser['id'],
                        $reportDate,
                        $description !== ''
                            ? $description
                            : null
                    ]);

                    $reportId = $pdo->lastInsertId();

                    /*
                    |------------------------------------------------------
                    | SIMPAN INFORMASI FILE
                    |------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        INSERT INTO report_files (
                            report_id,
                            file_name,
                            file_path,
                            file_type
                        )
                        VALUES (?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $reportId,
                        $originalName,
                        $relativePath,
                        $file['type'] ?? null
                    ]);

                    $pdo->commit();

                    $message = $synologySynced
                        ? 'Laporan berhasil disimpan dan disalin ke Synology Drive.'
                        : 'Laporan berhasil disimpan di server lokal. Salinan Synology belum berhasil.';

                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if (file_exists($localPath)) {
                        unlink($localPath);
                    }

                    $error = 'Gagal menyimpan laporan: '
                        . $e->getMessage();
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| TANYA AI GEMINI
|--------------------------------------------------------------------------
*/

$aiAnswer = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'ask_ai') {

    $question = trim($_POST['question'] ?? '');

    if ($question === '') {
        $error = 'Pertanyaan AI tidak boleh kosong.';
    } elseif ($geminiApiKey === '') {
        $error = 'API key Gemini belum dikonfigurasi.';
    } else {
        try {
            $reports = getLatestReportTitles($pdo);

            $reportContext = '';

            foreach ($reports as $report) {
                $reportContext .=
                    '- Judul: ' . $report['title']
                    . ', tanggal: ' . $report['report_date']
                    . ', status: ' . $report['status']
                    . "\n";
            }

            if ($reportContext === '') {
                $reportContext = 'Belum ada laporan.';
            }

            $prompt = "
                Kamu adalah asisten AI untuk sistem
                manajemen laporan PT Bintang Perkasa Sejati.

                Jawab dalam bahasa Indonesia.

                Gunakan daftar laporan berikut sebagai
                konteks untuk menjawab pertanyaan.

                Jangan mengarang data yang tidak tersedia.
                Jika informasi tidak ada, sampaikan dengan jujur.

                DAFTAR LAPORAN:
                $reportContext

                PERTANYAAN:
                $question
            ";

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt]
                        ]
                    ]
                ]
            ];

            $url =
                'https://generativelanguage.googleapis.com/'
                . 'v1beta/models/gemini-2.5-flash:generateContent'
                . '?key='
                . urlencode($geminiApiKey);

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 60
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            $curlError = curl_error($ch);

            curl_close($ch);

            if ($response === false) {
                throw new Exception($curlError);
            }

            $result = json_decode($response, true);

            if ($httpCode < 200 || $httpCode >= 300) {
                $apiMessage =
                    $result['error']['message']
                    ?? 'Permintaan Gemini gagal.';

                throw new Exception($apiMessage);
            }

            $aiAnswer =
                $result['candidates'][0]['content']['parts'][0]['text']
                ?? 'Gemini tidak memberikan jawaban.';

        } catch (Throwable $e) {
            $error = 'Gemini error: ' . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| AMBIL DATA LAPORAN
|--------------------------------------------------------------------------
*/

$reports = getReports($pdo);

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>PT Bintang Perkasa Sejati</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            color: #1f2937;
        }

        header {
            background: #123c69;
            color: white;
            padding: 22px 30px;
        }

        header h1 {
            margin: 0 0 6px;
            font-size: 24px;
        }

        header p {
            margin: 0;
            color: #dbeafe;
        }

        main {
            max-width: 1200px;
            margin: 25px auto;
            padding: 0 20px;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .card {
            background: white;
            padding: 22px;
            border-radius: 10px;
            box-shadow: 0 2px 8px #0000000c;
            margin-bottom: 20px;
        }

        h2 {
            margin-top: 0;
            font-size: 20px;
        }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        input,
        textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font: inherit;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            margin-top: 15px;
            padding: 11px 18px;
            border: 0;
            border-radius: 6px;
            background: #1769aa;
            color: white;
            cursor: pointer;
            font-weight: bold;
        }

        button:hover {
            background: #104f83;
        }

        .alert {
            padding: 13px;
            border-radius: 6px;
            margin-bottom: 18px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .ai-answer {
            margin-top: 18px;
            padding: 15px;
            background: #eff6ff;
            border-radius: 6px;
            white-space: pre-wrap;
            line-height: 1.6;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f8fafc;
        }

        .muted {
            color: #64748b;
            font-size: 13px;
        }

        @media (max-width: 750px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>PT Bintang Perkasa Sejati</h1>
    <p>Sistem Manajemen Laporan dan Tanya AI</p>
</header>

<main>

    <?php if ($message): ?>
        <div class="alert success">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid">

        <!-- FORM UPLOAD LAPORAN -->

        <section class="card">
            <h2>Upload Laporan</h2>

            <p class="muted">
                Format PDF, Word, Excel, CSV, TXT, JPG, PNG.
                Maksimal 20 MB.
            </p>

            <form
                method="POST"
                enctype="multipart/form-data"
            >
                <input
                    type="hidden"
                    name="action"
                    value="upload"
                >

                <label for="title">
                    Judul Laporan
                </label>

                <input
                    type="text"
                    id="title"
                    name="title"
                    maxlength="255"
                    required
                >

                <label for="report_date">
                    Tanggal Laporan
                </label>

                <input
                    type="date"
                    id="report_date"
                    name="report_date"
                    value="<?= date('Y-m-d') ?>"
                    required
                >

                <label for="description">
                    Deskripsi
                </label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="Keterangan laporan..."
                ></textarea>

                <label for="report_file">
                    File Laporan
                </label>

                <input
                    type="file"
                    id="report_file"
                    name="report_file"
                    accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.jpg,.jpeg,.png"
                    required
                >

                <button type="submit">
                    Simpan Laporan
                </button>
            </form>
        </section>

        <!-- FORM TANYA AI -->

        <section class="card">
            <h2>Tanya AI Gemini</h2>

            <p class="muted">
                Tanyakan informasi berdasarkan daftar
                laporan yang sudah tersimpan.
            </p>

            <form method="POST">
                <input
                    type="hidden"
                    name="action"
                    value="ask_ai"
                >

                <label for="question">
                    Pertanyaan
                </label>

                <textarea
                    id="question"
                    name="question"
                    placeholder="Contoh: Apa saja laporan yang tersedia?"
                    required
                ><?= e($_POST['question'] ?? '') ?></textarea>

                <button type="submit">
                    Tanya Gemini
                </button>
            </form>

            <?php if ($aiAnswer): ?>
                <div class="ai-answer">
                    <strong>Jawaban Gemini:</strong>

                    <br><br>

                    <?= e($aiAnswer) ?>
                </div>
            <?php endif; ?>
        </section>

    </div>

    <!-- DAFTAR LAPORAN -->

    <section class="card">
        <h2>Daftar Laporan</h2>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Judul</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Dibuat oleh</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($reports)): ?>
                        <tr>
                            <td colspan="5">
                                Belum ada laporan.
                            </td>
                        </tr>
                    <?php else: ?>

                        <?php foreach ($reports as $index => $report): ?>
                            <tr>
                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <?= e($report['title']) ?>
                                </td>

                                <td>
                                    <?= e($report['report_date']) ?>
                                </td>

                                <td>
                                    <?= e($report['status']) ?>
                                </td>

                                <td>
                                    <?= e($report['creator'] ?? '-') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>