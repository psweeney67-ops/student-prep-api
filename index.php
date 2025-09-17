<?php
// Intelligence Briefing API - Front Controller
// This script handles incoming API requests, validates them,
// and launches the background worker for long-running tasks.

// --- Error Handling & Logging Setup ---
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// Note: Render logs anything sent to stderr.
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
    // This is a server configuration issue, log it.
    error_log("CRITICAL: APP_SECRET_KEY is not configured.");
    // Send a generic error to the client.
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

// --- Routing ---
$action = $_GET['action'] ?? null;
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
            
            // --- MODIFIED: More robust background process launch with logging ---
            error_log("Attempting to launch worker for job ID: $job_id");
            
            // This command is designed to be non-blocking.
            // It executes worker.php and immediately returns.
            $command = "nohup php worker.php " . escapeshellarg($job_id) . " > /dev/null 2>&1 & echo $!";
            $pid = exec($command);

            if (empty($pid) || !is_numeric($pid)) {
                 error_log("Failed to launch worker for job ID: $job_id. exec() did not return a valid PID.");
                 throw new Exception("Failed to start the briefing generation process.");
            }
            
            error_log("Worker for job ID $job_id launched successfully with PID: $pid");
            
            http_response_code(202); // 202 Accepted
            echo json_encode(['success' => true, 'job_id' => $job_id, 'status' => 'queued']);
            break;

        case 'briefing-status':
            $job_id = $_GET['job_id'] ?? null;
            if (empty($job_id)) {
                throw new Exception("job_id is required.", 400);
            }
            
            $job_file = '/data/jobs/' . basename($job_id) . '.json';
            if (!file_exists($job_file)) {
                throw new Exception("Job not found.", 404);
            }
            
            $job_data = json_decode(file_get_contents($job_file), true);
            echo json_encode($job_data);
            break;

        default:
            throw new Exception("Invalid action. Use ?action=create-briefing or ?action=briefing-status", 400);
    }
} catch (Exception $e) {
    $code = $e->getCode() >= 400 ? $e->getCode() : 400;
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

