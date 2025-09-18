<?php
// Intelligence Briefing API - Front Controller
// This script handles incoming API requests, validates them,
// and starts a background worker process.

// --- Error Handling & Logging Setup ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php://stderr');

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($exception) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'An internal server error occurred.',
        'details' => $exception->getMessage()
    ]);
});

// --- Configuration ---
$app_secret_key = getenv('APP_SECRET_KEY');
if (empty($app_secret_key)) {
    error_log("CRITICAL: APP_SECRET_KEY is not configured.");
    throw new Exception("API is not configured correctly.");
}

// --- Security Check ---
$client_api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($client_api_key !== $app_secret_key) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// --- FINAL FIX: Robust Routing ---
// This new router understands clean URLs like /create-briefing
$request_uri = strtok($_SERVER['REQUEST_URI'], '?'); // Get the path without query params
$path_parts = explode('/', trim($request_uri, '/'));
$action = $path_parts[0] ?? null; // The first part of the path is the action

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'create-briefing':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception("Method not allowed. Use POST for create-briefing.", 405);
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            $company_name = $input['company_name'] ?? null;
            if (empty($company_name)) {
                throw new Exception("company_name is required.", 400);
            }
            
            $job_id = uniqid('job_');
            $job_data = [
                'job_id' => $job_id,
                'status' => 'queued',
                'company_name' => $company_name,
                'job_title' => $input['job_title'] ?? null,
                'include_podcast' => $input['include_podcast'] ?? true,
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'results' => null,
                'error' => null
            ];
            
            $jobs_path = '/data/jobs';
            if (!is_dir($jobs_path)) {
                mkdir($jobs_path, 0775, true);
            }
            file_put_contents($jobs_path . '/' . $job_id . '.json', json_encode($job_data, JSON_PRETTY_PRINT));
            
            // Reliably start the worker in the background
            $command = "php worker.php " . escapeshellarg($job_id) . " > /dev/null 2>&1 &";
            exec($command);
            
            http_response_code(202); // 202 Accepted
            echo json_encode(['success' => true, 'job_id' => $job_id, 'status' => 'queued']);
            break;

        case 'briefing-status':
            $job_id = $path_parts[1] ?? null; // The job_id is the second part of the path
            if (empty($job_id)) {
                throw new Exception("job_id is required. Use /briefing-status/{job_id}", 400);
            }
            
            $job_file = '/data/jobs/' . basename($job_id) . '.json';
            if (!file_exists($job_file)) {
                throw new Exception("Job not found.", 404);
            }
            
            $job_data = json_decode(file_get_contents($job_file), true);
            echo json_encode($job_data);
            break;

        default:
            // Handle root URL or unknown actions
            if (empty($action)) {
                 echo json_encode(['status' => 'API is running']);
            } else {
                 throw new Exception("Invalid action: $action", 404);
            }
    }
} catch (Exception $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 400;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

