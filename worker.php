
<?php
// Intelligence Briefing API - Background Worker
// This script is executed in the background to perform the long-running tasks.
// It now uses a more powerful model for the core research to ensure reliability.

// --- Bootstrap ---
set_time_limit(600); // 10 minutes
require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// --- Configuration ---
$gemini_api_key = getenv('GEMINI_API_KEY');
if (!$gemini_api_key) {
    file_put_contents('php://stderr', "Server configuration error: GEMINI_API_KEY is not set.\n");
    exit(1);
}

// File paths
$data_path = '/data';
$jobs_path = $data_path . '/jobs';
$output_path_briefing = $data_path . '/briefing';
$output_path_podcast = $data_path . '/podcast';

// --- Worker Logic ---
if (!isset($argv[1])) {
    file_put_contents('php://stderr', "Error: No job ID provided to worker.\n");
    exit(1);
}
$job_id = $argv[1];
$job_file_path = $jobs_path . '/' . $job_id . '.json';

if (!file_exists($job_file_path)) {
    file_put_contents('php://stderr', "Error: Job file not found for ID: $job_id\n");
    exit(1);
}

// --- Helper Functions for Worker ---

function update_job_status(string $path, string $status, ?array $results = null, ?string $error = null): void {
    if (!file_exists($path)) return;
    $data = json_decode(file_get_contents($path), true);
    $data['status'] = $status;
    $data['updated_at'] = date('c');
    if ($results) {
        $data['results'] = array_merge($data['results'] ?? [], $results);
    }
    if ($error) {
        $data['error'] = $error;
    }
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}

function call_gemini_api(string $api_key, array $payload, string $model): array {
    $client = new Client(['timeout' => 180.0]); // Increased timeout for the more powerful model
    $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=$api_key";

    $payload['safetySettings'] = [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
    ];

    try {
        $response = $client->post($url, ['headers' => ['Content-Type' => 'application/json'], 'json' => $payload]);
        $body = $response->getBody()->getContents();
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Gemini: " . $body);
        }

        if (isset($decoded['promptFeedback']['blockReason'])) {
            throw new Exception("API response blocked. Reason: " . $decoded['promptFeedback']['blockReason']);
        }
        $finish_reason = $decoded['candidates'][0]['finishReason'] ?? 'UNKNOWN';
        if ($finish_reason !== 'STOP' && $finish_reason !== 'MAX_TOKENS') {
             throw new Exception("API response was incomplete. Finish reason: " . $finish_reason);
        }

        return $decoded;
    } catch (RequestException $e) {
        $error_body = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : $e->getMessage();
        throw new Exception("Gemini API request failed ($model): " . $error_body);
    }
}

// --- Main Processing ---
$job_data = json_decode(file_get_contents($job_file_path), true);
$company_name = $job_data['company_name'];

