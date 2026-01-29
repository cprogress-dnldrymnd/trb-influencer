<?php
function generate_influencer_summary( $post_id ) {
    // 1. Retrieve your specific meta fields
    $name = get_post_meta( $post_id, 'influencer_name', true );
    $niche = get_post_meta( $post_id, 'influencer_niche', true );
    $bio = get_post_meta( $post_id, 'description', true );
    
    // 2. Construct the prompt
    $prompt = "You are an expert talent scout. Write a concise, professional summary (max 40 words) for an influencer named $name. 
    Here is their raw data: 
    Niche: $niche
    Bio: $bio
    Focus on their professional value.";

    // 3. Call the API (Example using OpenAI)
    $api_key = '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';
    $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-4o-mini', // Cost-effective model for summaries
            'messages' => [
                ['role' => 'system', 'content' => 'You summarize influencer profiles.'],
                ['role' => 'user', 'content' => $prompt],
            ]
        ]),
        'timeout' => 20,
    ]);

    // 4. Handle the response and save
    if ( ! is_wp_error( $response ) ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $summary = $body['choices'][0]['message']['content'] ?? '';
        
        if ( $summary ) {
            update_post_meta( $post_id, 'ai_summary_text', $summary );
        }
    }
}