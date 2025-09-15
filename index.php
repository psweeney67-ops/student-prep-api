<?php
// Intelligence Briefing API - Main Router
// This file handles incoming API requests, validates them, and either starts a new
// background job or checks the status of an existing one.

// --- Configuration & Security ---

// Set the content type for all responses to JSON
header('Content-Type: application/json');

// The path where job files and final outputs will be stored.
// This MUST be a persistent disk on Render.
define('STORAGE_PATH', '/var/data/jobs');

// Load environment variables for security
$geminiApiKey = getenv('GEMINI_API_KEY');
$appSecretKey = getenv('APP_SECRET_KEY');

// Get the API key from the request header
$clientApiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';

// Security Check: Ensure the request is authorized
if (!$appSecretKey || $clientApiKey !== $appSecretKey) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Security Check: Ensure required environment variables are set
if (!$geminiApiKey) {
    http_response_code(503); // Service Unavailable
    echo json_encode(['error' => 'Server configuration error: Gemini API key is not set.']);
    exit;
}

// Ensure the storage directory exists
if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0755, true);
}


// --- API Routing ---

$request_path = strtok($_SERVER["REQUEST_URI"], '?');

// Route: /create-briefing
// Starts a new background job to create a briefing and podcast.
if ($request_path === '/create-briefing' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handleCreateBriefing();
}
// Route: /briefing-status/{job_id}
// Checks the status and retrieves the results of a job.
elseif (preg_match('/^\/briefing-status\/([a-zA-Z0-9\-]+)$/', $request_path, $matches) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $job_id = $matches[1];
    handleBriefingStatus($job_id);
}
// Route: Not found
else {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found.']);
}


// --- Route Handlers ---

/**
 * Handles the creation of a new briefing job.
 * Validates input, creates a unique job ID, saves the job file,
 * and starts the background worker process.
 */
function handleCreateBriefing() {
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true);

    $company_name = $data['company_name'] ?? null;
    $industry_trends = $data['industry_trends'] ?? null;

    if (empty($company_name) || empty($industry_trends)) {
        http_response_code(400); // Bad Request
        echo json_encode(['error' => 'Missing required parameters: company_name and industry_trends are required.']);
        exit;
    }

    $job_id = uniqid('briefing-', true);
    $job_file_path = STORAGE_PATH . '/' . $job_id . '.json';

    $job_data = [
        'job_id' => $job_id,
        'status' => 'queued',
        'company_name' => $company_name,
        'industry_trends' => $industry_trends,
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'results' => null
    ];

    file_put_contents($job_file_path, json_encode($job_data, JSON_PRETTY_PRINT));

    // Execute the background worker script asynchronously.
    // The output is redirected to /dev/null to prevent the main process from waiting.
    $command = 'php worker.php ' . escapeshellarg($job_id) . ' > /dev/null 2>&1 &';
    exec($command);

    // Immediately return the job ID to the client
    http_response_code(202); // Accepted
    echo json_encode([
        'message' => 'Briefing job accepted.',
        'job_id' => $job_id,
        'status_url' => '/briefing-status/' . $job_id
    ]);
}


/**
 * Handles status checks for a given job ID.
 * Reads the job file and returns its current state.
 * @param string $job_id The ID of the job to check.
 */
function handleBriefingStatus($job_id) {
    // Sanitize job_id to prevent directory traversal attacks
    if (basename($job_id) !== $job_id) {
         http_response_code(400);
         echo json_encode(['error' => 'Invalid job ID format.']);
         exit;
    }

    $job_file_path = STORAGE_PATH . '/' . $job_id . '.json';

    if (!file_exists($job_file_path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found.']);
        exit;
    }

    $job_data = json_decode(file_get_contents($job_file_path), true);

    http_response_code(200);
    echo json_encode($job_data);
}

