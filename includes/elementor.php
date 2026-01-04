<?php
/**
 * Update the query by specific post meta.
 *
 * @since 1.0.0
 * @param \WP_Query $query The WordPress query instance.
 */
function recently_view_influencers($query)
{

    // Get current meta Query
    $meta_query = $query->get('meta_query');

    // If there is no meta query when this filter runs, it should be initialized as an empty array.
    if (! $meta_query) {
        $meta_query = [];
    }

    // Append our meta query
    $meta_query[] = [
        'key' => 'project_type',
        'value' => ['design', 'development'],
        'compare' => 'in',
    ];

    $query->set('meta_query', $meta_query);
}
add_action('elementor/query/recently_view_influencers', 'recently_view_influencers');
