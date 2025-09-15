<?php
// Intelligence Briefing API - Background Worker
// This script is executed asynchronously by index.php. It performs the long-running
// tasks of AI-powered research, synthesis, and audio generation.

// --- Initialization ---

// Increase script execution time limit, essential for long-running tasks.
set_time_limit(600); // 10 minutes

// Load Composer dependencies
require_once 'vendor/autoload.php';

// Define the storage path, same as in index.php
define('STORAGE_PATH', '/var/data/jobs');

// Get the job ID passed as a command-line argument from the main API file.
if (!isset($argv[1])) {
    // This script should not be run directly without a job ID.
    exit('Error: No job ID provided.');
}
$job_id = $argv[1];


// --- Job Management Functions ---

/**
 * Updates the status and content of a job file.
 * This function is used throughout the script to report progress.
 * @param string $id The job ID.
 * @param string $status The new status (e.g., 'processing-research', 'failed').
 * @param array|null $data Optional data to add to the 'results' field.
 */
function updateJobStatus($id, $status, $data = null) {
    $file_path = STORAGE_PATH . '/' . $id . '.json';
    if (!file_exists($file_path)) return;

    $job_data = json_decode(file_get_contents($file_path), true);
    $job_data['status'] = $status;
    $job_data['updated_at'] = date('c');

    if ($data !== null) {
        // Merge new data with existing results
        $job_data['results'] = array_merge($job_data['results'] ?? [], $data);
    }

    file_put_contents($file_path, json_encode($job_data, JSON_PRETTY_PRINT));
}

/**
 * Marks a job as failed with a specific error message.
 * @param string $id The job ID.
 * @param string $error_message The reason for the failure.
 */
function failJob($id, $error_message) {
    updateJobStatus($id, 'failed', ['error' => $error_message]);
    exit($error_message); // Exit the worker script
}


// --- Main Worker Logic ---

// 1. Load Job Data
$job_file_path = STORAGE_PATH . '/' . $job_id . '.json';
if (!file_exists($job_file_path)) {
    exit('Error: Job file not found.');
}
$job = json_decode(file_get_contents($job_file_path), true);
$company_name = $job['company_name'];
$industry_trends = $job['industry_trends'];


// 2. Step 1: Research and Synthesize Briefing Document
updateJobStatus($job_id, 'processing-research');

try {
    $geminiApiKey = getenv('GEMINI_API_KEY');
    $client = new \GuzzleHttp\Client();
    $geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent?key=' . $geminiApiKey;

    $system_prompt_research = "You are an intelligence analyst. Your task is to create a detailed briefing document about a company, focusing on specific industry trends. Find at least 10 high-quality sources (news articles, market reports, official statements) to inform your analysis. Your output must be a single JSON object containing a 'briefing_document' (in Markdown format) and the 'sources' you used. Do not include any other text or markdown formatting around the JSON.";

    $user_prompt_research = "Generate a detailed intelligence briefing for the company '$company_name'. The briefing must analyze the impact of these specific industry trends: '" . implode(", ", $industry_trends) . "'. Cite your sources within the document using footnotes like [1], [2], etc., and list them at the end.";

    $payload_research = [
        'contents' => [['parts' => [['text' => $user_prompt_research]]]],
        'tools' => [['google_search' => new stdClass()]],
        'systemInstruction' => ['parts' => [['text' => $system_prompt_research]]]
    ];

    $response_research = $client->post($geminiApiUrl, ['json' => $payload_research]);
    $body_research = json_decode($response_research->getBody()->getContents(), true);
    $research_text = $body_research['candidates'][0]['content']['parts'][0]['text'] ?? '';
    $research_data = json_decode($research_text, true);

    if (json_last_error() !== JSON_ERROR_NONE || !isset($research_data['briefing_document'])) {
        failJob($job_id, 'Failed to parse valid JSON from the research step. Raw response: ' . $research_text);
    }

    $briefing_document = $research_data['briefing_document'];
    $sources = $research_data['sources'];

    // Save the briefing document to a file
    $briefing_file_path = STORAGE_PATH . '/' . $job_id . '_briefing.md';
    file_put_contents($briefing_file_path, $briefing_document);

    updateJobStatus($job_id, 'processing-audio', [
        'briefing_document_url' => '/briefing/' . $job_id . '_briefing.md', // Relative path for client
        'sources' => $sources
    ]);

} catch (Exception $e) {
    failJob($job_id, 'An error occurred during the research step: ' . $e->getMessage());
}


// 3. Step 2: Generate Podcast Script and Audio
$podcast_script = "Here is your intelligence briefing for {$company_name}. " . str_replace(["\n", "\r", "#", "*"], ' ', $briefing_document);

try {
    $ttsApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key=' . $geminiApiKey;
    $payload_tts = [
        'contents' => [['parts' => [['text' => $podcast_script]]]],
        'generationConfig' => [
            'responseModalities' => ["AUDIO"],
            'speechConfig' => [
                'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => "Charon"]]
            ]
        ]
    ];

    $response_tts = $client->post($ttsApiUrl, ['json' => $payload_tts]);
    $body_tts = json_decode($response_tts->getBody()->getContents(), true);

    $audio_base64 = $body_tts['candidates'][0]['content']['parts'][0]['inlineData']['data'] ?? null;
    if (!$audio_base64) {
        failJob($job_id, 'Failed to retrieve audio data from TTS API.');
    }

    $raw_audio_path = STORAGE_PATH . '/' . $job_id . '.raw';
    file_put_contents($raw_audio_path, base64_decode($audio_base64));

    // Convert raw PCM audio to MP3 using ffmpeg
    $mp3_path = STORAGE_PATH . '/' . $job_id . '_podcast.mp3';
    // The API returns 16-bit signed PCM at a 24000 Hz sample rate
    $ffmpeg_command = "ffmpeg -f s16le -ar 24000 -ac 1 -i {$raw_audio_path} {$mp3_path}";
    exec($ffmpeg_command);

    // Clean up the raw audio file
    unlink($raw_audio_path);

    if (!file_exists($mp3_path)) {
        failJob($job_id, 'Failed to convert audio to MP3 using ffmpeg.');
    }

    updateJobStatus($job_id, 'complete', [
        'podcast_url' => '/podcast/' . $job_id . '_podcast.mp3' // Relative path for client
    ]);

} catch (Exception $e) {
    failJob($job_id, 'An error occurred during the audio generation step: ' . $e->getMessage());
}

// Worker has finished successfully.
exit;

