<?php
// A simple front-controller to route API requests without relying on URL rewriting.

// Set common headers
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-API-KEY, Authorization");

// Handle pre-flight CORS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// --- CONFIGURATION ---
// The directory where job files and results are stored. Must be writable.
// Render's persistent disk is mounted at /data
$jobs_dir = '/data/jobs';
if (!is_dir($jobs_dir)) {
    mkdir($jobs_dir, 0777, true);
}

// Get the action from the query parameter
$action = $_GET['action'] ?? null;
$job_id = $_GET['job_id'] ?? null;

// --- ROUTING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create-briefing') {
    // --- Start a new briefing job ---
    try {
        // ... (The rest of the job creation logic remains the same) ...
        $request_data = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON payload');
        }

        $company_name = $request_data['company_name'] ?? null;
        $job_title = $request_data['job_title'] ?? null; // Optional

        if (!$company_name) {
            throw new Exception('company_name is required');
        }

        $new_job_id = uniqid('briefing_', true);
        $job_file_path = "$jobs_dir/$new_job_id.json";

        $job_data = [
            'job_id' => $new_job_id,
            'status' => 'queued',
            'company_name' => $company_name,
            'job_title' => $job_title,
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'result' => null,
            'error' => null
        ];

        file_put_contents($job_file_path, json_encode($job_data, JSON_PRETTY_PRINT));

        // Start the background worker process
        $worker_path = __DIR__ . '/worker.php';
        $command = "php $worker_path " . escapeshellarg($new_job_id) . " > /dev/null 2>&1 &";
        exec($command);

        http_response_code(202); // 202 Accepted
        echo json_encode(['job_id' => $new_job_id, 'status' => 'queued']);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Failed to create job: ' . $e->getMessage()]);
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'briefing-status' && $job_id) {
    // --- Check the status of a job ---
    try {
        $job_file_path = "$jobs_dir/$job_id.json";
        if (!file_exists($job_file_path)) {
            throw new Exception('Job not found');
        }

        $job_data = json_decode(file_get_contents($job_file_path), true);
        
        // If the job is complete, provide URLs to the final files
        if ($job_data['status'] === 'complete' && isset($job_data['result'])) {
             $base_url = 'https://' . $_SERVER['HTTP_HOST'];
             $job_data['result']['briefing_url'] = $base_url . "/index.php?action=get-file&job_id=$job_id&type=briefing";
             $job_data['result']['podcast_url'] = $base_url . "/index.php?action=get-file&job_id=$job_id&type=podcast";
        }
        
        echo json_encode($job_data);

    } catch (Exception $e) {
        http_response_code(404);
        echo json_encode(['error' => $e->getMessage()]);
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get-file' && $job_id) {
    // --- Serve the final briefing document or podcast ---
    $file_type = $_GET['type'] ?? null;
    $file_path = null;
    $content_type = null;
    $file_name = null;
    
    if ($file_type === 'briefing') {
        $file_path = "$jobs_dir/$job_id.md";
        $content_type = 'text/markdown';
        $file_name = "briefing_$job_id.md";
    } elseif ($file_type === 'podcast') {
        $file_path = "$jobs_dir/$job_id.mp3";
        $content_type = 'audio/mpeg';
        $file_name = "podcast_$job_id.mp3";
    }

    if ($file_path && file_exists($file_path)) {
        header("Content-Type: $content_type");
        header("Content-Disposition: attachment; filename=\"$file_name\"");
        readfile($file_path);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'File not found']);
    }
} else {
    // --- Invalid route ---
    http_response_code(404);
    echo json_encode(['error' => 'Invalid endpoint']);
}

