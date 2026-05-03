<?php
/**
 * LM Studio local AI translation provider
 */
class Loco_api_LmStudio extends Loco_api_Client {

    /**
     * @param string[][] $items input messages with keys, "source", "context" and "notes"
     * @return string[] Translated strings
     * @throws Loco_error_Exception
     */
    public static function process( array $items, Loco_Locale $locale, array $config ):array {
        $targets = [];
        
        $endpoint = $config['endpoint'] ?: 'http://localhost:1234/v1/chat/completions';
        $model = $config['model'] ?: 'local-model';
        $temperature = $config['temperature'] ?? 0.3;

        $sourceTag = 'en_US';
        $sourceLang = 'English';
        $targetTag = (string) $locale;
        $targetLang = self::wordy_language($locale);

        $tag = Loco_mvc_PostParams::get()['source'];
        if( is_string($tag) && '' !== $tag ){
            $source = Loco_Locale::parse($tag);
            if( $source->isValid() ){
                $sourceTag = $tag;
                $sourceLang = self::wordy_language($source);
            }
        }

        Loco_data_CompiledData::flush();
        
        $instructions = ['Respond only in '.$targetLang, 'Return ONLY valid JSON array', 'Translate ALL items in the input'];
        $tone = $locale->getFormality();
        if( '' !== $tone ){
            $instructions[] = 'Use only the '.$tone.' tone of '.$targetLang;
        }
        $prompt = "You are a translator from ".$sourceLang.' to '.$targetLang.". ".implode(". ",$instructions).'.';
        
        $custom = apply_filters( 'loco_gpt_prompt', $config['prompt']??'', $locale );
        if( is_string($custom) ){
            $custom = trim($custom);
            if( '' !== $custom ){
                $prompt .= ' '.$custom;
            }
        }

        // Configurable timeout - default 120 seconds for slow models
        $timeout = (int) ($config['timeout'] ?? 120);
        add_filter('http_request_timeout', function( $default ) use ( $timeout ){
            return max( $default, $timeout );
        });
        
        // Increase PHP execution time for large translation jobs
        $maxTime = (int) ini_get('max_execution_time');
        if( $maxTime > 0 && $maxTime < 600 ){
            @set_time_limit(600);
        }
        
        $offset = 0;
        $totalItems = count($items);
        $batchSize = 5;
        
        // For very large jobs, increase batch size to reduce total time
        if( $totalItems > 100 ){
            $batchSize = 10;
        }
        
        // Track consecutive failures to abort early
        $consecutiveFailures = 0;
        $maxConsecutiveFailures = 3;
        
        while( $offset < $totalItems ){
            $bytes = 0;
            $batch = [];
            $batchStartOffset = $offset;
            // Adaptive batch size
            while( $bytes < 1500 && count($batch) < $batchSize && $offset < $totalItems ){
                $item = $items[$offset];
                $meta = array_filter( [$item['context'], $item['notes']] );
                $source = [
                    'id' => $offset,
                    'text' => $item['source'],
                ];
                if( $meta ){
                    $source['context'] = implode("\n",$meta);
                }
                $bytes += strlen( $source['text'].($source['context']??'') );
                $batch[] = $source;
                $offset++;
            }
            
            Loco_error_Debug::trace('Processing batch: offset %d-%d (%d items)', $batchStartOffset, $offset-1, count($batch));
            
            $userPrompt = sprintf(
                'Translate ALL %d items to %s. Return JSON array with %d objects: [{"id":N,"text":"translation"}]. Must have exactly %d items. ONLY JSON array, no explanations.',
                count($batch),
                $targetLang,
                count($batch),
                count($batch)
            );
            
            $result = wp_remote_request( $endpoint, self::init_request_arguments( $config, [
                'model' => $model,
                'temperature' => $temperature,
                'max_tokens' => 2000,
                'messages' => [
                    [ 'role' => 'system', 'content' => $prompt ],
                    [ 'role' => 'user', 'content' => $userPrompt."\n\n".json_encode($batch,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ],
                ],
            ]) );
            
            try {
                $data = self::decode_response($result);
                $batchProcessed = false;
                
                foreach( $data['choices'] as $choice ){
                    $blob = $choice['message'] ?? ['role'=>'null'];
                    if( 'assistant' !== $blob['role'] ){
                        Loco_error_Debug::trace('Ignoring %s role message', $blob['role'] );
                        continue;
                    }
                    
                    // Check finish_reason for issues
                    $finishReason = $choice['finish_reason'] ?? 'unknown';
                    if( 'length' === $finishReason ){
                        Loco_error_Debug::trace('Response truncated due to length limit');
                    }
                    
                    // Handle reasoning models that put content in reasoning_content
                    $content = trim($blob['content'] ?? '');
                    if( '' === $content && isset($blob['reasoning_content']) ){
                        $content = trim($blob['reasoning_content']);
                    }
                    
                    if( '' === $content ){
                        Loco_error_Debug::trace('Empty response content for batch starting at offset %d', $offset - count($batch));
                        continue;
                    }
                    
                    // Remove markdown code blocks if present
                    $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content);
                    $content = trim($content);
                    
                    // Try to extract JSON array from text
                    if( preg_match('/\[\s*\{.*?\}\s*\]/s', $content, $matches) ){
                        $content = $matches[0];
                    }
                    
                    $parsed = json_decode( $content, true );
                    if( ! is_array($parsed) ){
                        Loco_error_Debug::trace('Failed to decode JSON at offset %d: %s', $offset - count($batch), substr($content,0,500));
                        continue;
                    }
                    
                    // Handle both [{id,text}] and {result:[{id,text}]} formats
                    if( array_key_exists('result',$parsed) && is_array($parsed['result']) ){
                        $parsed = $parsed['result'];
                    }
                    
                    $processedCount = 0;
                    foreach( $parsed as $output ){
                        if( ! is_array($output) || ! isset($output['id'],$output['text']) ){
                            continue;
                        }
                        $gptId = (int) $output['id'];
                        $translation = trim($output['text']);
                        
                        // ID from model should match our batch IDs directly
                        if( isset($targets[$gptId]) === false && $gptId >= $batchStartOffset && $gptId < $offset ){
                            $targets[$gptId] = $translation;
                            $processedCount++;
                        }
                    }
                    
                    if( $processedCount > 0 ){
                        $batchProcessed = true;
                        Loco_error_Debug::trace('Processed %d/%d translations in batch', $processedCount, count($batch));
                        
                        // If we got fewer translations than expected, warn but continue
                        if( $processedCount < count($batch) ){
                            Loco_error_Debug::trace('Incomplete batch: expected %d, got %d', count($batch), $processedCount);
                        }
                    }
                }
                
                if( ! $batchProcessed ){
                    Loco_error_Debug::trace('No translations extracted from batch at offset %d', $offset - count($batch));
                    $consecutiveFailures++;
                    if( $consecutiveFailures >= $maxConsecutiveFailures ){
                        $name = $config['name'] ?? 'LM Studio';
                        throw new Loco_error_Exception( sprintf('%s: %d consecutive batch failures. Check if LM Studio is running and model is loaded.', $name, $consecutiveFailures) );
                    }
                }
                else {
                    $consecutiveFailures = 0;
                }
            }
            catch ( Throwable $e ){
                $name = $config['name'] ?? 'LM Studio';
                Loco_error_Debug::trace('%s error at offset %d: %s', $name, $offset - count($batch), $e->getMessage());
                $consecutiveFailures++;
                if( $consecutiveFailures >= $maxConsecutiveFailures ){
                    throw new Loco_error_Exception( sprintf('%s: %d consecutive failures. Last error: %s', $name, $consecutiveFailures, $e->getMessage()) );
                }
                // Continue to next batch
            }
        }
        
        // Retry missing translations one by one (only for small jobs to avoid timeout)
        $missing = [];
        for( $i = 0; $i < $totalItems; $i++ ){
            if( ! isset($targets[$i]) ){
                $missing[] = $i;
            }
        }
        
        if( count($missing) > 0 && count($missing) < 20 && count($missing) < $totalItems * 0.3 ){
            Loco_error_Debug::trace('Retrying %d missing translations individually', count($missing));
            
            foreach( $missing as $idx ){
                $item = $items[$idx];
                $meta = array_filter( [$item['context'], $item['notes']] );
                $source = [
                    'id' => $idx,
                    'text' => $item['source'],
                ];
                if( $meta ){
                    $source['context'] = implode("\n",$meta);
                }
                
                try {
                    $result = wp_remote_request( $endpoint, self::init_request_arguments( $config, [
                        'model' => $model,
                        'temperature' => $temperature,
                        'max_tokens' => 500,
                        'messages' => [
                            [ 'role' => 'system', 'content' => $prompt ],
                            [ 'role' => 'user', 'content' => 'Translate to '.$targetLang.': '.json_encode($source,JSON_UNESCAPED_UNICODE) ],
                        ],
                    ]) );
                    
                    $data = self::decode_response($result);
                    foreach( $data['choices'] as $choice ){
                        $blob = $choice['message'] ?? [];
                        if( 'assistant' === ($blob['role']??'') ){
                            $content = trim($blob['content'] ?? $blob['reasoning_content'] ?? '');
                            if( '' !== $content ){
                                // Try to extract just the translation text
                                $content = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $content);
                                $parsed = json_decode( trim($content), true );
                                if( is_array($parsed) && isset($parsed[0]['text']) ){
                                    $targets[$idx] = trim($parsed[0]['text']);
                                }
                                else if( is_array($parsed) && isset($parsed['text']) ){
                                    $targets[$idx] = trim($parsed['text']);
                                }
                                else {
                                    // Fallback: use raw content as translation
                                    $targets[$idx] = $content;
                                }
                                break;
                            }
                        }
                    }
                }
                catch( Throwable $e ){
                    Loco_error_Debug::trace('Failed to retry item %d: %s', $idx, $e->getMessage());
                }
            }
        }
        
        Loco_error_Debug::trace('LM Studio completed: %d translations for %d source strings', count($targets), $totalItems);
        return $targets;
    }


    private static function wordy_language( Loco_Locale $locale ):string {
        $names = Loco_data_CompiledData::get('languages');
        return $names[ $locale->lang ] ?? $locale->lang;
    }


    private static function init_request_arguments( array $config, array $data ):array {
        $headers = [
            'Content-Type' => 'application/json',
        ];
        if( ! empty($config['key']) ){
            $headers['Authorization'] = 'Bearer '.$config['key'];
        }
        return [
            'method' => 'POST',
            'redirection' => 0,
            'user-agent' => parent::getUserAgent(),
            'reject_unsafe_urls' => false,
            'headers' => $headers,
            'body' => json_encode($data),
        ];
    }


    private static function decode_response( $result ):array {
        $data = parent::decodeResponse($result);
        $status = $result['response']['code'];
        if( 200 !== $status ){
            $message = $data['error']['message'] ?? 'Unknown error';
            throw new Exception( sprintf('API returned status %u: %s',$status,$message) );
        }
        if( ! array_key_exists('choices',$data) || ! is_array($data['choices']) ){
            throw new Exception('API returned unexpected data');
        }
        return $data;
    }

}