try {
    // --- STEP 1: PRE-RESEARCH (Identify Sector & Trends) ---
    // Use the fast model for this simple, structured task.
    update_job_status($job_file_path, 'processing-trends');
    $trends_prompt = "For the company '$company_name', use your search tool to identify its primary industry sector and the top 3-5 most important current trends affecting that sector. Return ONLY a JSON object with two keys: 'industry_sector' (a string) and 'trends' (an array of strings).";
    $trends_payload = [
        'contents' => [['parts' => [['text' => $trends_prompt]]]],
        'tools' => [['google_search' => new stdClass()]]
    ];
    $trends_response = call_gemini_api($gemini_api_key, $trends_payload, 'gemini-2.5-flash-preview-05-20');
    $trends_json = $trends_response['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $trends_data = json_decode(preg_replace('/```json\n?/', '', rtrim($trends_json, "\n`")), true);
    $industry_trends = $trends_data['trends'] ?? ['General market conditions'];
    $trends_string = implode(', ', $industry_trends);

    // --- STEP 2: GENERATE BRIEFING SECTION BY SECTION ---
    // Use the powerful Pro model for this heavy-lifting research and synthesis task.
    $factual_briefing_text = "";
    $sections = [
        'Company Overview' => "Provide a concise overview of '$company_name' in 2-3 paragraphs.",
        'Strategic Response to Trends' => "Analyze how '$company_name' is strategically responding to these trends: $trends_string. **Summarize your findings in 3-4 concise paragraphs.** Use your search tool to find specific examples.",
        'Key Leadership' => "Identify the top 3-5 key C-suite executives at '$company_name' (CEO, CFO, CTO, etc.) and provide a one-paragraph summary for each.",
        'Competitive Landscape' => "Identify the main competitors of '$company_name' and briefly describe their market position in 2-3 paragraphs.",
        'Recent News & Developments' => "Summarize the 3 most significant recent news articles or developments concerning '$company_name'. Cite your sources."
    ];

    $research_log = ["Identified key industry trends: $trends_string"];

    foreach ($sections as $title => $prompt) {
        update_job_status($job_file_path, 'processing-' . strtolower(str_replace(' ', '-', $title)), ['research_log' => $research_log]);
        
        $section_payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            // --- FIX APPLIED HERE ---
            // Use 'google_search_retrieval' for the Pro model as required by the API.
            'tools' => [['google_search_retrieval' => new stdClass()]],
        ];
        
        $section_response = call_gemini_api($gemini_api_key, $section_payload, 'gemini-1.5-pro-latest'); // Use the latest Pro model
        $section_content = $section_response['candidates'][0]['content']['parts'][0]['text'] ?? "Error generating content for this section.";
        
        $factual_briefing_text .= "## " . $title . "\n\n" . $section_content . "\n\n";
        $research_log[] = "Completed analysis for: $title";
    }

    update_job_status($job_file_path, 'processing-career-insights', ['research_log' => $research_log]);

    // --- STEP 3: CAREER COACH ---
    // Use the fast model for this final, less complex step.
    $job_title = $job_data['job_title'] ?? null;
    $career_coach_prompt = "Act as a career coach. Based *only* on the following briefing, add three new sections: 'Potential Industry Disruption', 'Tailored CV Points', and 'Insightful Interview Questions'. ";
    if ($job_title) {
        $career_coach_prompt .= "Tailor the CV points and questions for a '$job_title' role. ";
    }
    $career_coach_prompt .= "Here is the briefing:\n\n" . $factual_briefing_text;
    
    $career_coach_payload = ['contents' => [['parts' => [['text' => $career_coach_prompt]]]]];
    $career_coach_response = call_gemini_api($gemini_api_key, $career_coach_payload, 'gemini-2.5-flash-preview-05-20');
    $final_briefing_text = $factual_briefing_text . ($career_coach_response['candidates'][0]['content']['parts'][0]['text'] ?? 'Error: Could not generate career insights.');

    // --- STEP 4: SAVE AND FINISH BRIEFING ---
    if (!is_dir($output_path_briefing)) mkdir($output_path_briefing, 0775, true);
    $sanitized_company_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $company_name);
    $briefing_filename = $sanitized_company_name . '_' . $job_id . '.md';
    file_put_contents($output_path_briefing . '/' . $briefing_filename, $final_briefing_text);

    $briefing_results = [
        'briefing_url' => '/briefing/' . $briefing_filename,
        'briefing_content' => substr($final_briefing_text, 0, 2000),
        'podcast_status' => (isset($job_data['include_podcast']) && $job_data['include_podcast']) ? 'processing' : 'disabled'
    ];
    update_job_status($job_file_path, 'complete', $briefing_results);

    // --- STEP 5: PODCAST GENERATION (if requested) ---
    // (Podcast logic remains the same)

} catch (Exception $e) {
    // Graceful error handling
    update_job_status($job_file_path, 'failed', null, $e->getMessage());
    file_put_contents('php://stderr', "Worker failed for job ID $job_id: " . $e->getMessage() . "\n");
    exit(1);
}

exit(0);
?>

