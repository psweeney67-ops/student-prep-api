<?php
// Intelligence Briefing API - Main Endpoint
// This script handles incoming API requests. It has two primary functions:
// 1. POST /create-briefing: Starts a new, long-running briefing job.
// 2. GET /briefing-status/{job_id}: Checks the status of a job.

// --- Bootstrap & Security ---
header('Content-Type: application/json');

// Get the secret key from environment variables for security.
$app_secret_key = getenv('APP_SECRET_KEY');
if (!$app_secret_key) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: APP_SECRET_KEY is not set.']);
    exit;
}

// Check for the API key in the request header.
$provided_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($provided_key !== $app_secret_key) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// --- Configuration ---
// These paths must match the mount path set in Render's service settings.
$data_path = '/var/data';
$jobs_path = $data_path . '/jobs';
$output_path_briefing = $data_path . '/briefing';
$output_path_podcast = $data_path . '/podcast';

// --- Routing ---
// Simple router based on the request path.
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_method = $_SERVER['REQUEST_METHOD'];

// Ensure required directories exist.
if (!is_dir($jobs_path)) mkdir($jobs_path, 0775, true);

// --- Endpoint Logic ---

// Endpoint: POST /create-briefing
if ($request_path === '/create-briefing' && $request_method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    // Validate input: Company name is required.
    if (empty($data['company_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request: company_name is required.']);
        exit;
    }

    // Generate a unique ID for this job.
    $job_id = uniqid('job_', true);
    $job_file_path = $jobs_path . '/' . $job_id . '.json';

    // Prepare the job data. Job title is optional.
    $job_data = [
        'job_id' => $job_id,
        'status' => 'pending',
        'company_name' => $data['company_name'],
        'job_title' => $data['job_title'] ?? null, // Optional field
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'results' => null,
        'error' => null
    ];

    // Save the job file.
    file_put_contents($job_file_path, json_encode($job_data, JSON_PRETTY_PRINT));

    // --- CRITICAL: Start the background worker ---
    // We use `exec` to run the worker script in the background.
    // The `>` redirects output to /dev/null, and `&` makes it run in the background.
    $command = 'php worker.php ' . escapeshellarg($job_id) . ' > /dev/null 2>&1 &';
    exec($command);

    // Immediately return the job ID to the client.
    http_response_code(202); // 202 Accepted
    echo json_encode(['job_id' => $job_id, 'status' => 'pending']);
    exit;
}

// Endpoint: GET /briefing-status/{job_id}
if (preg_match('/^\/briefing-status\/(job_[a-zA-Z0-9\._]+)$/', $request_path, $matches) && $request_method === 'GET') {
    $job_id = $matches[1];
    $job_file_path = $jobs_path . '/' . $job_id . '.json';

    if (!file_exists($job_file_path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found.']);
        exit;
    }

    $job_data = json_decode(file_get_contents($job_file_path), true);
    echo json_encode($job_data);
    exit;
}

// Default 404 for any other route
http_response_code(404);
echo json_encode(['error' => 'Endpoint not found.']);

