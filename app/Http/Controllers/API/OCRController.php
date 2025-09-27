<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\WorkEntry;
use Carbon\Carbon;

class OCRController extends Controller
{
    /**
     * Process uploaded screenshot and extract work entries
     */
    public function processScreenshot(Request $request)
    {
        error_log("=== OCR REQUEST STARTED ===");
        Log::emergency("OCR REQUEST DEBUG - This should appear!");
        file_put_contents('/tmp/ocr_debug.log', "OCR started: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);

        $request->validate([
            'image' => 'required|image|max:10240',
        ]);

        $userId = auth()->id();

        try {
            // Save uploaded image
            file_put_contents('/tmp/ocr_debug.log', "Starting image processing\n", FILE_APPEND);

            $image = $request->file('image');
            $imagePath = $image->store('ocr_uploads', 'public');
            $fullPath = storage_path('app/public/' . $imagePath);
            file_put_contents('/tmp/ocr_debug.log', "Image saved to: " . $fullPath . "\n", FILE_APPEND);

            Log::info("OCR: Processing image", ['user_id' => $userId, 'path' => $fullPath]);

            // Try GPT-4 Vision first (düzeltildi)
            $result = $this->processWithGPTVision($fullPath);

            if (!$result['success']) {
                Log::warning("GPT-4 Vision failed, falling back to Tesseract", ['error' => $result['error']]);
                $result = $this->processWithTesseract($fullPath);
            }

            if (!$result['success']) {
                throw new \Exception('All OCR methods failed: ' . $result['error']);
            }

            Log::info("OCR: Processing result", ['result' => $result]);

            // Save valid entries to database
            $savedEntries = [];
            $duplicateEntries = [];
            $skippedEntries = [];

            foreach ($result['entries'] as $entryData) {
                try {
                    file_put_contents('/tmp/ocr_debug.log', "Processing entry: " . json_encode($entryData) . "\n", FILE_APPEND);

                    if ($this->isValidEntry($entryData)) {
                        file_put_contents('/tmp/ocr_debug.log', "Entry is valid\n", FILE_APPEND);

                        // Check for duplicate entries
                        $isDuplicate = $this->checkDuplicateEntry($userId, $entryData);

                        if ($isDuplicate) {
                            file_put_contents('/tmp/ocr_debug.log', "Entry is duplicate\n", FILE_APPEND);
                            $duplicateEntries[] = $entryData;
                            continue;
                        }

                        file_put_contents('/tmp/ocr_debug.log', "Creating WorkEntry...\n", FILE_APPEND);

                        $hoursWorked = $this->calculateHoursFromTimes($entryData['start_time'], $entryData['end_time']);
                        file_put_contents('/tmp/ocr_debug.log', "Hours calculated: " . $hoursWorked . "\n", FILE_APPEND);

                        $workEntry = WorkEntry::create([
                            'user_id' => $userId,
                            'date' => $entryData['date'],
                            'hours_worked' => $hoursWorked,
                            'earnings' => $entryData['total_earnings'],
                            'base_pay' => $entryData['base_pay'],
                            'tips' => $entryData['tips'],
                            'service_type' => $entryData['service_type'] ?? $this->determineServiceType($entryData),
                            'notes' => 'OCR imported via ' . $result['method'] . ' - Start: ' . $entryData['start_time'],
                        ]);

                        file_put_contents('/tmp/ocr_debug.log', "WorkEntry created with ID: " . $workEntry->id . "\n", FILE_APPEND);

                        $savedEntries[] = $workEntry;
                    } else {
                        file_put_contents('/tmp/ocr_debug.log', "Entry validation failed\n", FILE_APPEND);
                        $skippedEntries[] = $entryData;
                    }
                } catch (\Exception $e) {
                    file_put_contents('/tmp/ocr_debug.log', "Exception: " . $e->getMessage() . "\n", FILE_APPEND);
                    $skippedEntries[] = $entryData;
                }
            }

            // Clean up uploaded file
            Storage::disk('public')->delete($imagePath);

            return response()->json([
                'success' => true,
                'data' => [
                    'parsed_entries' => $result['entries'],
                    'saved_entries' => count($savedEntries),
                    'duplicate_entries' => count($duplicateEntries),
                    'skipped_entries' => count($skippedEntries),
                    'entries' => $savedEntries,
                    'method' => $result['method'],
                ],
                'message' => $this->buildSummaryMessage($savedEntries, $duplicateEntries, $skippedEntries),
            ]);

        } catch (\Exception $e) {
            Log::error("OCR processing failed", [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Clean up file if it exists
            if (isset($imagePath)) {
                Storage::disk('public')->delete($imagePath);
            }

            return response()->json([
                'success' => false,
                'message' => 'OCR processing failed: ' . $e->getMessage(),
                'error_details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Process image with OpenAI GPT-4 Vision API (DÜZELTILDI)
     */
    private function processWithGPTVision($imagePath)
    {
        file_put_contents('/tmp/ocr_debug.log', "GPT Vision method started\n", FILE_APPEND);

        try {
            $apiKey = env('OPENAI_API_KEY');
            file_put_contents('/tmp/ocr_debug.log', "API Key length: " . strlen($apiKey ?? '') . "\n", FILE_APPEND);

            if (!$apiKey) {
                file_put_contents('/tmp/ocr_debug.log', "No API key found\n", FILE_APPEND);
                return ['success' => false, 'error' => 'OpenAI API key not configured'];
            }

            Log::info("Starting GPT-4 Vision processing", ['path' => $imagePath]);
            file_put_contents('/tmp/ocr_debug.log', "Image path: " . $imagePath . "\n", FILE_APPEND);

            if (!file_exists($imagePath)) {
                file_put_contents('/tmp/ocr_debug.log', "Image file not found\n", FILE_APPEND);
                throw new \Exception('Image file not found: ' . $imagePath);
            }

            file_put_contents('/tmp/ocr_debug.log', "Reading image file...\n", FILE_APPEND);
            $base64Image = base64_encode(file_get_contents($imagePath));
            file_put_contents('/tmp/ocr_debug.log', "Base64 length: " . strlen($base64Image) . "\n", FILE_APPEND);

            $mimeType = $this->getMimeType($imagePath);
            file_put_contents('/tmp/ocr_debug.log', "MIME type: " . $mimeType . "\n", FILE_APPEND);

            file_put_contents('/tmp/ocr_debug.log', "Sending OpenAI request...\n", FILE_APPEND);

            $currentYear = date('Y');

            $response = Http::timeout(60)->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Extract work entries from this Amazon Flex earnings screenshot.

Return JSON in this format:
{
  "entries": [
    {
      "date": "' . $currentYear . '-09-26",
      "start_time": "9:30 AM",
      "end_time": "1:30 PM",
      "total_earnings": 58.50,
      "base_pay": 45.00,
      "tips": 13.50,
      "service_type": "whole_foods"
    }
  ]
}

Rules:
- Date: YYYY-MM-DD format (use ' . $currentYear . ' if year not shown)
- Times: "H:MM AM/PM" format with space
- total_earnings: Main dollar amount visible
- If base/tips shown separately: include both, service_type="whole_foods"
- If only total shown: base_pay=null, tips=null, service_type="logistics"
- Include ALL visible entries
- Return only valid JSON'
                            ],
                            [
                                'type' => 'image_url',
                                'image_url' => [
                                    'url' => "data:{$mimeType};base64,{$base64Image}",
                                    'detail' => 'high'
                                ]
                            ]
                        ]
                    ]
                ],
                'response_format' => [
                    'type' => 'json_object'
                ],
                'max_tokens' => 2000,
                'temperature' => 0.1
            ]);

            file_put_contents('/tmp/ocr_debug.log', "OpenAI response status: " . $response->status() . "\n", FILE_APPEND);
            file_put_contents('/tmp/ocr_debug.log', "OpenAI response successful: " . ($response->successful() ? 'YES' : 'NO') . "\n", FILE_APPEND);

            if (!$response->successful()) {
                $errorBody = $response->body();
                file_put_contents('/tmp/ocr_debug.log', "OpenAI error: " . $errorBody . "\n", FILE_APPEND);
                $errorData = $response->json();
                return ['success' => false, 'error' => 'OpenAI API failed: ' . ($errorData['error']['message'] ?? $errorBody)];
            }

            if ($response->successful()) {
                $data = $response->json();
                file_put_contents('/tmp/ocr_debug.log', "OpenAI response received\n", FILE_APPEND);

                if (!isset($data['choices'][0]['message']['content'])) {
                    file_put_contents('/tmp/ocr_debug.log', "Invalid OpenAI response structure\n", FILE_APPEND);
                    throw new \Exception('Invalid OpenAI API response structure');
                }

                $content = $data['choices'][0]['message']['content'];
                file_put_contents('/tmp/ocr_debug.log', "GPT response content: " . substr($content, 0, 200) . "...\n", FILE_APPEND);

                Log::info("GPT-4 Vision raw response", ['content' => $content]);

                $parsedData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    file_put_contents('/tmp/ocr_debug.log', "JSON decode error: " . json_last_error_msg() . "\n", FILE_APPEND);
                    throw new \Exception('Invalid JSON response from GPT-4: ' . json_last_error_msg());
                }

                if (!isset($parsedData['entries']) || !is_array($parsedData['entries'])) {
                    file_put_contents('/tmp/ocr_debug.log', "Invalid entries structure\n", FILE_APPEND);
                    throw new \Exception('Invalid entries structure in GPT-4 response');
                }

                file_put_contents('/tmp/ocr_debug.log', "GPT Vision successful - found " . count($parsedData['entries']) . " entries\n", FILE_APPEND);

                Log::info("GPT-4 Vision successful", [
                    'entries_count' => count($parsedData['entries']),
                    'entries' => $parsedData['entries']
                ]);

                return [
                    'success' => true,
                    'entries' => $parsedData['entries'],
                    'method' => 'gpt4_vision'
                ];
            }

        } catch (\Exception $e) {
            file_put_contents('/tmp/ocr_debug.log', "GPT Vision exception: " . $e->getMessage() . "\n", FILE_APPEND);
            Log::error('GPT-4 Vision failed', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fallback: Process with Tesseract OCR and regex parsing
     */
    private function processWithTesseract($imagePath)
    {
        try {
            Log::info("Starting Tesseract fallback processing", ['path' => $imagePath]);

            // Extract text using Tesseract OCR
            $extractedText = $this->extractTextFromImage($imagePath);
            Log::info("Tesseract extracted text", ['text' => $extractedText]);

            // Parse Amazon Flex format with regex
            $parsedEntries = $this->parseAmazonFlexEarnings($extractedText);

            return [
                'success' => count($parsedEntries) > 0,
                'entries' => $parsedEntries,
                'method' => 'tesseract_regex',
                'error' => count($parsedEntries) == 0 ? 'No entries found with regex parsing' : null
            ];

        } catch (\Exception $e) {
            Log::error("Tesseract processing failed", ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'method' => 'tesseract_regex'
            ];
        }
    }

    /**
     * Extract text from image using Tesseract OCR
     */
    private function extractTextFromImage($imagePath)
    {
        try {
            Log::info("Starting OCR extraction", ['path' => $imagePath]);

            if (!file_exists($imagePath)) {
                throw new \Exception('Image file not found: ' . $imagePath);
            }

            $command = "tesseract '" . escapeshellarg($imagePath) . "' stdout 2>&1";
            $output = shell_exec($command);

            Log::info("Tesseract output", ['output' => $output]);

            if ($output === null || trim($output) === '') {
                throw new \Exception('Tesseract OCR failed to process image');
            }

            return $this->cleanOCRText($output);
        } catch (\Exception $e) {
            Log::error("extractTextFromImage failed", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Clean OCR text from artifacts
     */
    private function cleanOCRText($text)
    {
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = str_replace('\n', "\n", $text);

        return trim($text);
    }

    /**
     * Parse Amazon Flex earnings format (Tesseract fallback)
     */
    private function parseAmazonFlexEarnings($ocrText)
    {
        try {
            $entries = [];
            $lines = array_filter(array_map('trim', explode("\n", $this->cleanOCRText($ocrText))));

            for ($i = 0; $i < count($lines); $i++) {
                $line = $lines[$i];

                if ($this->isDateLine($line)) {
                    $entry = $this->parseEntry($lines, $i);
                    if ($entry) {
                        $entries[] = $entry;
                    }
                }
            }

            return $entries;
        } catch (\Exception $e) {
            Log::error("parseAmazonFlexEarnings failed", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Parse single entry from Tesseract lines
     */
    private function parseEntry($lines, $startIndex)
    {
        try {
            if ($startIndex >= count($lines)) return null;

            $dateLine = $lines[$startIndex];
            $timeLine = null;
            $totalEarnings = null;
            $basePay = null;
            $tips = null;

            // Look for related data in next few lines
            for ($i = $startIndex + 1; $i < count($lines) && $i < $startIndex + 6; $i++) {
                if ($i >= count($lines)) break;

                $line = $lines[$i];

                if ($this->isTimeLine($line)) {
                    $timeLine = $line;
                }

                if ($this->isEarningsLine($line)) {
                    $totalEarnings = $this->parseEarnings($line);
                }

                if (strpos($line, 'Base:') !== false && strpos($line, 'Tips:') !== false) {
                    if (preg_match('/Base:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $basePay = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                    if (preg_match('/Tips:\s*\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
                        $tips = isset($matches[1]) ? floatval($matches[1]) : null;
                    }
                }

                if ($this->isDateLine($line) && $i > $startIndex) {
                    break;
                }
            }

            if (!$timeLine || $totalEarnings === null) {
                return null;
            }

            $parsedDate = $this->parseDate($dateLine);
            if (!$parsedDate) return null;

            $times = $this->parseTimeRange($timeLine);
            if (!$times) return null;

            $serviceType = ($basePay !== null && $tips !== null) ? 'whole_foods' : 'logistics';

            return [
                'date' => $parsedDate->format('Y-m-d'),
                'start_time' => $times['start'],
                'end_time' => $times['end'],
                'total_earnings' => $totalEarnings,
                'base_pay' => $basePay,
                'tips' => $tips,
                'service_type' => $serviceType,
            ];
        } catch (\Exception $e) {
            Log::error("parseEntry failed", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse time range from line
     */
    private function parseTimeRange($timeLine)
    {
        try {
            // Match patterns like "9:21 AM - 10:30 AM"
            $patterns = [
                '/(\d{1,2}:\d{2}\s+(AM|PM))\s*[-–—]\s*(\d{1,2}:\d{2}\s+(AM|PM))/i',
                '/(\d{1,2}:\d{2}\s+(AM|PM))\s+to\s+(\d{1,2}:\d{2}\s+(AM|PM))/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $timeLine, $matches)) {
                    return [
                        'start' => trim($matches[1]),
                        'end' => trim($matches[3])
                    ];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("parseTimeRange failed", ['line' => $timeLine, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Validate entry data
     */
    private function isValidEntry($entry)
    {
        Log::info("Validating entry", ['entry' => $entry]);

        $hasRequiredFields = isset($entry['date'])
            && isset($entry['start_time'])
            && isset($entry['end_time'])
            && isset($entry['total_earnings']);

        $hasValidEarnings = isset($entry['total_earnings']) && $entry['total_earnings'] > 0;

        $isValid = $hasRequiredFields && $hasValidEarnings;

        if (!$isValid) {
            Log::warning("Entry validation failed", [
                'entry' => $entry,
                'has_date' => isset($entry['date']),
                'has_start_time' => isset($entry['start_time']),
                'has_end_time' => isset($entry['end_time']),
                'has_earnings' => isset($entry['total_earnings']),
                'earnings_value' => $entry['total_earnings'] ?? 'null',
                'earnings_positive' => isset($entry['total_earnings']) ? ($entry['total_earnings'] > 0) : false
            ]);
        } else {
            Log::info("Entry validation passed", ['entry' => $entry]);
        }

        return $isValid;
    }

    /**
     * Calculate hours from start and end times
     */
    private function calculateHoursFromTimes($startTime, $endTime)
    {
        try {
            Log::info("Calculating hours", ['start' => $startTime, 'end' => $endTime]);

            // Handle different time formats that GPT-4 might return
            $timeFormats = [
                'g:i A',    // 9:21 AM
                'H:i',      // 09:21
                'g:iA',     // 9:21AM
            ];

            $start = null;
            $end = null;

            foreach ($timeFormats as $format) {
                try {
                    $start = Carbon::createFromFormat($format, $startTime);
                    $end = Carbon::createFromFormat($format, $endTime);
                    break;
                } catch (\Exception $e) {
                    continue;
                }
            }

            if (!$start || !$end) {
                Log::warning("Could not parse times, using fallback", ['start' => $startTime, 'end' => $endTime]);
                return 2.0;
            }

            if ($end->lt($start)) {
                $end->addDay(); // Handle overnight shifts
            }

            $hours = round($start->diffInMinutes($end) / 60, 2);
            Log::info("Hours calculated", ['hours' => $hours]);

            return $hours;
        } catch (\Exception $e) {
            Log::error("calculateHoursFromTimes failed", ['start' => $startTime, 'end' => $endTime, 'error' => $e->getMessage()]);
            return 2.0; // Fallback
        }
    }

    /**
     * Determine service type from entry data
     */
    private function determineServiceType($entry)
    {
        if (isset($entry['base_pay']) && isset($entry['tips']) &&
            $entry['base_pay'] !== null && $entry['tips'] !== null) {
            return 'whole_foods';
        }
        return 'logistics';
    }

    /**
     * Check if entry already exists for user (DÜZELTILDI)
     */
    private function checkDuplicateEntry($userId, $entryData)
    {
        try {
            // Aynı kullanıcı, aynı tarih, aynı start time kontrolü
            $existingEntry = WorkEntry::where('user_id', $userId)
                ->where('date', $entryData['date'])
                ->where('notes', 'LIKE', '%Start: ' . $entryData['start_time'] . '%')
                ->first();

            if ($existingEntry) {
                Log::info("Duplicate found", [
                    'existing_date' => $existingEntry->date,
                    'existing_notes' => $existingEntry->notes,
                    'new_start_time' => $entryData['start_time']
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error("Duplicate check failed", ['error' => $e->getMessage()]);
            return false;
        }
    }
    /**
     * Parse time string to minutes since midnight
     */
    private function parseTimeToMinutes($timeString)
    {
        try {
            $timeFormats = ['g:i A', 'H:i', 'g:iA'];

            foreach ($timeFormats as $format) {
                try {
                    $time = Carbon::createFromFormat($format, $timeString);
                    return ($time->hour * 60) + $time->minute;
                } catch (\Exception $e) {
                    continue;
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Time parsing failed", ['time' => $timeString, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Build summary message for response
     */
    private function buildSummaryMessage($savedEntries, $duplicateEntries, $skippedEntries)
    {
        $messages = [];

        if (count($savedEntries) > 0) {
            $messages[] = count($savedEntries) . ' new entries saved';
        }

        if (count($duplicateEntries) > 0) {
            $messages[] = count($duplicateEntries) . ' duplicates skipped';
        }

        if (count($skippedEntries) > 0) {
            $messages[] = count($skippedEntries) . ' invalid entries skipped';
        }

        if (empty($messages)) {
            return 'No entries processed';
        }

        return implode(', ', $messages);
    }

    /**
     * Validate date format
     */
    private function isValidDate($date)
    {
        try {
            Carbon::createFromFormat('Y-m-d', $date);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Validate time format
     */
    private function isValidTime($time)
    {
        try {
            Carbon::createFromFormat('g:i A', $time);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // Legacy methods for Tesseract fallback
    private function isDateLine($line)
    {
        $patterns = [
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}$/i',
            '/(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+\d{1,2}/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isTimeLine($line)
    {
        $patterns = [
            '/^\d{1,2}:\d{2}\s+(AM|PM)\s*-\s*\d{1,2}:\d{2}\s+(AM|PM)$/i',
            '/\d{1,2}:\d{2}\s+(AM|PM)\s*[-–—]\s*\d{1,2}:\d{2}\s+(AM|PM)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isEarningsLine($line)
    {
        return preg_match('/^\$\d+(?:\.\d{2})?$/', $line) === 1;
    }

    private function parseEarnings($line)
    {
        if (preg_match('/\$([0-9]+(?:\.[0-9]{2})?)/', $line, $matches)) {
            return isset($matches[1]) ? floatval($matches[1]) : null;
        }
        return null;
    }

    private function parseDate($dateLine)
    {
        $patterns = [
            '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})$/i',
            '/(Mon|Tue|Wed|Thu|Fri|Sat|Sun),?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(\d{1,2})/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $dateLine, $matches)) {
                $monthName = ucfirst(strtolower($matches[2]));
                $day = intval($matches[3]);

                $months = [
                    'Jan' => 1, 'Feb' => 2, 'Mar' => 3, 'Apr' => 4,
                    'May' => 5, 'Jun' => 6, 'Jul' => 7, 'Aug' => 8,
                    'Sep' => 9, 'Oct' => 10, 'Nov' => 11, 'Dec' => 12,
                ];

                if (isset($months[$monthName])) {
                    $month = $months[$monthName];
                    $year = date('Y');
                    return Carbon::createFromDate($year, $month, $day);
                }
            }
        }
        return null;
    }

    /**
     * Get MIME type of image
     */
    private function getMimeType($imagePath)
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $imagePath);
        finfo_close($finfo);
        return $mimeType ?: 'image/jpeg';
    }
}
